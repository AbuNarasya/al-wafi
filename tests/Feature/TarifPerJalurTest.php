<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
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

/**
 * Tarif dibedakan per JALUR, bukan cuma per jenjang.
 *
 * Latar: dua baris uang pangkal SMP (jalur OSS 70jt & reguler 50jt) dulu tak
 * terbedakan — yang terpilih baris pertama urut kode, sehingga calon SMP
 * reguler ditawari tarif OSS. Kolom kode_jalur + JenisBiaya::berlaku() menutup
 * celah itu; penjaga cakupan tunggal mencegah celahnya terbuka lagi.
 */
class TarifPerJalurTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZJL';
    private const PEND = '4.ZZJL.PEND';
    private const PIUT = '1.ZZJL.PIUT';
    private const UNIT = 'ZZJLU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'JL']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        foreach ([['REG', 'Reguler'], ['OSS', 'SMP (OSS)'], ['YTM', 'Beasiswa Yatim']] as [$kode, $nama]) {
            JalurPendaftaran::create(['kode' => $kode, 'nama' => $nama, 'tahun_ajaran' => self::TA]);
        }
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SD Tahfizh', 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

        // Registrasi dasar agar pendaftaran calon bisa jalan.
        $this->buatJenis('REG-UMUM', 'registrasi', '500000', null, null);
    }

    private function buatJenis(string $kode, string $tipe, ?string $nominal, ?string $jenjang, ?string $jalur): JenisBiaya
    {
        return (new JenisBiayaService)->create([
            'kode' => $kode, 'nama' => $kode, 'tipe' => $tipe, 'nominal' => $nominal,
            'kode_jenjang' => $jenjang, 'kode_jalur' => $jalur,
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => $tipe === 'registrasi' ? null : self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function calonLulus(string $jenjang, string $jalur): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => $jalur, 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);

        return $santri->refresh();
    }

    /** KASUS ASLI: SMP punya tarif dasar 50jt + tarif khusus jalur OSS 70jt. */
    public function test_tarif_jalur_khusus_tidak_bocor_ke_jalur_lain(): void
    {
        // Kode OSS sengaja lebih kecil dari kode REG secara abjad — dulu ini yang
        // membuat OSS "menang" untuk semua calon SMP.
        $this->buatJenis('UP-SMP-OSS', 'uang_pangkal', '70000000', 'SMP', 'OSS');
        $this->buatJenis('UP-SMP-REG', 'uang_pangkal', '50000000', 'SMP', null);
        $svc = new SantriService;

        $this->assertSame(70000000.0, (float) $svc->jenisUangPangkal(self::TA, 'SMP', 'OSS')->nominal);
        $this->assertSame(50000000.0, (float) $svc->jenisUangPangkal(self::TA, 'SMP', 'REG')->nominal);
        // Jalur lain yang tak punya baris sendiri ikut tarif dasar, bukan tarif OSS.
        $this->assertSame(50000000.0, (float) $svc->jenisUangPangkal(self::TA, 'SMP', 'YTM')->nominal);
    }

    /** Baris berjalur tanpa jenjang = pengecualian lintas jenjang, menang atas tarif dasar jenjang. */
    public function test_jalur_lintas_jenjang_menang_atas_tarif_dasar_jenjang(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', '50000000', 'SMP', null);
        $this->buatJenis('UP-YATIM', 'uang_pangkal', '1000000', null, 'YTM');
        $svc = new SantriService;

        $this->assertSame('UP-YATIM', $svc->jenisUangPangkal(self::TA, 'SMP', 'YTM')->kode);
        $this->assertSame('UP-SMP', $svc->jenisUangPangkal(self::TA, 'SMP', 'REG')->kode);
    }

    public function test_fallback_ke_baris_umum_saat_tak_ada_baris_khusus(): void
    {
        $this->buatJenis('UP-UMUM', 'uang_pangkal', '15000000', null, null);
        $this->buatJenis('UP-SMP-OSS', 'uang_pangkal', '70000000', 'SMP', 'OSS');
        $svc = new SantriService;

        $this->assertSame('UP-UMUM', $svc->jenisUangPangkal(self::TA, 'SDTQ', 'REG')->kode);
        $this->assertSame('UP-UMUM', $svc->jenisUangPangkal(self::TA, 'SMP', 'REG')->kode);
        $this->assertSame('UP-SMP-OSS', $svc->jenisUangPangkal(self::TA, 'SMP', 'OSS')->kode);
    }

    /** Tak ada baris yang cocok → GAGAL dengan pesan menuntun, bukan diam-diam memakai tarif jenjang lain. */
    public function test_tanpa_baris_cocok_gagal_bukan_asal_pilih(): void
    {
        $this->buatJenis('UP-SMP-OSS', 'uang_pangkal', '70000000', 'SMP', 'OSS');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('jenjang "SDTQ" jalur "REG"');
        (new SantriService)->jenisUangPangkal(self::TA, 'SDTQ', 'REG');
    }

    public function test_dua_baris_bercakupan_sama_ditolak_beda_jalur_diterima(): void
    {
        $this->buatJenis('UP-SMP-A', 'uang_pangkal', '50000000', 'SMP', null);

        // Cakupan persis sama (SMP, semua jalur) → ditolak.
        try {
            $this->buatJenis('UP-SMP-B', 'uang_pangkal', '60000000', 'SMP', null);
            $this->fail('Baris kedua dengan cakupan sama seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('UP-SMP-A', $e->getMessage());
        }

        // Dibedakan jalurnya → boleh.
        $this->buatJenis('UP-SMP-OSS', 'uang_pangkal', '70000000', 'SMP', 'OSS');
        $this->assertSame(2, JenisBiaya::where('tipe', 'uang_pangkal')->count());
    }

    public function test_registrasi_dan_spp_ikut_aturan_jalur_yang_sama(): void
    {
        $this->buatJenis('REG-SMP-OSS', 'registrasi', '1500000', 'SMP', 'OSS');
        $this->buatJenis('SPP-SMP', 'spp', '4200000', 'SMP', null);
        $this->buatJenis('SPP-SMP-OSS', 'spp', '6000000', 'SMP', 'OSS');

        // Registrasi terbit otomatis saat mendaftar → nominalnya ikut jalur.
        $calonOss = $this->calonLulus('SMP', 'OSS');
        $this->assertSame(1500000.0, (float) $calonOss->tagihan()->first()->nominal);
        $calonReg = $this->calonLulus('SMP', 'REG');
        $this->assertSame(500000.0, (float) $calonReg->tagihan()->first()->nominal); // REG-UMUM

        $spp = new SppService;
        $this->assertSame('SPP-SMP-OSS', $spp->jenisSppSantri('SMP', self::TA, 'OSS')->kode);
        $this->assertSame('SPP-SMP', $spp->jenisSppSantri('SMP', self::TA, 'REG')->kode);
    }

    /** Form "Tagihkan Uang Pangkal" terisi tarif jalur calon yang bersangkutan. */
    public function test_form_penagihan_terisi_tarif_sesuai_jalur(): void
    {
        $this->buatJenis('UP-SMP-OSS', 'uang_pangkal', '70000000', 'SMP', 'OSS');
        $this->buatJenis('UP-SMP-REG', 'uang_pangkal', '50000000', 'SMP', null);

        $this->actingAs(User::find($this->admin))
            ->get(route('santri.show', $this->calonLulus('SMP', 'REG')->id))
            ->assertOk()
            ->assertSee('value="50000000.00"', false)
            ->assertDontSee('value="70000000.00"', false);

        $this->actingAs(User::find($this->admin))
            ->get(route('santri.show', $this->calonLulus('SMP', 'OSS')->id))
            ->assertOk()
            ->assertSee('value="70000000.00"', false);
    }

    /** Tagihan dicari lewat TIPE, jadi baris master lain tak membuatnya "hilang". */
    public function test_tagihan_uang_pangkal_tetap_ditemukan_meski_master_banyak_baris(): void
    {
        $this->buatJenis('UP-SMP-OSS', 'uang_pangkal', '70000000', 'SMP', 'OSS');
        $this->buatJenis('UP-SMP-REG', 'uang_pangkal', '50000000', 'SMP', null);

        $santri = $this->calonLulus('SMP', 'REG');
        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '50000000']);
        $this->assertSame('UP-SMP-REG', $tagihan->kode_jenis);

        // detail() dulu menebak baris master lebih dulu (urut kode → UP-SMP-OSS)
        // lalu mencari tagihan ber-kode_jenis itu → tagihan yang ADA dianggap
        // "tidak ditemukan". Sekarang dicari lewat tipe tagihannya.
        $detail = (new \App\Services\Modules\AngsuranUangPangkalService)->detail($santri->id);
        $this->assertSame($santri->id, $detail['id_santri']);
        $this->assertSame('50000000.00', $detail['total']);
    }
}
