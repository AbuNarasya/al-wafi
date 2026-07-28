<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\Budget;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\PengajuanPembayaran;
use App\Services\Ledger\AnggaranPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kebijakan anggaran: overbudget, belum dianggarkan, komitmen. */
class AnggaranPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZAG';
    private const AKUN1 = '5.ZZAG.1';
    private const AKUN2 = '5.ZZAG.2';
    private const BAGIAN = 'ZZBAG';
    private const UNIT = 'ZZUNIT';

    protected function setUp(): void
    {
        parent::setUp();

        CompanySettings::create([
            'id' => 1, 'nama_perusahaan' => 'Al Wafi', 'periode_awal_pembukuan' => '2026-01-01',
            'bulan_awal_anggaran' => 1,
        ]);
        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Bagian', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'AG Test']);
        CoaDetail::create(['kode_coa' => self::AKUN1, 'nama_coa' => 'Beban 1', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::AKUN2, 'nama_coa' => 'Beban 2', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        // Anggaran AKUN1 = 1.000.000 (Juli 2026).
        Budget::create(['tahun' => 2026, 'bulan' => 7, 'kode_coa' => self::AKUN1, 'kode_bagian' => self::BAGIAN, 'nominal' => '1000000']);
    }

    private function eval(string $akun, string $nominal): array
    {
        return AnggaranPolicy::evaluasiAnggaran([
            'tahun' => 2026, 'bulan' => 7, 'kode_coa' => $akun, 'kode_bagian' => self::BAGIAN, 'nominal' => $nominal,
        ]);
    }

    public function test_dalam_anggaran_tidak_eskalasi(): void
    {
        $r = $this->eval(self::AKUN1, '500000');
        $this->assertSame('1000000.00', $r['anggaran']);
        $this->assertFalse($r['overbudget']);
        $this->assertFalse($r['belum_dianggarkan']);
        $this->assertFalse($r['perlu_eskalasi']);
    }

    public function test_proyeksi_melebihi_anggaran_overbudget(): void
    {
        $r = $this->eval(self::AKUN1, '1500000');
        $this->assertTrue($r['overbudget']);
        $this->assertTrue($r['perlu_eskalasi']);
    }

    public function test_akun_belum_dianggarkan_eskalasi(): void
    {
        $r = $this->eval(self::AKUN2, '10000');
        $this->assertTrue($r['belum_dianggarkan']);
        $this->assertTrue($r['perlu_eskalasi']);
        $this->assertFalse($r['overbudget']); // overbudget khusus akun yang dianggarkan
    }

    public function test_komitmen_pengajuan_dihitung(): void
    {
        // Pengajuan "diajukan" 700.000 pada akun yang sama → jadi komitmen.
        $p = PengajuanPembayaran::create([
            'nomor' => 'PB-TEST-1', 'tanggal' => '2026-07-10', 'jenis' => 'pembayaran',
            'kode_bagian' => self::BAGIAN, 'nominal' => '700000', 'keterangan' => 'Test', 'status' => 'diajukan',
            'id_pengguna' => 1,
        ]);
        $p->details()->create(['kode_coa' => self::AKUN1, 'nama_coa' => 'Beban 1', 'nominal' => '700000', 'kode_unit' => self::UNIT]);

        // Terpakai kini 700.000 (komitmen). Ajukan 500.000 → proyeksi 1.200.000 > 1.000.000.
        $r = $this->eval(self::AKUN1, '500000');
        $this->assertSame('700000.00', $r['komitmen']);
        $this->assertTrue($r['overbudget']);
    }
}
