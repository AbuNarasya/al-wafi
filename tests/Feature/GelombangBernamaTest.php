<?php

namespace Tests\Feature;

use App\Http\Controllers\SantriController;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\PotonganUangPangkal;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\PotonganGelombangService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MelunasiRegistrasi;
use Tests\Concerns\MembuatGelombang;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * GELOMBANG BERKODE TEKS + potongan yang DIPEROLEH dengan membayar registrasi.
 *
 * Tiga koreksi sekaligus, dan ketiganya bertemu di satu titik: nominal tagihan
 * uang pangkal.
 *
 *  1. Kode gelombang bebas ("Beasiswa Tahfizh"), bukan angka urut — memaksanya
 *     jadi 1/2/3 membuat nomornya kehilangan arti dan petugas harus menghafal
 *     nomor mana milik gelombang apa.
 *  2. Registrasi memilih gelombang dari daftar, bukan mengetik angkanya. Angka
 *     yang salah ketik diam-diam memindahkan calon ke gelombang lain — atau ke
 *     gelombang yang tak ada, sehingga potongannya hilang tanpa pesan.
 *  3. Potongan hanya diberikan bila registrasinya LUNAS, dan periodenya diukur
 *     pada TANGGAL PELUNASAN itu. Kalau diukur saat penerbitan uang pangkal,
 *     calon yang membayar tepat waktu kehilangan potongan hanya karena
 *     pengumuman kelulusan atau penagihannya tertunda.
 */
class GelombangBernamaTest extends TestCase
{
    use MelunasiRegistrasi;
    use MembuatGelombang;
    use MembuatTarif;
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZGB';

    private const PEND = '4.ZZGB.1';

    private const PIUT = '1.ZZGB.1';

    private const UNIT = 'ZZGBU';

    private const KODE = 'Beasiswa Tahfizh';

    private PotonganGelombangService $svc;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PotonganGelombangService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'GB']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUT, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'status' => 'aktif', 'jumlah_tingkat' => 3]);

        $this->admin = User::create(['username' => 'admgb', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_jenjang' => 'J002', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '20000000',
            'kode_jenjang' => 'J002', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    /**
     * Master + sel matriksnya. `$ganti` menerima kunci lama agar test di bawah
     * tetap terbaca: `gelombang`, `potongan`, `berlaku_mulai`, `berlaku_sampai`.
     */
    private function buatPotongan(array $ganti = []): PotonganGelombang
    {
        return $this->buatPotonganGelombang(
            self::TA,
            $ganti['gelombang'] ?? self::KODE,
            $ganti['kode_jenjang'] ?? 'J002',
            $ganti['potongan'] ?? '5000000',
            array_filter([
                'berlaku_mulai' => $ganti['berlaku_mulai'] ?? null,
                'berlaku_sampai' => $ganti['berlaku_sampai'] ?? null,
            ]),
        );
    }

    private function buatCalon(?string $gelombang = self::KODE): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'gelombang' => $gelombang,
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);
        $santri->update(['status' => 'diterima']);

        return $santri->refresh();
    }

    private function tagihkan(Santri $santri): float
    {
        return (float) (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '20000000'])['uang_pangkal']->nominal;
    }

    // ---- 1. Kode berupa teks ----

    public function test_kode_gelombang_boleh_berupa_nama(): void
    {
        $row = $this->buatPotongan();

        $this->assertSame(self::KODE, $row->gelombang);
        $this->assertSame(self::KODE, $this->svc->potonganAktif(self::KODE, 'J002', self::TA)?->gelombang);
    }

    public function test_kode_angka_tetap_sah(): void
    {
        $this->buatPotongan(['gelombang' => '1']);

        $this->assertNotNull($this->svc->potonganAktif('1', 'J002', self::TA));
    }

    /** "-" penanda Tanpa Gelombang di dropdown, jadi tak boleh jadi kode sungguhan. */
    public function test_kode_penanda_tanpa_gelombang_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('gelombang.store'), [
            'tahun_ajaran' => self::TA, 'kode' => SantriController::TANPA_GELOMBANG, 'nama' => 'Palsu',
            'masa_berlaku_hari' => 7, 'status' => 'aktif',
        ])->assertSessionHasErrors('kode');
    }

    // ---- 2. Dropdown registrasi ----

    public function test_form_registrasi_menawarkan_nama_gelombang(): void
    {
        $this->buatPotongan();

        $halaman = $this->actingAs($this->admin)->get(route('santri.create'))->assertOk();

        // Diperiksa sebagai <option>, bukan sekadar "namanya muncul di halaman".
        $halaman->assertSee('<option value="'.e(self::KODE).'"', false)
            ->assertSee('Tanpa Gelombang');
    }

    public function test_registrasi_menyimpan_kode_gelombang_yang_dipilih(): void
    {
        $this->buatPotongan();
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08123456']);

        $this->actingAs($this->admin)->post(route('santri.store'), [
            'id_wali' => $wali->id, 'nama' => 'Zaid', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'tahun_ajaran' => self::TA,
            'jalur' => 'reguler', 'gelombang' => self::KODE,
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::KODE, Santri::where('nama', 'Zaid')->first()->gelombang);
    }

    // ---- 3. Potongan diperoleh dengan membayar registrasi ----

    public function test_tanpa_pelunasan_registrasi_tidak_ada_potongan(): void
    {
        $this->buatPotongan();
        $santri = $this->buatCalon();

        $this->assertSame(20000000.0, $this->tagihkan($santri), 'terbit penuh, tanpa potongan');
        $this->assertSame(0, PotonganUangPangkal::count());
    }

    public function test_registrasi_lunas_memberi_potongan(): void
    {
        $this->buatPotongan();
        $santri = $this->buatCalon();
        $this->lunasiRegistrasi($santri->id);

        $this->assertSame(15000000.0, $this->tagihkan($santri)); // 20jt − 5jt
        $this->assertSame(self::KODE, PotonganUangPangkal::first()->gelombang);
    }

    /** Registrasi baru dibayar SEBAGIAN belum memenuhi syarat. */
    public function test_registrasi_belum_lunas_tidak_memberi_potongan(): void
    {
        $this->buatPotongan();
        $santri = $this->buatCalon();
        \App\Models\TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'registrasi')->update(['sisa' => '100000']);

        $this->assertSame(20000000.0, $this->tagihkan($santri));
    }

    /**
     * Periode diukur pada TANGGAL PELUNASAN REGISTRASI. Calon yang membayar di
     * dalam periode tetap dapat potongan walau uang pangkalnya baru terbit
     * berbulan-bulan kemudian.
     */
    public function test_periode_diukur_pada_tanggal_bayar_registrasi(): void
    {
        $this->buatPotongan(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);
        $santri = $this->buatCalon();
        $this->lunasiRegistrasi($santri->id, '2026-02-20');

        // Hari ini jauh di luar periode, tapi pembayarannya di dalam.
        $this->assertSame(15000000.0, $this->tagihkan($santri));
    }

    public function test_bayar_registrasi_di_luar_periode_tidak_dapat_potongan(): void
    {
        $this->buatPotongan(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);
        $santri = $this->buatCalon();
        $this->lunasiRegistrasi($santri->id, '2026-03-01');

        $this->assertSame(20000000.0, $this->tagihkan($santri));
    }

    /** Tanpa gelombang tetap tak pernah dapat potongan, walau registrasinya lunas. */
    public function test_tanpa_gelombang_tetap_tanpa_potongan(): void
    {
        $this->buatPotongan();
        $santri = $this->buatCalon(null);
        $this->lunasiRegistrasi($santri->id);

        $this->assertSame(20000000.0, $this->tagihkan($santri));
    }
}
