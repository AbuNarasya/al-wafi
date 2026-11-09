<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\HakAksesModul;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\MenuMitra;
use App\Models\Mitra;
use App\Models\PenarikanMitra;
use App\Models\PenjualanMitra;
use App\Models\User;
use App\Services\Modules\RekonsiliasiDompetToko;
use App\Services\Modules\SaldoMitraService;
use App\Support\Akses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MITRA KANTIN WAFI MART — Paket 2 (mitra, menu, akun, kasir mitra) & Paket 3 (saldo, penarikan, jurnal),
 * instans Wafi Mart; penghubung ke ERP Al Wafi dipalsukan dengan Http::fake.
 */
class MitraKantinTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://erp.contoh.test';

    private const PIUTANG = '1.ZZMK.PIU';

    private const HUTANG = '2.ZZMK.HTM';

    private const BANK = '1.ZZMK.BNK';

    private User $staf;

    private Mitra $mitra;

    private User $akunMitra;

    private MenuMitra $nasi;

    private MenuMitra $esteh;

    private ?\Closure $jawab = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instans.kode' => 'wafimart', 'penghubung.url' => self::URL, 'penghubung.rahasia' => str_repeat('k', 40),
            'penghubung.jeda_pastikan_ms' => 0]);
        Akses::lupakan();
        CoaGroup::create(['kode_grup' => 'ZZMK', 'nama_grup' => 'Mitra']);
        foreach ([[self::PIUTANG, 'debet'], [self::HUTANG, 'kredit'], [self::BANK, 'debet']] as [$kd, $s]) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $kd, 'kode_grup' => 'ZZMK', 'jenis_saldo' => $s]);
        }
        CompanySettings::updateOrCreate(['id' => 1], ['id' => 1, 'nama_perusahaan' => 'Wafi Mart', 'periode_awal_pembukuan' => '2026-01-01',
            'kode_coa_kartu_santri' => self::PIUTANG, 'kode_coa_hutang_mitra' => self::HUTANG]);
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'BSI Wafi Mart', 'jenis_rekening' => 'bank', 'status' => 'aktif']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);

        $this->staf = User::create(['username' => 'kepala', 'nama' => 'Kepala Wafi Mart', 'password_hash' => 'x', 'kode_level' => 'L1',
            'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $this->staf->id_pengguna, 'kode_modul' => 'mitra-kantin', 'lihat' => true, 'buat' => true,
            'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $this->actingAs($this->staf)->post(route('mitra_kantin.store'), ['kode' => 'kb01', 'nama' => 'Kantin Basah Bu Ani', 'status' => 'aktif',
            'bank_nama' => 'BSI', 'bank_rekening' => '7123', 'bank_atas_nama' => 'Ani', 'keterangan_sewa' => 'Sewa tahunan'])->assertRedirect();
        $this->mitra = Mitra::sole();
        $this->actingAs($this->staf)->post(route('mitra_kantin.menu.store', $this->mitra), ['nama' => 'Nasi goreng', 'harga' => 12000])->assertRedirect();
        $this->actingAs($this->staf)->post(route('mitra_kantin.menu.store', $this->mitra), ['nama' => 'Es teh', 'harga' => 3000])->assertRedirect();
        [$this->nasi, $this->esteh] = [MenuMitra::where('nama', 'Nasi goreng')->sole(), MenuMitra::where('nama', 'Es teh')->sole()];
        $this->actingAs($this->staf)->post(route('mitra_kantin.akun.store', $this->mitra), ['username' => 'kantinani', 'sandi' => 'rahasia123'])->assertRedirect();
        $this->akunMitra = User::where('username', 'kantinani')->sole();
    }

    private function penghubung(\Closure $jawab): void
    {
        if ($this->jawab === null) {
            Http::fake([self::URL.'/*' => fn (Request $r) => ($this->jawab)($r)]);
        }
        $this->jawab = $jawab;
    }

    private function penghubungSetuju(): void
    {
        $this->penghubung(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/potong') => Http::response(['nomor' => $r['nomor'], 'jenis' => 'potong', 'nominal' => $r['nominal'],
                'nama' => 'AHMAD', 'sisa_saldo' => '50000.00', 'sisa_batas' => '5000.00']),
            str_ends_with($r->url(), '/kembalikan') => Http::response(['nomor' => $r['nomor'], 'jenis' => 'kembalikan', 'nominal' => $r['nominal'],
                'nama' => 'AHMAD', 'sisa_saldo' => '65000.00']),
            default => Http::response(['nama' => 'AHMAD', 'nis' => '1001', 'sisa_batas' => '20000.00', 'perlu_pin' => false, 'punya_pin' => true]),
        });
    }

    private function bayar(array $isi = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->akunMitra)->postJson(route('mitra.bayar'), $isi + [
            'uid' => '0012345678', 'kunci' => 'K-1',
            'baris' => [['id_menu' => $this->nasi->id, 'jumlah' => 1], ['id_menu' => $this->esteh->id, 'jumlah' => 1]],
        ]);
    }

    private function saldoAkun(string $coa): string
    {
        return Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
    }

    public function test_data_mitra_menu_dan_akun_dari_layar_staf(): void
    {
        $this->assertSame(['KB01', 'aktif', 'Sewa tahunan'], [$this->mitra->kode, $this->mitra->status, $this->mitra->keterangan_sewa]);
        $this->actingAs($this->staf)->post(route('mitra_kantin.menu.store', $this->mitra), ['nama' => 'nasi GORENG', 'harga' => 1])->assertSessionHas('error');
        $this->actingAs($this->staf)->put(route('mitra_kantin.menu.update', [$this->mitra, $this->nasi]), ['nama' => 'Nasi goreng', 'harga' => 13000, 'aktif' => 1])
            ->assertSessionHas('status');
        $this->assertSame('13000.00', $this->nasi->fresh()->harga);
        // Perubahan harga tercatat lama → baru.
        $log = ActivityLog::where('aksi', 'ubah_mitra-kantin')->where('ref_jenis', 'MenuMitra')->sole();
        $this->assertSame(['12000.00', '13000.00'], [$log->perubahan['harga']['lama'], $log->perubahan['harga']['baru']]);

        $this->assertSame([$this->mitra->id, false], [$this->akunMitra->id_mitra, (bool) $this->akunMitra->is_admin]);
        $this->actingAs($this->staf)->get(route('mitra_kantin.show', $this->mitra))->assertOk()->assertSee('Nasi goreng')->assertSee('kantinani');
        $this->actingAs($this->staf)->get(route('mitra_kantin.index'))->assertOk()->assertSee('Kantin Basah Bu Ani');
    }

    public function test_akun_mitra_hanya_bisa_membuka_layar_mitra(): void
    {
        $this->actingAs($this->akunMitra)->get('/beranda')->assertRedirect(route('mitra.kasir'));
        $this->actingAs($this->akunMitra)->get(route('mitra_kantin.index'))->assertRedirect(route('mitra.kasir'));
        $this->actingAs($this->akunMitra)->get(route('mitra.kasir'))->assertOk()->assertSee('Kantin Basah Bu Ani')->assertSee('Nasi goreng');
        $this->actingAs($this->akunMitra)->get(route('mitra.saldo'))->assertOk();
        // Staf biasa tak memakai layar mitra.
        $this->actingAs($this->staf)->get(route('mitra.kasir'))->assertForbidden();

        // Mitra dinonaktifkan → akunnya dikeluarkan.
        $this->mitra->update(['status' => 'nonaktif']);
        $this->actingAs($this->akunMitra)->get(route('mitra.kasir'))->assertRedirect(route('login'));
    }

    public function test_bayar_kartu_lewat_penghubung_dengan_asal_kantin_dan_idempoten(): void
    {
        $this->penghubungSetuju();
        $r = $this->bayar()->assertOk()->assertJson(['status' => 'berhasil', 'total' => '15000.00', 'nama' => 'AHMAD', 'sisa_saldo' => '50000.00']);
        $j = PenjualanMitra::sole();
        $this->assertMatchesRegularExpression('/^KM-\d{6}-[A-Z0-9]{10}$/', $j->nomor);
        $this->assertSame(2, $j->rincian()->count());
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/potong') && $q['asal'] === 'kantin' && $q['kode_toko'] === 'KB01'
            && $q['nama_toko'] === 'Kantin Basah Bu Ani' && $q['nomor'] === $j->nomor && Money::eq($q['nominal'], '15000')
            && $q['rincian']['toko'] === 'Kantin Basah Bu Ani' && count($q['rincian']['barang']) === 2);

        // Kiriman ulang dengan kunci yang sama: hasil sama, tak memotong lagi.
        $this->bayar()->assertOk()->assertJson(['nomor' => $j->nomor]);
        $this->assertSame(1, PenjualanMitra::count());
        Http::assertSentCount(1);

        // Menu milik mitra lain / nonaktif ditolak.
        $this->nasi->update(['aktif' => false]);
        $this->bayar(['kunci' => 'K-2'])->assertStatus(422);
    }

    public function test_penolakan_dan_jawaban_hilang(): void
    {
        // Perlu PIN → 409, tercatat gagal; diulang dengan PIN & kunci sama → berhasil dengan nomor baru.
        $this->penghubung(fn (Request $r) => $r['pin'] ? Http::response(['nomor' => $r['nomor'], 'nama' => 'AHMAD', 'sisa_saldo' => '1.00'])
            : Http::response(['message' => 'Melewati batas belanja harian — minta santri mengetik PIN belanjanya.'], 409));
        $this->bayar()->assertStatus(409);
        $this->assertSame('gagal', PenjualanMitra::sole()->status);
        $this->bayar(['pin' => '730519'])->assertOk()->assertJson(['status' => 'berhasil']);
        $this->assertSame(['gagal', 'berhasil'], PenjualanMitra::orderBy('id')->pluck('status')->all());

        // Jawaban hilang lalu dipastikan "dibatalkan" → aman diulang (503 pesan tidak terjadi).
        $this->penghubung(fn (Request $r) => str_ends_with($r->url(), '/pastikan')
            ? Http::response(['status' => 'dibatalkan', 'nomor' => $r['nomor']]) : Http::response(['message' => 'galat'], 500));
        $this->bayar(['kunci' => 'K-3'])->assertStatus(503);
        $this->assertSame('gagal', PenjualanMitra::latest('id')->first()->status);

        // ERP tak terjangkau sama sekali → belum pasti; Cek lagi saat ERP kembali → berhasil.
        $this->penghubung(fn () => Http::response(['message' => 'galat'], 500));
        $this->bayar(['kunci' => 'K-4'])->assertOk()->assertJson(['status' => 'belum_pasti']);
        $j = PenjualanMitra::latest('id')->first();
        $this->penghubung(fn (Request $r) => Http::response(['status' => 'berhasil', 'nomor' => $r['nomor'], 'nama' => 'AHMAD', 'sisa_saldo' => '9.00']));
        $this->actingAs($this->akunMitra)->postJson(route('mitra.cek_lagi', $j->id))->assertOk()->assertJson(['status' => 'berhasil', 'nama' => 'AHMAD']);
    }

    public function test_batal_hanya_hari_yang_sama(): void
    {
        $this->penghubungSetuju();
        $this->bayar()->assertOk();
        $j = PenjualanMitra::sole();
        $this->actingAs($this->akunMitra)->post(route('mitra.batal', $j->id), ['alasan' => 'Salah pesan'])->assertSessionHas('status');
        $j->refresh();
        $this->assertSame(['batal', $j->nomor.'-B', 'Salah pesan'], [$j->status, $j->ref_dompet_batal, $j->alasan_batal]);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/kembalikan') && $q['nomor_asal'] === $j->nomor && Money::eq($q['nominal'], '15000'));

        $lama = PenjualanMitra::create(['nomor' => 'KM-LAMA', 'id_mitra' => $this->mitra->id, 'id_kasir' => $this->akunMitra->id_pengguna,
            'waktu' => now()->subDay(), 'total' => 5000, 'uid_kartu' => 'X1234', 'status' => 'berhasil']);
        $this->actingAs($this->akunMitra)->post(route('mitra.batal', $lama->id), ['alasan' => 'x'])->assertSessionHas('error');
        $this->assertSame('berhasil', $lama->fresh()->status);
    }

    public function test_rekonsiliasi_mengenali_penjualan_mitra(): void
    {
        $kemarin = now()->subDay();
        PenjualanMitra::create(['nomor' => 'KM-1', 'id_mitra' => $this->mitra->id, 'id_kasir' => $this->akunMitra->id_pengguna,
            'waktu' => $kemarin, 'total' => 15000, 'uid_kartu' => 'X1234', 'status' => 'berhasil']);
        $this->penghubung(fn (Request $r) => str_contains($r->url(), '/transaksi')
            ? Http::response(['transaksi' => [
                ['nomor' => 'KM-1', 'jenis' => 'potong', 'nomor_asal' => null, 'nominal' => '15000.00', 'status' => 'berhasil', 'kode_toko' => 'KB01', 'asal' => 'kantin'],
            ]]) : Http::response(['message' => 'tak diharapkan'], 500));

        $rek = app(RekonsiliasiDompetToko::class)->jalankan($kemarin->toDateString());
        $this->assertSame(['cocok', 0], [$rek->status, $rek->jumlah_yatim]);
        Http::assertNotSent(fn (Request $q) => str_ends_with($q->url(), '/kembalikan'));
    }

    public function test_saldo_penarikan_dan_jurnal(): void
    {
        Storage::fake('local');
        $buat = fn ($waktu, $total) => PenjualanMitra::create(['nomor' => 'KM-'.uniqid(), 'id_mitra' => $this->mitra->id,
            'id_kasir' => $this->akunMitra->id_pengguna, 'waktu' => $waktu, 'total' => $total, 'uid_kartu' => 'X1234', 'status' => 'berhasil']);
        $buat(now()->subDays(2), 40000);
        $buat(now()->subDay(), 60000);
        $buat(now(), 25000);   // hari ini: tertahan

        $s = app(SaldoMitraService::class);
        $sal = $s->saldo($this->mitra->id);
        $this->assertSame(['100000.00', '25000.00', '125000.00'], [$sal['bisa_ditarik'], $sal['tertahan'], $sal['saldo']]);

        // Jurnal malam: dua tanggal, hari ini dilewati; diulang tak menggandakan.
        $hasil = $s->jurnalPenjualan();
        $this->assertCount(2, $hasil);
        $this->assertSame([], $s->jurnalPenjualan());
        $this->assertSame(['100000.00', '-100000.00'], [$this->saldoAkun(self::PIUTANG), $this->saldoAkun(self::HUTANG)]);

        // Ajukan: melebihi saldo ditolak; lalu 80.000; pengajuan kedua ditolak selama yang pertama menunggu.
        $this->actingAs($this->akunMitra)->post(route('mitra.ajukan'), ['nominal' => 100001])->assertSessionHas('error');
        $this->actingAs($this->akunMitra)->post(route('mitra.ajukan'), ['nominal' => 80000])->assertSessionHas('status');
        $this->actingAs($this->akunMitra)->post(route('mitra.ajukan'), ['nominal' => 1000])->assertSessionHas('error');
        $p = PenarikanMitra::sole();
        $this->assertSame(['diajukan', '7123', '20000.00'], [$p->status, $p->bank_rekening, $s->saldo($this->mitra->id)['bisa_ditarik']]);

        // Petugas memproses: biaya transfer 2.500 dipotong; jurnal sebesar nominal.
        $this->actingAs($this->staf)->post(route('mitra_kantin.penarikan.proses', $p->id), [
            'biaya_transfer' => 2500, 'kode_coa_bank' => self::BANK, 'tanggal_transfer' => now()->toDateString(),
            'bukti' => UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf'),
        ])->assertSessionHas('status');
        $p->refresh();
        $this->assertSame(['selesai', '2500.00', '77500.00'], [$p->status, $p->biaya_transfer, Money::of($p->diterima())]);
        Storage::disk('local')->assertExists($p->bukti_path);
        $this->assertSame(['-20000.00', '-80000.00'], [$this->saldoAkun(self::HUTANG), $this->saldoAkun(self::BANK)]);
        $this->actingAs($this->akunMitra)->get(route('mitra.bukti', $p->id))->assertOk();
        $this->actingAs($this->akunMitra)->get(route('mitra.saldo'))->assertOk()->assertSee('Sudah ditransfer');

        // Pengajuan berikutnya ditolak petugas → saldo kembali bisa ditarik.
        $this->actingAs($this->akunMitra)->post(route('mitra.ajukan'), ['nominal' => 20000])->assertSessionHas('status');
        $p2 = PenarikanMitra::where('status', 'diajukan')->sole();
        $this->actingAs($this->staf)->post(route('mitra_kantin.penarikan.tolak', $p2->id), ['alasan' => 'Rekening salah'])->assertSessionHas('status');
        $this->assertSame('20000.00', $s->saldo($this->mitra->id)['bisa_ditarik']);
        $this->actingAs($this->staf)->get(route('mitra_kantin.penarikan', ['status' => 'semua']))->assertOk()->assertSee('Rekening salah');
    }

    public function test_tak_tersedia_di_instans_al_wafi(): void
    {
        config(['instans.kode' => 'alwafi']);
        Akses::lupakan();
        $this->actingAs($this->staf)->get(route('mitra_kantin.index'))->assertNotFound();
    }
}
