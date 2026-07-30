<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Jenjang;
use App\Models\TahunAjaran;
use App\Models\TarifSpp;
use App\Models\TargetSantri;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\JenjangService;
use App\Support\Referensi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Master Jenjang: sumber tunggal daftar jenjang + pagar hapus bila dirujuk. */
class JenjangTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZJJ', 'nama_grup' => 'JJ']);
        CoaDetail::create(['kode_coa' => '4.ZZJJ.SPP', 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => 'ZZJJ', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZJJU', 'nama_unit' => 'Unit']);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
    }

    public function test_referensi_jenjang_dari_master_terurut_dan_hanya_aktif(): void
    {
        $svc = new JenjangService;
        $svc->create(['kode' => 'SMA', 'nama' => 'Sekolah Menengah Atas', 'urutan' => 3]);
        $svc->create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        $svc->create(['kode' => 'SMP', 'nama' => 'Sekolah Menengah Pertama', 'urutan' => 2]);
        $svc->create(['kode' => 'PAUD', 'nama' => 'PAUD', 'urutan' => 0, 'status' => 'nonaktif']);

        // Urut mengikuti kolom `urutan`, bukan abjad; yang nonaktif tak ikut.
        $this->assertSame(['SD', 'SMP', 'SMA'], array_keys(Referensi::jenjang()));
        $this->assertSame('Sekolah Dasar', Referensi::jenjang()['SD']);
    }

    public function test_kode_tidak_bisa_diubah_saat_update(): void
    {
        $svc = new JenjangService;
        $svc->create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        $svc->update('SD', ['kode' => 'SDIT', 'nama' => 'SD Islam Terpadu', 'urutan' => 5]);

        $this->assertNotNull(Jenjang::find('SD'));
        $this->assertNull(Jenjang::find('SDIT'));
        $this->assertSame('SD Islam Terpadu', Jenjang::find('SD')->nama);
        $this->assertSame(5, Jenjang::find('SD')->urutan);
    }

    public function test_kode_ganda_ditolak(): void
    {
        $svc = new JenjangService;
        $svc->create(['kode' => 'SD', 'nama' => 'Sekolah Dasar']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah ada/i');
        $svc->create(['kode' => 'SD', 'nama' => 'Duplikat']);
    }

    public function test_tidak_bisa_dihapus_bila_masih_dirujuk(): void
    {
        $svc = new JenjangService;
        $svc->create(['kode' => 'SD', 'nama' => 'Sekolah Dasar']);
        TargetSantri::create(['tahun_ajaran' => '2026/2027', 'kode_jenjang' => 'SD', 'target' => 100]);

        try {
            $svc->remove('SD');
            $this->fail('harus 409');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('target santri', $e->getMessage());
        }

        TargetSantri::where('kode_jenjang', 'SD')->delete();
        $svc->remove('SD');
        $this->assertDatabaseMissing('jenjang', ['kode' => 'SD']);
    }

    public function test_tarif_spp_terpusat_di_jenis_biaya(): void
    {
        (new JenjangService)->create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        $spp = $this->buatBiaya([
            'kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '300000', 'kode_jenjang' => 'SD',
            'kode_coa_pendapatan' => '4.ZZJJ.SPP', 'kode_unit' => 'ZZJJU', 'tahun_ajaran' => '2026/2027', 'berulang' => true,
        ]);

        // Tabel `tarif_spp` lama sudah dibuang. Tarifnya kini di grid `tarif_biaya`,
        // sedangkan jenis biaya hanya memegang akun & jenjangnya.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('tarif_spp'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('jenis_biaya', 'nominal'));
        $this->assertSame('SD', $spp->kode_jenjang);
        $this->assertSame(300000.0, (float) (new \App\Services\Modules\TarifService)
            ->cari('spp', '2026/2027', 'SD', null)['nominal']);
    }

    public function test_target_santri_memakai_kode_jenjang_yang_seragam(): void
    {
        (new JenjangService)->create(['kode' => 'SMP', 'nama' => 'Sekolah Menengah Pertama']);
        $target = TargetSantri::create(['tahun_ajaran' => '2026/2027', 'kode_jenjang' => 'SMP', 'target' => 60]);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('target_santri', 'jenjang'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('target_santri', 'kode_jenjang'));
        $this->assertSame('Sekolah Menengah Pertama', $target->jenjang->nama);
    }
}
