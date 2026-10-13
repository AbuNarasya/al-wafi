<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\Notification;
use App\Models\NotifikasiWali;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\TransaksiMidtrans;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\PinTransaksi;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BAYAR BEBERAPA TAGIHAN SEKALIGUS + PINDAH UANG SAKU ANTAR ANAK (keputusan user 13 Okt 2026).
 *
 * Yang dijaga: semua-atau-tidak-sama-sekali; pembukuan tetap satu pembayaran + satu
 * jurnal per tagihan; transfer berkelompok diputus keuangan SEKALIGUS; satu VA untuk
 * beberapa tagihan memungut biaya sekali; pindah antar anak tanpa jurnal.
 */
class BayarBanyakTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZBB';

    private const BANK = '1.ZZBB.BANK';

    private const PIN = '482915';

    private const KUNCI = 'SB-Mid-server-UJI';

    private User $keuangan;

    private Wali $wali;

    private Santri $aktif;

    private Santri $calon;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        TipeBiaya::lupakan();
        config(['services.midtrans.server_key' => self::KUNCI, 'services.midtrans.client_key' => 'SB-Mid-client-UJI',
            'services.midtrans.biaya_va' => 4440]);

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->keuangan = User::create(['username' => 'zzbb_keu', 'nama' => 'Petugas Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZBBU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Bayar Banyak']);
        foreach ([['4.ZZBB.1', 'Pendapatan', 'kredit'], ['1.ZZBB.2', 'Piutang', 'debet'], [self::BANK, 'Bank', 'debet'],
            ['1.1.01.003', 'Rekening Midtrans', 'debet'], ['2.1.01.010', 'Titipan Biaya Admin PG', 'kredit'],
            [DompetPolicy::COA_TITIPAN['wali'], 'Titipan Wali', 'kredit'], [DompetPolicy::COA_TITIPAN['santri'], 'Titipan Santri', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'BSI Penerimaan', 'jenis_rekening' => 'bank',
            'nama_bank' => 'BSI', 'no_rekening' => '7123456789', 'status' => 'aktif', 'tampil_di_portal' => true]);
        BankAccount::create(['kode_coa' => '1.1.01.003', 'nama_rekening' => 'Rekening Midtrans', 'jenis_rekening' => 'bank',
            'nama_bank' => 'Midtrans', 'status' => 'aktif']);

        foreach (['spp' => 'SPP', 'lain' => 'Ekskul', 'uang_pangkal' => 'Uang Pangkal', 'perlengkapan' => 'Perlengkapan', 'registrasi' => 'Registrasi'] as $p => $nama) {
            TipeBiaya::firstOrCreate(['kode' => $p], ['nama' => $nama, 'perilaku' => $p, 'urutan' => 1, 'bawaan' => true, 'status' => 'aktif']);
            JenisBiaya::create(['kode' => strtoupper($p), 'nama' => $nama, 'tipe' => $p, 'kode_coa_pendapatan' => '4.ZZBB.1',
                'kode_coa_piutang' => '1.ZZBB.2', 'kode_unit' => 'ZZBBU', 'status' => 'aktif']);
        }

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811',
            'email_ibu' => 'aminah@contoh.id', 'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->aktif = $this->santri('Ahmad', 'aktif');
        $this->calon = $this->santri('Adik', 'calon');
        (new PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN);
    }

    private function santri(string $nama, string $status, ?Wali $wali = null): Santri
    {
        static $n = 0;

        return Santri::create(['no_pendaftaran' => 'ZZBB-'.(++$n), 'nama' => $nama, 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'tingkat' => 1, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
            'status' => $status, 'id_wali' => ($wali ?? $this->wali)->id]);
    }

    private function tagihan(Santri $s, string $perilaku, int $nominal, string $periode = 'P1'): TagihanSantri
    {
        return TagihanSantri::create(['id_santri' => $s->id, 'kode_jenis' => strtoupper($perilaku), 'perilaku' => $perilaku,
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA, 'periode' => $periode,
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar']);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu']);
    }

    /** @param  list<TagihanSantri>  $tagihan */
    private function isian(array $tagihan): array
    {
        return ['nominal' => collect($tagihan)->mapWithKeys(fn ($t) => [$t->id => (int) $t->sisa])->all()];
    }

    // ---- Dompet ----

    public function test_spp_tiga_bulan_sekaligus_dari_dompet_satu_pin(): void
    {
        DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => 1000000]);
        $spp = [$this->tagihan($this->aktif, 'spp', 300000, '2026-08'), $this->tagihan($this->aktif, 'spp', 300000, '2026-09'),
            $this->tagihan($this->aktif, 'spp', 300000, '2026-10')];

        $this->sebagaiIbu()->get(route('portal.bayar_banyak.form', ['t' => collect($spp)->pluck('id')->all()]))->assertOk()
            ->assertSee('Bayar 3 tagihan sekaligus')->assertSee('Rp 300.000');
        $this->sebagaiIbu()->post(route('portal.bayar_banyak.dompet'), $this->isian($spp) + ['pin' => self::PIN])
            ->assertRedirect(route('portal.tagihan'))->assertSessionHas('status');

        $this->assertSame(3, PembayaranSantri::count());
        $this->assertSame(1, PembayaranSantri::distinct()->count('kelompok_bayar'));
        $this->assertNotNull(PembayaranSantri::first()->kelompok_bayar);
        foreach ($spp as $t) {
            $this->assertSame('lunas', $t->refresh()->status);
        }
        $this->assertEquals(100000, (float) DompetWali::sole()->saldo);
        $this->assertSame(3, JournalEntry::count(), 'tetap satu jurnal per tagihan');
    }

    public function test_dompet_semua_atau_tidak_sama_sekali(): void
    {
        DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => 500000]);
        $spp = [$this->tagihan($this->aktif, 'spp', 300000, '2026-09'), $this->tagihan($this->aktif, 'spp', 300000, '2026-10')];

        $this->sebagaiIbu()->post(route('portal.bayar_banyak.dompet'), $this->isian($spp) + ['pin' => self::PIN])
            ->assertSessionHas('error');
        // SPP tak boleh dicicil dari dompet.
        $this->sebagaiIbu()->post(route('portal.bayar_banyak.dompet'), ['nominal' => [$spp[0]->id => 100000], 'pin' => self::PIN])
            ->assertSessionHas('error');

        $this->assertSame(0, PembayaranSantri::count());
        $this->assertEquals(500000, (float) DompetWali::sole()->saldo);
    }

    public function test_spp_dan_ekskul_sekaligus_dan_campuran_hanya_lewat_dompet(): void
    {
        DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => 2000000]);
        $spp = $this->tagihan($this->aktif, 'spp', 300000);
        $ekskul = $this->tagihan($this->aktif, 'lain', 150000);
        $reg = $this->tagihan($this->calon, 'registrasi', 500000);

        // Campuran santri aktif + calon: hanya Dompet yang ditawarkan.
        $this->sebagaiIbu()->get(route('portal.bayar_banyak.form', ['t' => [$spp->id, $reg->id]]))->assertOk()
            ->assertSee('Bayar 2 tagihan sekaligus')->assertDontSee('Transfer + bukti')->assertDontSee('Online (VA)');

        $this->sebagaiIbu()->post(route('portal.bayar_banyak.dompet'), $this->isian([$spp, $ekskul, $reg]) + ['pin' => self::PIN])
            ->assertSessionHas('status');
        $this->assertSame(['lunas', 'lunas', 'lunas'], [$spp->refresh()->status, $ekskul->refresh()->status, $reg->refresh()->status]);
        $this->assertSame('terbayar', $this->calon->refresh()->status, 'gerbang tahap PSB tetap berlaku di jalur sekaligus');
    }

    // ---- Transfer berkelompok ----

    private function kirimTransfer(array $tagihan): void
    {
        $this->sebagaiIbu()->post(route('portal.bayar_banyak.transfer'), $this->isian($tagihan) + [
            'kode_rekening' => self::BANK, 'tanggal' => now()->toDateString(),
            'bukti' => UploadedFile::fake()->image('bukti.jpg', 600, 800)->size(200),
        ])->assertRedirect(route('portal.tagihan'))->assertSessionHas('status');
    }

    public function test_transfer_satu_bukti_diverifikasi_keuangan_sekaligus(): void
    {
        $up = $this->tagihan($this->calon, 'uang_pangkal', 5000000);
        $plk = $this->tagihan($this->calon, 'perlengkapan', 1300000);

        $this->kirimTransfer([$up, $plk]);

        $baris = PembayaranSantri::orderBy('id')->get();
        $this->assertCount(2, $baris);
        $this->assertSame(['menunggu_verifikasi'], $baris->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $baris->pluck('kelompok_bayar')->unique()->count());
        $this->assertSame(1, $baris->pluck('bukti_path')->unique()->count(), 'satu bukti untuk semuanya');
        $this->assertSame(1, Notification::where('jenis', 'pembayaran_santri_menunggu')->count(), 'satu kabar ke keuangan');

        // Satu per satu ditolak — wajib sekaligus.
        $this->actingAs($this->keuangan)->post(route('pembayaran_ppsb.verifikasi', $baris[0]->id))->assertSessionHas('error');
        $this->actingAs($this->keuangan)->get(route('pembayaran_ppsb.index'))->assertOk()
            ->assertSee('Verifikasi kelompok')->assertSee('Kelompok 2 tagihan');

        $this->actingAs($this->keuangan)->post(route('pembayaran_ppsb.verifikasi_kelompok', $baris[0]->kelompok_bayar))
            ->assertSessionHas('status');

        $this->assertSame(['terverifikasi'], PembayaranSantri::pluck('status')->unique()->values()->all());
        $this->assertSame(['lunas', 'lunas'], [$up->refresh()->status, $plk->refresh()->status]);
        $this->assertSame(2, JournalEntry::count());

        $this->actingAs($this->keuangan)->get(route('pembayaran_ppsb.kuitansi_kelompok', $baris[0]->kelompok_bayar))->assertOk()
            ->assertSee('Kuitansi Pembayaran Gabungan')->assertSee('6.300.000')->assertSee('enam juta tiga ratus ribu');
    }

    public function test_tolak_kelompok_satu_kabar_ke_wali(): void
    {
        $up = $this->tagihan($this->calon, 'uang_pangkal', 5000000);
        $plk = $this->tagihan($this->calon, 'perlengkapan', 1300000);
        $this->kirimTransfer([$up, $plk]);
        $kelompok = PembayaranSantri::value('kelompok_bayar');

        $this->actingAs($this->keuangan)->post(route('pembayaran_ppsb.tolak_kelompok', $kelompok), ['alasan' => 'Dana belum masuk'])
            ->assertSessionHas('status');

        $this->assertSame(['ditolak'], PembayaranSantri::pluck('status')->unique()->values()->all());
        $this->assertSame(1, NotifikasiWali::where('jenis', 'pembayaran_santri_ditolak')->count());
        $this->assertSame(['belum_bayar', 'belum_bayar'], [$up->refresh()->status, $plk->refresh()->status]);
        $this->assertSame(0, JournalEntry::count());
    }

    // ---- Satu VA untuk beberapa tagihan ----

    public function test_satu_va_untuk_beberapa_tagihan_biaya_sekali(): void
    {
        $up = $this->tagihan($this->calon, 'uang_pangkal', 5000000);
        $plk = $this->tagihan($this->calon, 'perlengkapan', 1300000);
        $status = [];
        Http::fake(function (PermintaanHttp $r) use (&$status) {
            if (str_ends_with($r->url(), '/snap/v1/transactions')) {
                return Http::response(['token' => 'TOK', 'redirect_url' => 'https://app.sandbox.midtrans.com/x'], 201);
            }
            if (preg_match('#/v2/([^/]+)/status$#', $r->url(), $m)) {
                return Http::response($status[urldecode($m[1])] ?? ['status_code' => '404']);
            }

            return Http::response(['status_code' => '200']);
        });

        $this->sebagaiIbu()->post(route('portal.bayar_banyak.online'), $this->isian([$up, $plk]));
        $t = TransaksiMidtrans::sole();
        $this->assertSame(['bayar_kelompok', '6300000.00', '4440.00', '6304440.00', 2],
            [$t->jenis, (string) $t->nominal, (string) $t->biaya, (string) $t->total, $t->rincian()->count()]);
        Http::assertSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/snap/v1/transactions')
            && collect($r['item_details'])->sum('price') === 6304440 && count($r['item_details']) === 3);

        $status[$t->order_id] = ['status_code' => '200', 'order_id' => $t->order_id, 'transaction_id' => 'TRX9',
            'transaction_status' => 'settlement', 'gross_amount' => '6304440.00',
            'va_numbers' => [['bank' => 'bni', 'va_number' => '9881']], 'settlement_time' => '2026-10-13 14:00:00'];
        $gross = '6304440.00';
        $this->postJson(route('midtrans.notifikasi'), ['order_id' => $t->order_id, 'status_code' => '200', 'gross_amount' => $gross,
            'transaction_status' => 'settlement', 'signature_key' => hash('sha512', $t->order_id.'200'.$gross.self::KUNCI)])->assertOk();

        $this->assertSame('lunas', $t->refresh()->status);
        $p = PembayaranSantri::orderBy('id')->get();
        $this->assertCount(2, $p);
        $this->assertSame([$t->order_id], $p->pluck('kelompok_bayar')->unique()->values()->all());
        $this->assertSame(['lunas', 'lunas'], [$up->refresh()->status, $plk->refresh()->status]);
        // 2 jurnal penerimaan + SATU jurnal potongan admin.
        $this->assertSame(3, JournalEntry::count());
        $this->assertSame(0, MutasiDompet::count());
    }

    // ---- Pindah uang saku antar anak ----

    public function test_pindah_uang_saku_antar_anak_tanpa_jurnal(): void
    {
        $kakak = $this->aktif;
        $adik = $this->santri('Adik Aktif', 'aktif');
        DompetSantri::create(['id_santri' => $kakak->id, 'saldo' => 300000]);

        $this->sebagaiIbu()->get(route('portal.dompet.pindah'))->assertOk()->assertSee('Uang saku antar anak');
        $this->sebagaiIbu()->post(route('portal.dompet.pindah'), ['arah' => 'antar_anak', 'id_santri' => $kakak->id,
            'id_santri_tujuan' => $adik->id, 'nominal' => 100000, 'pin' => self::PIN])->assertSessionHas('status');

        $this->assertEquals(200000, (float) DompetSantri::where('id_santri', $kakak->id)->value('saldo'));
        $this->assertEquals(100000, (float) DompetSantri::where('id_santri', $adik->id)->value('saldo'));
        $this->assertSame(0, JournalEntry::count(), 'satu akun buku besar — tak ada jurnal');
        [$keluar, $masuk] = MutasiDompet::orderBy('id')->get()->all();
        $this->assertSame([$masuk->id, $keluar->id], [$keluar->id_pasangan, $masuk->id_pasangan]);
        $this->assertSame($this->wali->id, $keluar->dicatat_oleh_wali);
    }

    public function test_pindah_antar_anak_ditolak_bila_saldo_kurang_atau_bukan_anak_sendiri(): void
    {
        $adik = $this->santri('Adik Aktif', 'aktif');
        $orangLain = $this->santri('Anak Lain', 'aktif', Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lain',
            'telepon_ayah' => '0822', 'nama' => 'Lain', 'telepon' => '0822', 'status' => 'aktif']));
        DompetSantri::create(['id_santri' => $this->aktif->id, 'saldo' => 50000]);

        $kirim = fn (int $tujuan, int $nominal) => $this->sebagaiIbu()->post(route('portal.dompet.pindah'), ['arah' => 'antar_anak',
            'id_santri' => $this->aktif->id, 'id_santri_tujuan' => $tujuan, 'nominal' => $nominal, 'pin' => self::PIN]);

        $kirim($adik->id, 100000)->assertSessionHas('error');
        $kirim($orangLain->id, 10000)->assertSessionHas('error');
        $kirim($this->aktif->id, 10000)->assertSessionHas('error');

        $this->assertEquals(50000, (float) DompetSantri::where('id_santri', $this->aktif->id)->value('saldo'));
        $this->assertSame(0, MutasiDompet::count());
    }
}
