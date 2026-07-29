<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nominal uang pangkal di master = DEFAULT: mengisi form penagihan, tetap bisa diubah. */
class NominalDefaultUangPangkalTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZND';
    private const PEND = '4.ZZND.PEND';
    private const PIUT = '1.ZZND.PIUT';
    private const UNIT = 'ZZNDU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'ND']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'Sekolah Menengah Pertama', 'urutan' => 2]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

        (new JenisBiayaService)->create(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    private function buatUangPangkal(string $kode, ?string $nominal, ?string $jenjang): void
    {
        (new JenisBiayaService)->create([
            'kode' => $kode, 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => $nominal,
            'kode_jenjang' => $jenjang, 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function calonLulus(?string $jenjang): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);

        return $santri->refresh();
    }

    public function test_master_per_jenjang_dipakai_lalu_fallback_umum(): void
    {
        $this->buatUangPangkal('UP-UMUM', '15000000', null);
        $this->buatUangPangkal('UP-SMP', '25000000', 'SMP');
        $svc = new SantriService;

        // Jenjang punya baris sendiri → dipakai.
        $this->assertSame('UP-SMP', $svc->jenisUangPangkal(self::TA, 'SMP')->kode);
        $this->assertSame(25000000.0, (float) $svc->jenisUangPangkal(self::TA, 'SMP')->nominal);

        // Jenjang tanpa baris sendiri → jatuh ke UMUM.
        $this->assertSame('UP-UMUM', $svc->jenisUangPangkal(self::TA, 'SD')->kode);
        // Tanpa jenjang sama sekali → UMUM.
        $this->assertSame('UP-UMUM', $svc->jenisUangPangkal(self::TA, null)->kode);
    }

    public function test_form_penagihan_terisi_nominal_default(): void
    {
        $this->buatUangPangkal('UP-UMUM', '18000000', null);
        $santri = $this->calonLulus('SD');

        $this->actingAs(User::find($this->admin))
            ->get(route('santri.show', $santri->id))
            ->assertOk()
            ->assertSee('value="18000000.00"', false)
            ->assertSee('Terisi dari master Jenis Biaya');
    }

    public function test_nominal_default_tetap_bisa_diubah_saat_menagih(): void
    {
        $this->buatUangPangkal('UP-UMUM', '18000000', null);
        $santri = $this->calonLulus('SD');

        // Petugas mengetik angka lain → yang dipakai angka petugas, bukan master.
        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '12000000'])['uang_pangkal'];

        $this->assertSame(12000000.0, (float) $tagihan->nominal);
    }

    public function test_master_tanpa_nominal_form_tetap_kosong_dan_alur_lama_jalan(): void
    {
        $this->buatUangPangkal('UP-UMUM', null, null); // nominal sengaja dikosongkan
        $santri = $this->calonLulus('SD');

        $this->actingAs(User::find($this->admin))
            ->get(route('santri.show', $santri->id))
            ->assertOk()
            ->assertDontSee('Terisi dari master Jenis Biaya');

        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '9000000'])['uang_pangkal'];
        $this->assertSame(9000000.0, (float) $tagihan->nominal);
    }

    public function test_daftar_ulang_menemukan_tagihan_meski_master_berubah(): void
    {
        // Tagihan terbit memakai baris UMUM…
        $this->buatUangPangkal('UP-UMUM', '10000000', null);
        $santri = $this->calonLulus('SD');
        $svc = new SantriService;
        $tagihan = $svc->tagihkanUangPangkal($santri->id, ['nominal' => '10000000'])['uang_pangkal'];

        // …lalu admin menambah baris khusus jenjang SD (master berubah setelah tagihan terbit).
        $this->buatUangPangkal('UP-SD', '30000000', 'SD');

        $svc->medcheck($santri->id, ['lolos' => true]);
        $svc->daftarUlang($santri->id, $this->admin);

        // Tagihan lama tetap ditemukan & terakrual, bukan dianggap belum ditagihkan.
        $this->assertTrue((bool) TagihanSantri::find($tagihan->id)->sudah_akrual);
        $this->assertSame('aktif', $santri->refresh()->status);
    }
}
