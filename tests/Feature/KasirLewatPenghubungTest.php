<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\Penjualan;
use App\Models\PermintaanKartu;
use App\Models\PinPengawas;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Services\Penghubung\KlienDompetToko;
use App\Services\Penghubung\PermintaanKartuTertunda;
use App\Support\Akses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KASIR WAFI MART LEWAT PENGHUBUNG (Fase 3b, 20 Okt 2026) — instans Wafi Mart tak punya santri; kartu,
 * saldo, batas & PIN diperiksa ERP Al Wafi lewat API bertanda tangan (dipalsukan dengan Http::fake).
 *
 * Yang dijaga:
 *  • Struk kartu menyimpan snapshot jawaban (nama, nomor rujukan, sisa saldo) dan tercetak di struk.
 *  • Penghubung menolak / tak menjawab → struk & stok tergulung UTUH, kasir diminta terima tunai.
 *  • Batal & retur memanggil `kembalikan` dengan nomor asal yang benar.
 *  • Tutup shift: penjualan kartu didebet ke Piutang ke Al Wafi, bukan Titipan Uang Saku.
 */
class KasirLewatPenghubungTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://erp.contoh.test';

    private const UID = '0012345678';

    private User $kasir;

    private User $pengawas;

    private ShiftKasir $shift;

    private KasirService $k;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instans.kode' => 'wafimart', 'penghubung.url' => self::URL, 'penghubung.rahasia' => str_repeat('k', 40),
            'penghubung.jeda_pastikan_ms' => 0]);
        Akses::lupakan();

        CoaGroup::create(['kode_grup' => 'ZZPH', 'nama_grup' => 'Toko']);
        foreach ([['1.ZZPH.PRS', 'debet'], ['1.ZZPH.LCI', 'debet'], ['1.ZZPH.PIU', 'debet'], ['4.ZZPH.JUAL', 'kredit'],
            ['5.ZZPH.HPP', 'debet'], ['5.ZZPH.SKS', 'debet']] as [$kd, $s]) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $kd, 'kode_grup' => 'ZZPH', 'jenis_saldo' => $s]);
        }
        CompanySettings::updateOrCreate(['id' => 1], ['id' => 1, 'nama_perusahaan' => 'Wafi Mart',
            'periode_awal_pembukuan' => '2026-01-01', 'kode_coa_kartu_santri' => '1.ZZPH.PIU']);
        BankAccount::create(['kode_coa' => '1.ZZPH.LCI', 'nama_rekening' => 'Laci', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZPHU', 'nama_unit' => 'Toko Banin']);
        Bagian::create(['kode_bagian' => 'BZZPH', 'nama_bagian' => 'Toko', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Toko Banin', 'jenis' => 'toko', 'kode_unit' => 'ZZPHU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZPH.LCI', 'kode_coa_pendapatan' => '4.ZZPH.JUAL', 'kode_coa_hpp' => '5.ZZPH.HPP',
            'kode_coa_selisih_kas' => '5.ZZPH.SKS', 'kode_bagian' => 'BZZPH',
        ]);
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZPH.PRS', 'status' => 'aktif']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '100', 'nilai' => '300000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
        $this->pengawas = $this->pengguna('spv', ['shift-kasir' => ['ubah']]);
        PinPengawas::create(['id_pengguna' => $this->pengawas->id_pengguna, 'pin_hash' => Hash::make('482915')]);

        $this->k = new KasirService;
        $this->shift = $this->k->bukaShift('L002', 100000, $this->kasir->id_pengguna);
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    /** Jawaban penghubung palsu yang sedang berlaku (Http::fake menumpuk stub, jadi cukup dipasang sekali). */
    private ?\Closure $jawab = null;

    /** Penghubung palsu yang menyetujui semua: potong & kembalikan menjawab ulang nomornya. */
    private function penghubungSetuju(): void
    {
        $this->penghubung(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/potong') => Http::response(['nomor' => $r['nomor'], 'jenis' => 'potong',
                'nominal' => $r['nominal'], 'nama' => 'AHMAD', 'sisa_saldo' => '34000.00', 'sisa_batas' => '4000.00']),
            str_ends_with($r->url(), '/kembalikan') => Http::response(['nomor' => $r['nomor'], 'jenis' => 'kembalikan',
                'nominal' => $r['nominal'], 'nama' => 'AHMAD', 'sisa_saldo' => '50000.00']),
            default => Http::response(['nama' => 'AHMAD', 'nis' => '242501001',
                'sisa_batas' => '20000.00', 'perlu_pin' => false, 'punya_pin' => true]),
        });
    }

    private function penghubung(\Closure $jawab): void
    {
        if ($this->jawab === null) {
            Http::fake([self::URL.'/*' => fn (Request $r) => ($this->jawab)($r)]);
        }
        $this->jawab = $jawab;
    }

    private function jualKartu(int $qty, ?string $pin = null): Penjualan
    {
        return $this->k->jual($this->shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => $qty]], 0, $this->kasir->id_pengguna, [],
            ['uid' => self::UID, 'pin' => $pin]);
    }

    private function stok(): string
    {
        return Money::of(\App\Models\MutasiPersediaan::where('kode_persediaan', 'BRG0001')->sum('kuantiti'), 4);
    }

    private function saldoAkun(string $coa): string
    {
        return Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
    }

    public function test_belanja_kartu_dipotong_lewat_penghubung_dan_jurnal_ke_piutang(): void
    {
        $this->penghubungSetuju();
        $j = $this->jualKartu(4, '730519');   // 16.000

        $this->assertSame(['dompet', '16000.00', null, self::UID, 'AHMAD', '34000.00'],
            [$j->metode, $j->bayar, $j->id_santri, $j->uid_kartu, $j->nama_kartu, $j->fresh()->sisa_saldo_kartu]);
        // Nomor rujukan = nomor niat bayar yang disimpan sebelum memanggil penghubung (Fase 3c-1).
        $this->assertMatchesRegularExpression('/^WM-\d{6}-[A-Z0-9]{10}$/', $j->ref_dompet);
        $p = PermintaanKartu::where('nomor', $j->ref_dompet)->first();
        $this->assertSame(['berhasil', $j->id_penjualan, 'AHMAD'], [$p->status, $p->id_penjualan, $p->hasil['nama']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/potong') && $r['uid'] === self::UID && $r['pin'] === '730519'
            && Money::eq($r['nominal'], '16000') && $r['kode_toko'] === 'L002' && $r['nama_toko'] === 'Toko Banin'
            && $r->hasHeader('X-Penghubung-Tanda') && $r->hasHeader('X-Penghubung-Waktu'));
        // Daftar barang ikut terkirim untuk portal wali — tanpa harga pokok.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/potong') && $r['rincian']['struk'] === $j->nomor
            && $r['rincian']['toko'] === 'Toko Banin' && count($r['rincian']['barang']) === 1
            && Money::eq($r['rincian']['barang'][0]['subtotal'], '16000') && ! isset($r['rincian']['barang'][0]['hpp']));

        $tutup = $this->k->tutupShift($this->shift->id_shift, null, 100000, null, $this->kasir->id_pengguna);
        $this->assertSame(['0.00', '16000.00', '0.00'], [$tutup->total_tunai, $tutup->total_dompet, $tutup->selisih]);
        $this->assertSame('16000.00', $this->saldoAkun('1.ZZPH.PIU'));
        $this->assertSame('-16000.00', $this->saldoAkun('4.ZZPH.JUAL'));
    }

    public function test_penghubung_menolak_atau_diam_struk_dan_stok_tergulung(): void
    {
        $stokAwal = $this->stok();
        $this->penghubung(fn () => Http::response(['message' => 'Saldo uang saku tidak cukup.'], 422));
        try {
            $this->jualKartu(2);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertSame([422, 'Saldo uang saku tidak cukup.'], [$e->status, $e->getMessage()]);
        }

        $this->assertSame('gagal', PermintaanKartu::latest('id')->value('status'));   // penolakan = pasti tak terpotong

        // 5xx terus-menerus (potong & pastikan): TAK PASTI → ditahan, bukan "gagal, bayar tunai".
        $this->penghubung(fn () => Http::response('Bad Gateway', 502));
        try {
            $this->jualKartu(2);
            $this->fail('Harus ditahan.');
        } catch (PermintaanKartuTertunda $e) {
            $this->assertSame(KasirService::PESAN_BELUM_PASTI, $e->getMessage());
            $this->assertSame('belum_pasti', PermintaanKartu::find($e->idPermintaan)->status);
        }

        $this->assertSame(0, Penjualan::count());
        $this->assertSame($stokAwal, $this->stok());

        // Belum diatur sama sekali → tetap 503, tak pernah dianggap lunas.
        config(['penghubung.url' => '']);
        try {
            $this->jualKartu(1);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertSame(0, Penjualan::count());
    }

    public function test_batal_dan_retur_mengembalikan_lewat_penghubung(): void
    {
        $this->penghubungSetuju();
        $a = $this->jualKartu(2);
        $b = $this->jualKartu(3);

        $this->k->batalPenjualan($a->id_penjualan, 'salah scan', $this->pengawas->id_pengguna);
        $a->refresh();
        $this->assertSame(['batal', 'WM-BATAL-'.$a->ref_dompet], [$a->status, $a->ref_dompet_batal]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/kembalikan') && $r['nomor'] === 'WM-BATAL-'.$a->ref_dompet
            && $r['nomor_asal'] === $a->ref_dompet && Money::eq($r['nominal'], '8000'));

        $r = $this->k->retur($this->shift->id_shift, $b->nomor, [['id_penjualan_detail' => $b->details[0]->id, 'kuantiti' => 1, 'kondisi' => 'layak']],
            'salah ambil', $this->pengawas->id_pengguna, '482915', $this->kasir->id_pengguna);
        $this->assertSame(['dompet', '4000.00'], [$r->metode, $r->total]);
        $this->assertStringStartsWith('WM-'.$r->nomor.'-', $r->ref_dompet);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/kembalikan') && $q['nomor'] === $r->ref_dompet
            && $q['nomor_asal'] === $b->ref_dompet && Money::eq($q['nominal'], '4000')
            && $q['rincian']['jenis'] === 'retur' && $q['rincian']['struk_asal'] === $b->nomor
            && Money::eq($q['rincian']['barang'][0]['jumlah'], '1') && Money::eq($q['rincian']['barang'][0]['subtotal'], '4000'));

        // Penjualan kartu 12.000 − retur 4.000 → piutang ke Al Wafi 8.000.
        $this->k->tutupShift($this->shift->id_shift, null, 100000, null, $this->kasir->id_pengguna);
        $this->assertSame('8000.00', $this->saldoAkun('1.ZZPH.PIU'));
    }

    public function test_retur_ditolak_penghubung_tergulung(): void
    {
        $this->penghubungSetuju();
        $b = $this->jualKartu(3);
        $stok = $this->stok();
        $this->penghubung(fn () => Http::response('', 500));

        try {
            $this->k->retur($this->shift->id_shift, $b->nomor, [['id_penjualan_detail' => $b->details[0]->id, 'kuantiti' => 1, 'kondisi' => 'layak']],
                'salah ambil', $this->pengawas->id_pengguna, '482915', $this->kasir->id_pengguna);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertSame(0, \App\Models\ReturPenjualan::count());
        $this->assertSame($stok, $this->stok());
    }

    public function test_layar_kasir_cek_kartu_dan_struk(): void
    {
        $this->penghubungSetuju();
        $this->actingAs($this->kasir)->getJson(route('pos.kartu', ['uid' => self::UID, 'total' => 8000]))->assertOk()
            ->assertExactJson(['nama' => 'AHMAD', 'nis' => '242501001', 'sisa_batas' => 20000, 'perlu_pin' => false, 'punya_pin' => true]);

        $hasil = $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 0, 'baris' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => 2]], 'kartu_uid' => self::UID,
        ])->assertOk()->json();
        $this->actingAs($this->kasir)->get($hasil['struk'])->assertOk()->assertSee('AHMAD')->assertSee('Sisa uang saku')->assertSee('34.000');

        $this->penghubung(fn () => Http::response(['message' => 'Kartu tidak dikenal, diblokir, atau bukan santri aktif.'], 404));
        $this->actingAs($this->kasir)->getJson(route('pos.kartu', ['uid' => '999999']))->assertNotFound()
            ->assertJson(['message' => 'Kartu tidak dikenal, diblokir, atau bukan santri aktif.']);
    }

    // ---- Fase 3c-1: pencegahan potongan yatim ----

    private function hitung(string $ujung): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $ujung))->count();
    }

    /** Potong selalu putus di jalan; jawaban "pastikan" ditentukan `$pastikan`. */
    private function potongPutus(\Closure $pastikan): void
    {
        $this->penghubung(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/potong') => Http::failedConnection(),
            str_ends_with($r->url(), '/pastikan') => $pastikan($r),
            default => Http::response([], 404),
        });
    }

    public function test_jawaban_hilang_lalu_dipastikan_terpotong_struk_tetap_tersimpan(): void
    {
        $this->potongPutus(fn (Request $r) => Http::response(['status' => 'berhasil', 'nomor' => $r['nomor'], 'nama' => 'AHMAD', 'sisa_saldo' => '42000.00']));
        $j = $this->jualKartu(2);

        $p = PermintaanKartu::where('nomor', $j->ref_dompet)->first();
        $this->assertSame(['berhasil', $j->id_penjualan], [$p->status, $p->id_penjualan]);
        $this->assertSame(['AHMAD', '42000.00'], [$j->nama_kartu, $j->fresh()->sisa_saldo_kartu]);
        $this->assertSame(1, $this->hitung('/potong'));   // TIDAK memotong untuk kedua kalinya
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pastikan') && $r['nomor'] === $j->ref_dompet
            && $r['jenis'] === 'potong' && Money::eq($r['nominal'], '8000'));
    }

    public function test_jawaban_hilang_lalu_dipastikan_tidak_terjadi_aman_tunai(): void
    {
        $stok = $this->stok();
        $this->potongPutus(fn (Request $r) => Http::response(['status' => 'dibatalkan', 'nomor' => $r['nomor']]));
        try {
            $this->jualKartu(2);
            $this->fail('Harus gagal.');
        } catch (AppException $e) {
            $this->assertSame([503, KasirService::PESAN_TIDAK_TERJADI], [$e->status, $e->getMessage()]);
        }
        $this->assertSame(0, Penjualan::count());
        $this->assertSame($stok, $this->stok());
        $this->assertSame('gagal', PermintaanKartu::value('status'));
    }

    public function test_al_wafi_tak_terjangkau_ditahan_lalu_cek_lagi_menyimpan_struk(): void
    {
        $this->potongPutus(fn () => Http::failedConnection());
        $kirim = fn (int $qty, ?int $permintaan = null) => $this->actingAs($this->kasir)->postJson(route('pos.jual'), [
            'bayar' => 0, 'baris' => [['kode_persediaan' => 'BRG0001', 'kuantiti' => $qty]], 'kartu_uid' => self::UID,
            'permintaan_kartu' => $permintaan,
        ]);

        $id = $kirim(2)->assertStatus(422)->assertJson(['message' => KasirService::PESAN_BELUM_PASTI])->json('tertunda');
        $this->assertSame(3, $this->hitung('/pastikan'));
        $this->assertSame(0, Penjualan::count());

        // Masih tak terjangkau → tetap belum pasti.
        $this->actingAs($this->kasir)->postJson(route('pos.kartu_pastikan', $id))->assertOk()->assertJson(['status' => 'belum_pasti']);

        // Al Wafi kembali: ternyata terpotong.
        $this->potongPutus(fn (Request $r) => Http::response(['status' => 'berhasil', 'nomor' => $r['nomor'], 'nama' => 'AHMAD', 'sisa_saldo' => '42000.00']));
        $this->actingAs($this->kasir)->postJson(route('pos.kartu_pastikan', $id))->assertOk()->assertJson(['status' => 'berhasil', 'sudah_struk' => false]);

        // Keranjang berubah → ditahan, tak tersimpan dengan nilai lain.
        $kirim(3, $id)->assertStatus(422)->assertJson(['tertunda' => $id]);
        $kirim(2, $id)->assertOk();
        $this->assertSame(1, $this->hitung('/potong'));
        $this->assertSame(PermintaanKartu::find($id)->nomor, Penjualan::sole()->ref_dompet);

        // Diulang lagi (tombol ditekan dua kali) → tidak jadi struk kedua.
        $kirim(2, $id)->assertStatus(422);
        $this->assertSame(1, Penjualan::count());
    }

    // ---- Fase 3c-2: rekonsiliasi malam ----

    public function test_rekonsiliasi_mengembalikan_potongan_yatim_dan_menandai_selisih(): void
    {
        $kemarin = now()->subDay()->toDateString();
        $this->penghubungSetuju();
        $a = $this->jualKartu(2);   // 8.000 — cocok
        $b = $this->jualKartu(1);   // 4.000 — ada di toko, tak dikenal Al Wafi
        Penjualan::query()->update(['waktu' => now()->subDay()]);
        PermintaanKartu::create(['nomor' => 'WM-YTM', 'jenis' => 'potong', 'nominal' => 5000, 'uid_kartu' => self::UID,
            'id_shift' => $this->shift->id_shift, 'id_kasir' => $this->kasir->id_pengguna, 'status' => 'berhasil']);

        $this->penghubung(fn (Request $r) => match (true) {
            str_contains($r->url(), '/transaksi') => Http::response(['transaksi' => [
                ['nomor' => $a->ref_dompet, 'jenis' => 'potong', 'nomor_asal' => null, 'nominal' => '8000.00', 'status' => 'berhasil'],
                ['nomor' => 'WM-YTM', 'jenis' => 'potong', 'nomor_asal' => null, 'nominal' => '5000.00', 'status' => 'berhasil'],
                ['nomor' => 'WM-ASING', 'jenis' => 'kembalikan', 'nomor_asal' => $a->ref_dompet, 'nominal' => '1000.00', 'status' => 'berhasil'],
                ['nomor' => 'WM-NISAN', 'jenis' => 'potong', 'nomor_asal' => null, 'nominal' => '3000.00', 'status' => 'dibatalkan'],
            ]]),
            str_ends_with($r->url(), '/kembalikan') => Http::response(['nomor' => $r['nomor'], 'jenis' => 'kembalikan', 'nominal' => $r['nominal']]),
            str_ends_with($r->url(), '/pastikan') => Http::response(['status' => 'dibatalkan', 'nomor' => $r['nomor']]),
            default => Http::response([], 404),
        });

        $rk = app(\App\Services\Modules\RekonsiliasiDompetToko::class);
        $h = $rk->jalankan($kemarin);
        $this->assertSame(['perlu_diputus', 3, 1, '5000.00', 2, '12000.00'],
            [$h->status, $h->jumlah_transaksi, $h->jumlah_yatim, $h->nilai_yatim, $h->perlu_diputus, $h->bersih_al_wafi]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/kembalikan') && $r['nomor'] === 'WM-YATIM-WM-YTM'
            && $r['nomor_asal'] === 'WM-YTM' && Money::eq($r['nominal'], '5000'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pastikan') && $r['nomor'] === $b->ref_dompet);
        // Kasir yang masih menahannya tak bisa lagi menyimpannya sebagai struk.
        $this->assertSame('gagal', PermintaanKartu::where('nomor', 'WM-YTM')->value('status'));
        $this->assertEqualsCanonicalizing(['WM-YTM', 'WM-ASING', $b->ref_dompet], collect($h->rincian)->pluck('nomor')->all());

        // Diulang: satu baris per tanggal, pengembalian memakai nomor yang sama (idempoten di Al Wafi).
        $rk->jalankan($kemarin);
        $this->assertSame(1, \App\Models\RekonsiliasiDompetToko::count());
        $this->assertSame(2, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/kembalikan') && $r['nomor'] === 'WM-YATIM-WM-YTM')->count());

        // Layar: staf menandai sudah ditangani; tanggal hari ini ditolak.
        $staf = $this->pengguna('akuntan', ['rekonsiliasi-kartu' => ['ubah']]);
        $this->actingAs($staf)->get(route('rekonsiliasi_kartu.index'))->assertOk()->assertSee('Perlu diputus')->assertSee('WM-ASING');
        $this->actingAs($staf)->post(route('rekonsiliasi_kartu.tangani', $h->id), ['catatan' => 'Retur manual dicatat'])->assertRedirect();
        $this->assertNotNull($h->fresh()->ditangani_pada);
        $this->actingAs($staf)->post(route('rekonsiliasi_kartu.jalankan'), ['tanggal' => now()->toDateString()])->assertSessionHasErrors('tanggal');
    }

    public function test_rekonsiliasi_al_wafi_tak_terjangkau_dicatat_gagal_lalu_diulang_otomatis(): void
    {
        $this->penghubung(fn () => Http::failedConnection());
        $rk = app(\App\Services\Modules\RekonsiliasiDompetToko::class);
        $hasil = $rk->jalankanTertunda();
        $this->assertCount(\App\Services\Modules\RekonsiliasiDompetToko::HARI_MUNDUR, $hasil);
        $this->assertSame(['gagal'], collect($hasil)->pluck('status')->unique()->values()->all());

        // Al Wafi kembali → yang gagal dijalankan ulang, yang sudah cocok tak diulang.
        $this->penghubung(fn () => Http::response(['transaksi' => []]));
        $this->assertSame(['cocok'], collect($rk->jalankanTertunda())->pluck('status')->unique()->values()->all());
        $this->assertSame([], $rk->jalankanTertunda());
    }

    // ---- Fase 3c-3: penerimaan pencairan dari Al Wafi ----

    public function test_penerimaan_pencairan_dari_al_wafi_melunasi_piutang(): void
    {
        $tolak = false;
        $this->penghubung(function (Request $r) use (&$tolak) {
            return match (true) {
                str_ends_with($r->url(), '/pencairan/terima') => $tolak
                    ? Http::response(['message' => 'Pencairan PWM-2610-0001 sudah dibatalkan di ERP Al Wafi.'], 409)
                    : Http::response(['nomor' => $r['nomor'], 'diterima' => true]),
                str_contains($r->url(), '/pencairan') => Http::response(['pencairan' => [
                    ['nomor' => 'PWM-2610-0001', 'tanggal' => '2026-10-20', 'sampai_tanggal' => '2026-10-19', 'jumlah_transaksi' => 3,
                        'total' => '12000.00', 'status' => 'aktif', 'diterima' => false],
                ]]),
                default => Http::response([], 404),
            };
        });
        $s = app(\App\Services\Modules\PenerimaanAlWafiService::class);

        // Al Wafi menolak → penerimaan & jurnalnya tergulung.
        $tolak = true;
        try {
            $s->terima('PWM-2610-0001', '1.ZZPH.LCI', $this->kasir->id_pengguna);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(0, \App\Models\PenerimaanAlWafi::count());

        $tolak = false;
        $doc = $s->terima('PWM-2610-0001', '1.ZZPH.LCI', $this->kasir->id_pengguna);
        $this->assertSame('12000.00', $doc->total);
        $this->assertSame(['12000.00', '-12000.00'], [$this->saldoAkun('1.ZZPH.LCI'), $this->saldoAkun('1.ZZPH.PIU')]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pencairan/terima') && $r['nomor'] === 'PWM-2610-0001' && Money::eq($r['total'], '12000'));
        try {
            $s->terima('PWM-2610-0001', '1.ZZPH.LCI', $this->kasir->id_pengguna);
            $this->fail('Tak boleh diterima dua kali.');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
        }

        $staf = $this->pengguna('keuwm', ['penerimaan-al-wafi' => ['buat']]);
        $this->actingAs($staf)->get(route('penerimaan_al_wafi.index'))->assertOk()->assertSee('PWM-2610-0001')->assertSee('Diterima');
        // Modul sisi Al Wafi mati di instans ini.
        $this->actingAs($staf)->get('/pencairan-toko')->assertNotFound();
    }

    public function test_cek_lagi_hanya_milik_kasirnya_sendiri(): void
    {
        $this->potongPutus(fn () => Http::failedConnection());
        try {
            $this->jualKartu(1);
        } catch (PermintaanKartuTertunda $e) {
            $id = $e->idPermintaan;
        }
        $lain = $this->pengguna('kasir2', ['pos' => ['buat']]);
        $this->actingAs($lain)->postJson(route('pos.kartu_pastikan', $id))->assertNotFound();
    }
}
