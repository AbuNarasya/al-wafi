<?php

namespace Tests\Feature;

use App\Http\Middleware\TandaTanganToko;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\DompetSantri;
use App\Models\JournalLine;
use App\Models\MutasiDompet;
use App\Models\PengajuanTarikTunai;
use App\Models\Santri;
use App\Models\SesiTarikTunai;
use App\Models\TransaksiDompetToko;
use App\Models\Wali;
use App\Services\Modules\JurnalDompetToko;
use App\Services\Modules\KartuSantriService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PENGHUBUNG DOMPET SANTRI ↔ WAFI MART — Fase 3a (sisi ERP Al Wafi). Kasir Wafi Mart (instans lain)
 * memotong uang saku lewat API bertanda tangan; aturan dompet diperiksa di sini; jurnal ringkas tiap malam.
 */
class PenghubungDompetTokoTest extends TestCase
{
    use RefreshDatabase;

    private const RAHASIA = 'rahasia-uji-penghubung';

    private const UID = 'KARTU-AHMAD';

    private const HUTANG = '2.1.01.913';

    private Santri $ahmad;

    protected function setUp(): void
    {
        parent::setUp();
        config(['penghubung.rahasia' => self::RAHASIA, 'penghubung.ip' => '']);
        CoaGroup::create(['kode_grup' => 'ZZPD', 'nama_grup' => 'Penghubung']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['santri'], 'nama_coa' => 'Titipan Uang Saku', 'kode_grup' => 'ZZPD', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang ke Wafi Mart', 'kode_grup' => 'ZZPD', 'jenis_saldo' => 'kredit']);
        CompanySettings::updateOrCreate(['id' => 1], ['nama_perusahaan' => 'Yayasan', 'jenis_perusahaan' => 'Yayasan', 'mata_uang' => 'IDR',
            'periode_awal_pembukuan' => '2026-01-01', 'bulan_awal_anggaran' => 1, 'kode_coa_hutang_toko' => self::HUTANG]);

        \App\Models\Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        \App\Models\Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '0811', 'nama' => 'Ibu', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZPD-1', 'nis' => '1001', 'nama' => 'AHMAD', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id]);
        DompetSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => 100000, 'batas_harian' => 30000]);
        $k = new KartuSantriService;
        $k->pasang($this->ahmad->id, self::UID, null);
        $k->pasangPin($this->ahmad, '730519', '730519', $wali->id, 'ibu');
    }

    /** Kirim permintaan bertanda tangan seperti kasir Wafi Mart. */
    private function kirim(string $method, string $path, array $isi = [], ?string $rahasia = null, ?int $waktu = null): TestResponse
    {
        $waktu = (string) ($waktu ?? time());
        $badan = $method === 'GET' ? '' : json_encode($isi);
        $url = $method === 'GET' && $isi ? $path.'?'.http_build_query($isi) : $path;
        $tanda = TandaTanganToko::tanda($rahasia ?? self::RAHASIA, $method, $path, $waktu, $badan);

        return $this->call($method, $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PENGHUBUNG_WAKTU' => $waktu, 'HTTP_X_PENGHUBUNG_TANDA' => $tanda,
        ], $badan);
    }

    private function potong(string $nomor, int $nominal, ?string $pin = null): TestResponse
    {
        return $this->kirim('POST', '/api/dompet-toko/v1/potong', ['nomor' => $nomor, 'uid' => self::UID, 'nominal' => $nominal,
            'pin' => $pin, 'kode_toko' => 'L002', 'nama_toko' => 'Koperasi Banin']);
    }

    private function saldo(): string
    {
        return DompetSantri::where('id_santri', $this->ahmad->id)->value('saldo');
    }

    public function test_cek_kartu_tanpa_membuka_saldo(): void
    {
        $r = $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => self::UID, 'total' => 20000])->assertOk();
        $this->assertSame(['AHMAD', '30000.00', false, true], [$r->json('nama'), $r->json('sisa_batas'), $r->json('perlu_pin'), $r->json('punya_pin')]);
        $this->assertArrayNotHasKey('saldo', $r->json());
        $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => 'TAK-ADA'])->assertNotFound();
    }

    public function test_potong_idempoten_dan_nomor_bentrok_ditolak(): void
    {
        $r = $this->potong('WM-JL-1', 20000)->assertOk();
        $this->assertSame(['80000.00', '10000.00'], [$r->json('sisa_saldo'), $r->json('sisa_batas')]);

        // Dikirim ulang (jaringan putus): hasil sama, TIDAK terpotong dua kali.
        $this->potong('WM-JL-1', 20000)->assertOk()->assertJson(['ulang' => true, 'nomor' => 'WM-JL-1']);
        $this->assertSame('80000.00', $this->saldo());
        $this->assertSame(1, TransaksiDompetToko::count());
        $m = MutasiDompet::find(TransaksiDompetToko::sole()->id_mutasi_dompet);
        $this->assertSame(['jajan', '-20000.00', null], [$m->jenis, $m->nominal, $m->journal_entry_id]);

        // Nomor yang sama untuk isi lain = ditolak.
        $this->potong('WM-JL-1', 5000)->assertStatus(409);
    }

    public function test_aturan_dompet_tetap_berlaku(): void
    {
        $this->potong('WM-1', 20000)->assertOk();
        // 20.000 + 15.000 > batas 30.000 → wajib PIN belanja anak.
        $this->potong('WM-2', 15000)->assertStatus(409);
        $this->assertSame(0, \App\Models\PinSantri::find($this->ahmad->id)->gagal);   // PIN belum dikirim ≠ salah
        $this->potong('WM-2', 15000, '000000')->assertStatus(422);
        $this->potong('WM-2', 15000, '730519')->assertOk();
        $this->assertSame('65000.00', $this->saldo());

        $this->potong('WM-3', 70000, '730519')->assertStatus(422);     // saldo kurang

        // Cadangan tarik tunai kegiatan ikut menahan.
        BankAccount::create(['kode_coa' => DompetPolicy::COA_TITIPAN['santri'], 'nama_rekening' => 'x', 'jenis_rekening' => 'bank', 'status' => 'aktif']);
        $sesi = SesiTarikTunai::create(['nomor' => 'STT-1', 'nama' => 'Rihlah', 'batas_pengajuan' => now()->addDay(), 'batas_persetujuan' => now()->addDays(2),
            'tanggal_ambil' => now()->addDays(3), 'tanggal_distribusi' => now()->addDays(4), 'maks_per_santri' => 50000,
            'kode_coa_bank' => DompetPolicy::COA_TITIPAN['santri'], 'kode_coa_kas' => DompetPolicy::COA_TITIPAN['santri'], 'dibuka' => true]);
        PengajuanTarikTunai::create(['nomor' => 'TTK-1', 'id_sesi' => $sesi->id_sesi, 'id_santri' => $this->ahmad->id,
            'id_wali' => $this->ahmad->id_wali, 'peran' => 'ibu', 'nominal' => 50000]);
        $this->potong('WM-4', 20000, '730519')->assertStatus(422)->assertJson(['message' => true]);
        $this->assertStringContainsString('dicadangkan', $this->potong('WM-4', 20000, '730519')->json('message'));

        // Kartu diblokir.
        (new KartuSantriService)->blokir($this->ahmad->kartuAktif()->first(), 'hilang', null);
        $this->potong('WM-5', 1000)->assertNotFound();
    }

    public function test_kembalikan_sebagian_dan_tak_melebihi_asal(): void
    {
        $this->potong('WM-1', 20000)->assertOk();
        $k = fn ($nomor, $n) => $this->kirim('POST', '/api/dompet-toko/v1/kembalikan', ['nomor' => $nomor, 'nomor_asal' => 'WM-1', 'nominal' => $n]);

        $k('WM-R1', 5000)->assertOk()->assertJson(['sisa_saldo' => '85000.00']);
        $k('WM-R1', 5000)->assertOk()->assertJson(['ulang' => true]);   // berulang
        $k('WM-R2', 16000)->assertStatus(422);                            // sisa 15.000
        $k('WM-R2', 15000)->assertOk();
        $this->assertSame('100000.00', $this->saldo());
        $this->kirim('POST', '/api/dompet-toko/v1/kembalikan', ['nomor' => 'WM-R3', 'nomor_asal' => 'TAK-ADA', 'nominal' => 1])->assertNotFound();
    }

    public function test_pengamanan_tanda_tangan_waktu_dan_instans(): void
    {
        $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => self::UID], 'kunci-salah')->assertStatus(401);
        $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => self::UID], null, time() - 600)->assertStatus(401);
        $this->postJson('/api/dompet-toko/v1/potong', ['nomor' => 'X', 'uid' => self::UID, 'nominal' => 1000])->assertStatus(401);

        config(['penghubung.ip' => '10.9.9.9']);
        $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => self::UID])->assertStatus(403);
        config(['penghubung.ip' => '']);

        config(['penghubung.rahasia' => '']);
        $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => self::UID], self::RAHASIA)->assertNotFound();
        config(['penghubung.rahasia' => self::RAHASIA, 'instans.kode' => 'wafimart']);
        $this->kirim('POST', '/api/dompet-toko/v1/kartu', ['uid' => self::UID])->assertNotFound();
        $this->assertSame('100000.00', $this->saldo());
    }

    public function test_jurnal_malam_ringkas_per_tanggal_dan_aman_diulang(): void
    {
        $this->travelTo(now()->subDays(2)->setTime(10, 0));
        $this->potong('WM-1', 20000)->assertOk();
        $this->potong('WM-2', 10000, '730519')->assertOk();
        $this->kirim('POST', '/api/dompet-toko/v1/kembalikan', ['nomor' => 'WM-R1', 'nomor_asal' => 'WM-1', 'nominal' => 4000])->assertOk();
        $this->travelBack();
        $this->potong('WM-3', 5000)->assertOk();   // hari ini — belum dijurnal

        $hasil = (new JurnalDompetToko)->jalankan();
        $this->assertCount(1, $hasil);
        $this->assertSame(['26000.00', 3], [$hasil[0]['bersih'], $hasil[0]['transaksi']]);
        $saldo = fn ($coa) => Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
        $this->assertSame(['26000.00', '-26000.00'], [$saldo(DompetPolicy::COA_TITIPAN['santri']), $saldo(self::HUTANG)]);
        $this->assertSame(3, MutasiDompet::whereNotNull('journal_entry_id')->count());

        $this->assertSame([], (new JurnalDompetToko)->jalankan());          // diulang: tak ada yang baru
        $this->assertNull(TransaksiDompetToko::where('nomor_toko', 'WM-3')->value('dijurnal_pada'));
        $this->artisan('dompet:jurnal-belanja-toko')->assertSuccessful();
    }

    public function test_daftar_transaksi_untuk_rekonsiliasi(): void
    {
        $this->potong('WM-1', 20000)->assertOk();
        $r = $this->kirim('GET', '/api/dompet-toko/v1/transaksi', ['tanggal' => now()->toDateString()])->assertOk();
        $this->assertSame([['nomor' => 'WM-1', 'jenis' => 'potong', 'nomor_asal' => null, 'nominal' => '20000.00', 'status' => 'berhasil', 'kode_toko' => 'L002']],
            $r->json('transaksi'));
    }
}
