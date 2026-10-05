<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use App\Services\Modules\DashboardService;
use App\Services\Modules\OpeningBalanceService;
use App\Services\Reports\ReportsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SALDO AWAL TERHITUNG SEKALI — di setiap laporan.
 *
 * Dulu laporan menjumlahkan tabel draf `opening_balances` DITAMBAH seluruh
 * jurnal. Sejak saldo awal difinalisasi menjadi jurnal pembuka, isinya juga
 * ada di jurnal — jadi kas Rp 50 juta tampil Rp 100 juta di neraca, neraca
 * saldo, buku besar, arus kas, dan dashboard.
 *
 * Kini saldo awal dibaca HANYA dari buku besar, dan jurnal pembukanya
 * bertanggal sehari sebelum periode pembukuan, sehingga laporan yang dimulai di
 * periode pertama membacanya sebagai saldo awal — bukan mutasi, bukan kas masuk.
 */
class SaldoAwalSekaliDiLaporanTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.ZZSS.KAS';

    private const MODAL = '3.ZZSS.MDL';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => '1', 'nama_grup' => 'Aset', 'level' => 1]);
        CoaGroup::create(['kode_grup' => '3', 'nama_grup' => 'Ekuitas', 'level' => 1]);
        CoaGroup::create(['kode_grup' => 'G1', 'nama_grup' => 'Kas & Bank', 'kode_induk' => '1', 'level' => 3]);
        CoaGroup::create(['kode_grup' => 'G3', 'nama_grup' => 'Modal', 'kode_induk' => '3', 'level' => 3]);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => 'G1', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::MODAL, 'nama_coa' => 'Aset Neto Awal', 'kode_grup' => 'G3', 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        BusinessUnit::create(['kode_unit' => 'ZZSSU', 'nama_unit' => 'Unit']);
        CompanySettings::create([
            'nama_perusahaan' => 'Uji', 'periode_awal_pembukuan' => '2026-09-01', 'kode_unit_neraca' => 'ZZSSU',
        ]);

        Level::create(['kode_level' => 'LSS', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'admss', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LSS', 'is_admin' => true, 'status' => 'aktif', 'tim_keuangan' => true,
        ]);

        $svc = new OpeningBalanceService;
        $svc->addLine(['kode_coa' => self::KAS, 'jenis_saldo' => 'debet', 'saldo' => '50000000']);
        $svc->addLine(['kode_coa' => self::MODAL, 'jenis_saldo' => 'kredit', 'saldo' => '50000000']);
    }

    private function finalisasi(): void
    {
        (new OpeningBalanceService)->post($this->admin->id_pengguna);
    }

    public function test_jurnal_pembuka_bertanggal_sehari_sebelum_periode(): void
    {
        $this->finalisasi();

        $sa = JournalEntry::where('sumber_modul', OpeningBalanceService::SUMBER)->firstOrFail();
        $this->assertSame('2026-08-31', Carbon::parse($sa->tanggal)->toDateString());
    }

    public function test_draf_yang_belum_difinalisasi_tak_muncul_di_laporan(): void
    {
        $neraca = (new ReportsService)->neraca('2026-09-30');

        $this->assertSame('0.00', $neraca['total_aset']);
    }

    public function test_sesudah_finalisasi_saldo_awal_terhitung_sekali_di_setiap_laporan(): void
    {
        $this->finalisasi();
        $laporan = new ReportsService;

        // Neraca: kas 50 juta, bukan 100 juta — dan tetap seimbang.
        $neraca = $laporan->neraca('2026-09-30');
        $this->assertSame('50000000.00', $neraca['total_aset']);
        $this->assertTrue($neraca['balanced']);

        // Neraca Saldo September: 50 juta tampil sebagai SALDO AWAL, mutasinya nol.
        $ns = collect($laporan->neracaSaldo('2026-09-01', '2026-09-30')['rows'])->firstWhere('kode_coa', self::KAS);
        $this->assertSame('50000000.00', $ns['awal_debet']);
        $this->assertSame('0.00', $ns['mutasi_debet']);

        // Buku Besar kas September: saldo awal 50 juta, tanpa baris mutasi.
        $bb = $laporan->bukuBesar(self::KAS, '2026-09-01', '2026-09-30');
        $this->assertSame('50000000.00', $bb['saldo_awal']);
        $this->assertSame([], $bb['mutasi']);

        // Arus Kas September: 50 juta adalah kas AWAL, bukan kas masuk.
        $ak = $laporan->arusKas('2026-09-01', '2026-09-30');
        $this->assertSame('50000000.00', $ak['saldo_kas_awal']);
        $this->assertSame('0.00', $ak['arus_bersih']);
        $this->assertTrue($ak['selaras']);

        // Dashboard saldo kas & rekening.
        $this->assertSame('50000000.00', Money::of((new DashboardService)->kasRekening()['total']));
    }

    public function test_void_saldo_awal_mengembalikan_laporan_ke_nol(): void
    {
        $this->finalisasi();
        (new OpeningBalanceService)->void($this->admin->id_pengguna);

        $this->assertSame('0.00', (new ReportsService)->neraca('2026-09-30')['total_aset']);
        // Draf kembali bisa disunting, tapi tetap tak masuk laporan.
        $this->assertSame('0.00', Money::of((new DashboardService)->kasRekening()['total']));
    }
}
