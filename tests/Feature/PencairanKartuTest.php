<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\HakAksesModul;
use App\Models\Inventory;
use App\Models\Jenjang;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LokasiPersediaan;
use App\Models\PencairanKartu;
use App\Models\Penjualan;
use App\Models\PinPengawas;
use App\Models\Santri;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KartuSantriService;
use App\Services\Modules\KasirService;
use App\Services\Modules\PencairanKartuService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Akses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PENCAIRAN BELANJA KARTU KE TOKO (D3, mingguan).
 */
class PencairanKartuTest extends TestCase
{
    use RefreshDatabase;

    private const PENAMPUNG = '1.ZZPC.BNK';

    private const KAS_TOKO = '1.ZZPC.TKO';

    private User $kasir;

    private User $keuangan;

    private int $spv;

    private KasirService $k;

    private PencairanKartuService $p;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZPC', 'nama_grup' => 'Cair']);
        foreach ([['1.ZZPC.PRS', 'debet'], ['1.ZZPC.LCI', 'debet'], [self::PENAMPUNG, 'debet'], [self::KAS_TOKO, 'debet'], ['4.ZZPC.JUAL', 'kredit'],
            ['5.ZZPC.HPP', 'debet'], ['5.ZZPC.SKS', 'debet'], [DompetPolicy::COA_TITIPAN['santri'], 'kredit']] as [$kd, $s]) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $kd, 'kode_grup' => 'ZZPC', 'jenis_saldo' => $s]);
        }
        foreach (['1.ZZPC.LCI' => 'Laci', self::PENAMPUNG => 'Bank Titipan Santri', self::KAS_TOKO => 'Bank Wafi Mart'] as $kd => $n) {
            BankAccount::create(['kode_coa' => $kd, 'nama_rekening' => $n, 'jenis_rekening' => 'bank', 'status' => 'aktif']);
        }
        BusinessUnit::create(['kode_unit' => 'ZZPCU', 'nama_unit' => 'Wafi Mart']);
        Bagian::create(['kode_bagian' => 'BZZPC', 'nama_bagian' => 'Koperasi', 'level' => 3]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        LokasiPersediaan::create([
            'kode' => 'L002', 'nama' => 'Koperasi Banat', 'jenis' => 'toko', 'kode_unit' => 'ZZPCU', 'status' => 'aktif',
            'kode_coa_kas' => '1.ZZPC.LCI', 'kode_coa_pendapatan' => '4.ZZPC.JUAL', 'kode_coa_hpp' => '5.ZZPC.HPP',
            'kode_coa_selisih_kas' => '5.ZZPC.SKS', 'kode_bagian' => 'BZZPC',
        ]);
        Inventory::create(['kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Teh Kotak', 'satuan' => 'pcs', 'harga_perolehan' => '0',
            'harga_jual' => '4000', 'dijual_di_toko' => true, 'kode_coa' => '1.ZZPC.PRS', 'status' => 'aktif']);
        InventoryMovement::masuk(['kode_persediaan' => 'BRG0001', 'kode_lokasi' => 'L002', 'kuantiti' => '100', 'nilai' => '300000',
            'sumber_modul' => 'JurnalUmum', 'sumber_ref' => 'AWAL']);
        $wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '0811', 'nama' => 'Ibu', 'telepon' => '0811', 'status' => 'aktif']);
        $s = Santri::create(['no_pendaftaran' => 'ZZPC-1', 'nis' => '1', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id]);
        DompetSantri::create(['id_santri' => $s->id, 'saldo' => 500000, 'batas_harian' => 500000]);
        (new KartuSantriService)->pasang($s->id, 'KARTU01', null);

        $this->kasir = $this->pengguna('kasir', ['pos' => ['buat']]);
        $this->keuangan = $this->pengguna('keu', ['pencairan-kartu' => ['buat', 'hapus']]);
        $spv = $this->pengguna('spv', ['shift-kasir' => ['ubah']]);
        PinPengawas::create(['id_pengguna' => $spv->id_pengguna, 'pin_hash' => Hash::make('482915')]);
        $this->spv = $spv->id_pengguna;
        $this->k = new KasirService;
        $this->p = new PencairanKartuService;
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => in_array('hapus', $aksi, true), 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    /** Satu shift berisi struk kartu `$qty` pcs, lalu (opsional) ditutup. */
    private function shiftKartu(int $qty, bool $tutup = true): array
    {
        $shift = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $jual = $this->k->jual($shift->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => $qty]], 0, $this->kasir->id_pengguna, [], ['uid' => 'KARTU01']);
        if ($tutup) {
            $this->k->tutupShift($shift->id_shift, null, 0, null, $this->kasir->id_pengguna);
        }

        return [$shift, $jual];
    }

    private function saldo(string $coa): string
    {
        return Money::sub(JournalLine::where('kode_coa', $coa)->sum('debet'), JournalLine::where('kode_coa', $coa)->sum('kredit'));
    }

    private function cairkan(): PencairanKartu
    {
        return $this->p->cairkan('L002', now()->toDateString(), self::PENAMPUNG, self::KAS_TOKO, $this->keuangan->id_pengguna);
    }

    public function test_hanya_shift_tertutup_yang_dicairkan_dan_hanya_sekali(): void
    {
        [$shift] = $this->shiftKartu(5, tutup: false);   // 20.000, shift masih buka
        $this->assertSame('0.00', $this->p->menunggu('L002', now()->toDateString())['total']);

        $this->k->tutupShift($shift->id_shift, null, 0, null, $this->kasir->id_pengguna);
        $doc = $this->cairkan();

        $this->assertSame(['20000.00', '0.00', '20000.00'], [$doc->total_belanja, $doc->total_retur, $doc->total]);
        $this->assertSame('20000.00', $this->saldo(self::KAS_TOKO));
        $this->assertSame('-20000.00', $this->saldo(self::PENAMPUNG));
        $this->assertSame($doc->id_pencairan, Penjualan::sole()->id_pencairan);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Tidak ada belanja kartu/');
        $this->cairkan();
    }

    public function test_retur_sesudah_dicairkan_mengurangi_pencairan_berikutnya(): void
    {
        [, $jual] = $this->shiftKartu(5);   // 20.000
        $this->cairkan();

        // Minggu berikutnya: retur 1 pcs dari struk yang sudah dicairkan, plus belanja baru 3 pcs.
        $shift2 = $this->k->bukaShift('L002', 0, $this->kasir->id_pengguna);
        $this->k->retur($shift2->id_shift, $jual->nomor, [['id_penjualan_detail' => $jual->details[0]->id, 'kuantiti' => 1, 'kondisi' => 'layak']],
            'salah', $this->spv, '482915', $this->kasir->id_pengguna);
        $this->k->jual($shift2->id_shift, [['kode_persediaan' => 'BRG0001', 'kuantiti' => 3]], 0, $this->kasir->id_pengguna, [], ['uid' => 'KARTU01']);
        $this->k->tutupShift($shift2->id_shift, null, 0, null, $this->kasir->id_pengguna);

        $doc = $this->cairkan();
        $this->assertSame(['12000.00', '4000.00', '8000.00'], [$doc->total_belanja, $doc->total_retur, $doc->total]);
        $this->assertSame('28000.00', $this->saldo(self::KAS_TOKO));   // 20.000 + 8.000 = belanja bersih 32.000 − 4.000
    }

    public function test_batal_pencairan_membalik_jurnal_dan_melepas_tanda(): void
    {
        $this->shiftKartu(5);
        $doc = $this->cairkan();

        $this->p->batal($doc->id_pencairan, 'rekening keliru', $this->keuangan->id_pengguna);
        $this->assertSame('batal', $doc->fresh()->status);
        $this->assertSame(['0.00', '0.00'], [$this->saldo(self::KAS_TOKO), $this->saldo(self::PENAMPUNG)]);
        $this->assertNull(Penjualan::sole()->id_pencairan);

        $this->assertSame('20000.00', $this->cairkan()->total);   // bisa dicairkan ulang
    }

    public function test_layar_dan_hak_akses(): void
    {
        $this->shiftKartu(5);
        $this->actingAs($this->kasir)->get(route('pencairan_kartu.index'))->assertForbidden();

        $this->actingAs($this->keuangan)->get(route('pencairan_kartu.index'))->assertOk()
            ->assertSee('Koperasi Banat')->assertSee('Rp 20.000')->assertSee('Cairkan');
        $this->actingAs($this->keuangan)->post(route('pencairan_kartu.store'), [
            'kode_lokasi' => 'L002', 'sampai_tanggal' => now()->toDateString(), 'kode_coa_sumber' => self::PENAMPUNG, 'kode_coa_tujuan' => self::PENAMPUNG,
        ])->assertSessionHasErrors('kode_coa_tujuan');
        $this->actingAs($this->keuangan)->post(route('pencairan_kartu.store'), [
            'kode_lokasi' => 'L002', 'sampai_tanggal' => now()->toDateString(), 'kode_coa_sumber' => self::PENAMPUNG, 'kode_coa_tujuan' => self::KAS_TOKO,
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'Rp 20.000'));
        $this->actingAs($this->keuangan)->get(route('pencairan_kartu.index'))->assertOk()->assertSee(PencairanKartu::sole()->nomor);
    }
}
