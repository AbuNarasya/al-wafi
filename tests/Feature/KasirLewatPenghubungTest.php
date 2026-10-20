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
use App\Models\PinPengawas;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Services\Penghubung\KlienDompetToko;
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
        config(['instans.kode' => 'wafimart', 'penghubung.url' => self::URL, 'penghubung.rahasia' => str_repeat('k', 40)]);
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
        $this->assertMatchesRegularExpression('/^WM-'.preg_quote($j->nomor, '/').'-[A-Z0-9]{6}$/', $j->ref_dompet);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/potong') && $r['uid'] === self::UID && $r['pin'] === '730519'
            && Money::eq($r['nominal'], '16000') && $r['kode_toko'] === 'L002' && $r['nama_toko'] === 'Toko Banin'
            && $r->hasHeader('X-Penghubung-Tanda') && $r->hasHeader('X-Penghubung-Waktu'));

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

        $this->penghubung(fn () => Http::response('Bad Gateway', 502));
        try {
            $this->jualKartu(2);
            $this->fail('Harus ditolak.');
        } catch (AppException $e) {
            $this->assertSame([503, KlienDompetToko::TIDAK_TERSEDIA], [$e->status, $e->getMessage()]);
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
            && $q['nomor_asal'] === $b->ref_dompet && Money::eq($q['nominal'], '4000'));

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
}
