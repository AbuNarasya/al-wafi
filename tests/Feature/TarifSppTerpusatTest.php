<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tarif SPP terpusat di master Jenis Biaya (tabel tarif_spp dibuang). */
class TarifSppTerpusatTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTS';
    private const PEND = '4.ZZTS.SPP';
    private const PIUT = '1.ZZTS.SPP';
    private const UNIT = 'ZZTSU';
    private const TA = '2026/2027';

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'TS']);
        foreach ([[self::PEND, 'Pendapatan SPP', 'kredit'], [self::PIUT, 'Piutang SPP', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2]);
        User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true]);

        (new JenisBiayaService)->create(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    private function buatSpp(string $kode, ?string $nominal, ?string $jenjang, string $status = 'aktif'): void
    {
        (new JenisBiayaService)->create([
            'kode' => $kode, 'nama' => 'SPP', 'tipe' => 'spp', 'nominal' => $nominal, 'kode_jenjang' => $jenjang,
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT,
            'tahun_ajaran' => self::TA, 'berulang' => true, 'status' => $status,
        ]);
    }

    private function santriAktif(string $jenjang): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif']);

        return $santri->refresh();
    }

    public function test_tarif_diambil_per_jenjang_lalu_fallback_umum(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatSpp('SPP-SMP', '400000', 'SMP');
        $svc = new SppService;

        // Jenjang punya tarif sendiri.
        $smp = $svc->nominalSppSantri($this->santriAktif('SMP')->id);
        $this->assertSame('400000.00', $smp['nominal']);
        $this->assertSame('SPP-SMP', $smp['kode_jenis']);
        $this->assertSame('jenjang', $smp['asal']);

        // Jenjang tanpa tarif sendiri → jatuh ke UMUM.
        $sd = $svc->nominalSppSantri($this->santriAktif('SD')->id);
        $this->assertSame('250000.00', $sd['nominal']);
        $this->assertSame('SPP-UMUM', $sd['kode_jenis']);
    }

    public function test_nominal_khusus_santri_mengalahkan_tarif_jenjang(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $santri = $this->santriAktif('SD');
        $santri->update(['nominal_spp' => '100000', 'keterangan_spp' => 'beasiswa sebagian']);

        $hasil = (new SppService)->nominalSppSantri($santri->id);

        $this->assertSame('100000.00', $hasil['nominal']);
        $this->assertSame('khusus', $hasil['asal']);
        $this->assertSame('SPP-UMUM', $hasil['kode_jenis']); // jenis tetap dari master
        $this->assertSame('beasiswa sebagian', $hasil['keterangan']);
    }

    public function test_tarif_spp_ganda_untuk_jenjang_sama_ditolak(): void
    {
        $this->buatSpp('SPP-SD', '300000', 'SD');

        try {
            $this->buatSpp('SPP-SD-2', '350000', 'SD');
            $this->fail('harus 409');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('sudah ada di jenis biaya "SPP-SD"', $e->getMessage());
        }

        // Baris UMUM juga hanya boleh satu…
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->expectException(AppException::class);
        $this->buatSpp('SPP-UMUM-2', '260000', null);
    }

    public function test_baris_nonaktif_tidak_menghalangi_dan_tidak_dipakai(): void
    {
        $this->buatSpp('SPP-SD-LAMA', '200000', 'SD', 'nonaktif');
        $this->buatSpp('SPP-SD', '300000', 'SD'); // tak bentrok dengan yang nonaktif

        $hasil = (new SppService)->nominalSppSantri($this->santriAktif('SD')->id);
        $this->assertSame('SPP-SD', $hasil['kode_jenis']);
        $this->assertSame('300000.00', $hasil['nominal']);
    }

    public function test_tanpa_tarif_memberi_pesan_yang_menuntun(): void
    {
        $santri = $this->santriAktif('SD');

        try {
            (new SppService)->nominalSppSantri($santri->id);
            $this->fail('harus 422');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('PPSB → Jenis Biaya', $e->getMessage());
        }
    }

    public function test_halaman_spp_tak_lagi_punya_seksi_tarif(): void
    {
        $this->actingAs(User::first())
            ->get(route('spp.index'))
            ->assertOk()
            ->assertDontSee('Tarif SPP per Jenjang')
            ->assertSee('Terbitkan Tagihan SPP')
            ->assertSee('diatur di'); // penunjuk ke master Jenis Biaya
    }
}
