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
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TarifBiaya;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\TarifService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grid Tarif (biaya MASUK: registrasi, uang pangkal, perlengkapan) + pengaman
 * anti tagih-ganda. Penerbitan massal & daftar ulang diuji di DaftarUlangTest.
 *
 * Inti yang dijaga di sini: TIGA KEADAAN sel (angka / bebas / belum diisi) harus
 * tetap berbeda artinya, dan santri tak boleh bisa ditagih dua kali untuk
 * (jenjang, tahun ajaran) yang sama betapapun tombolnya ditekan.
 */
class GridTarifTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZGT';
    private const PEND = '4.ZZGT.PEND';
    private const PIUT = '1.ZZGT.PIUT';
    private const UNIT = 'ZZGTU';
    private const TA = '2026/2027';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'GT']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        JalurPendaftaran::create(['kode' => 'REG', 'nama' => 'Reguler']);
        JalurPendaftaran::create(['kode' => 'OSS', 'nama' => 'Lanjutan (OSS)', 'bebas_uang_pangkal' => true]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        $svc = new JenisBiayaService;
        foreach ([['REG-SMP', 'registrasi', null], ['UP-SMP', 'uang_pangkal', self::PIUT], ['PLK-SMP', 'perlengkapan', self::PIUT]] as [$kode, $tipe, $piutang]) {
            $svc->create(['kode' => $kode, 'nama' => $kode, 'tipe' => $tipe, 'kode_jenjang' => 'SMP',
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => $piutang, 'kode_unit' => self::UNIT]);
        }
        // Registrasi wajib ada tarifnya supaya pendaftaran calon bisa jalan.
        (new TarifService)->simpan(self::TA, 'SMP', ['-' => ['registrasi' => ['nominal' => '500000']]]);
    }

    private function calon(string $jalur, string $nama = 'Ahmad'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L', 'gelombang' => 1,
            'tahun_ajaran' => self::TA, 'jalur' => $jalur, 'kode_jenjang' => 'SMP', 'tingkat' => 1,
        ]);
        $santri->update(['status' => 'diterima']);

        return $santri->refresh();
    }

    // ---- Grid: tiga keadaan sel ----

    public function test_menyimpan_grid_membedakan_angka_bebas_dan_kosong(): void
    {
        $svc = new TarifService;
        $svc->simpan(self::TA, 'SMP', [
            '-' => ['uang_pangkal' => ['nominal' => '50000000']],
            'OSS' => ['uang_pangkal' => ['nominal' => '', 'bebas' => true]],
            'REG' => ['uang_pangkal' => ['nominal' => '']], // sengaja dibiarkan kosong
        ]);

        $this->assertSame('ada', $svc->cari('uang_pangkal', self::TA, 'SMP', 'REG')['status'], 'jalur tanpa sel sendiri ikut baris Umum');
        $this->assertSame('bebas', $svc->cari('uang_pangkal', self::TA, 'SMP', 'OSS')['status']);
        // Sel kosong TIDAK disimpan sebagai baris bernominal nol — nol itu angka
        // yang sah (gratis tapi tetap terbit) dan tak boleh tertukar dengan bebas.
        $this->assertNull(TarifBiaya::where('kode_jalur', 'REG')->first());
    }

    public function test_sel_dikosongkan_kembali_menghapus_barisnya(): void
    {
        $svc = new TarifService;
        $svc->simpan(self::TA, 'SMP', ['-' => ['uang_pangkal' => ['nominal' => '50000000']]]);
        $this->assertSame('ada', $svc->cari('uang_pangkal', self::TA, 'SMP', null)['status']);

        $svc->simpan(self::TA, 'SMP', ['-' => ['uang_pangkal' => ['nominal' => '']]]);
        $this->assertSame('kosong', $svc->cari('uang_pangkal', self::TA, 'SMP', null)['status']);
    }

    public function test_salin_antar_tahun_ajaran_tidak_menimpa_yang_sudah_diisi(): void
    {
        $svc = new TarifService;
        $svc->simpan(self::TA, 'SMP', [
            '-' => ['uang_pangkal' => ['nominal' => '50000000'], 'perlengkapan' => ['nominal' => '13000000']],
            'OSS' => ['uang_pangkal' => ['bebas' => true]],
        ]);
        // T.A tujuan sudah punya penyesuaian sendiri untuk uang pangkal.
        $svc->simpan('2027/2028', 'SMP', ['-' => ['uang_pangkal' => ['nominal' => '55000000']]]);

        $hasil = $svc->salin(self::TA, '2027/2028', 'SMP');

        // Tersalin: registrasi (dari setUp), perlengkapan, & sel bebas OSS.
        $this->assertSame(3, $hasil['disalin']);
        $this->assertSame(1, $hasil['dilewati'], 'uang pangkal yang sudah disesuaikan tidak ditimpa');
        $this->assertSame('55000000.00', $svc->cari('uang_pangkal', '2027/2028', 'SMP', null)['nominal']);
        $this->assertSame('bebas', $svc->cari('uang_pangkal', '2027/2028', 'SMP', 'OSS')['status']);
    }

    public function test_halaman_tarif_menampilkan_grid_dan_menyimpan(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tarif.index', ['ta' => self::TA, 'jenjang' => 'SMP']))
            ->assertOk()
            ->assertSee('Umum (semua jalur)')
            ->assertSee('OSS')
            ->assertSee('Uang Pangkal');

        $this->actingAs($this->admin)->put(route('tarif.simpan'), [
            'tahun_ajaran' => self::TA, 'kode_jenjang' => 'SMP',
            'sel' => ['-' => ['uang_pangkal' => ['nominal' => '50000000', 'bebas' => '0']]],
        ])->assertRedirect();

        $this->assertSame('50000000.00', (new TarifService)->cari('uang_pangkal', self::TA, 'SMP', null)['nominal']);
    }

    // ---- Penjagaan OSS & anti tagih-ganda ----

    public function test_sel_bebas_menghalangi_uang_pangkal_tapi_perlengkapan_tetap_terbit(): void
    {
        $svc = new TarifService;
        $svc->simpan(self::TA, 'SMP', [
            '-' => ['uang_pangkal' => ['nominal' => '50000000'], 'perlengkapan' => ['nominal' => '13000000']],
            'OSS' => ['uang_pangkal' => ['bebas' => true]],
        ]);

        $hasil = (new SantriService)->tagihkanUangPangkal($this->calon('OSS')->id, ['nominal_perlengkapan' => '13000000']);

        $this->assertNull($hasil['uang_pangkal']);
        $this->assertNotNull($hasil['perlengkapan']);
        $this->assertSame(13000000.0, (float) $hasil['perlengkapan']->nominal);
    }

    public function test_tagihan_kedua_untuk_jenjang_dan_ta_sama_ditolak_penjaga(): void
    {
        (new TarifService)->simpan(self::TA, 'SMP', ['-' => ['uang_pangkal' => ['nominal' => '50000000']]]);
        $santri = $this->calon('REG');
        $svc = new SantriService;
        $svc->tagihkanUangPangkal($santri->id, ['nominal' => '50000000']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah pernah ditagihkan');
        $svc->tagihkanUangPangkal($santri->id, ['nominal' => '50000000']);
    }

    /**
     * Pengaman KERAS: bahkan bila penjaga di service dilewati (mis. dua proses
     * menulis bersamaan), basis data menolak baris keduanya.
     */
    public function test_indeks_unik_menolak_tagihan_ganda_di_tingkat_basis_data(): void
    {
        (new TarifService)->simpan(self::TA, 'SMP', ['-' => ['uang_pangkal' => ['nominal' => '50000000']]]);
        $santri = $this->calon('REG');
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '50000000']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        TagihanSantri::create([
            'id_santri' => $santri->id, 'kode_jenis' => 'UP-SMP', 'perilaku' => 'uang_pangkal',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => '1', 'sisa' => '1', 'status' => 'belum_bayar',
        ]);
    }

    /** Naik jenjang MEMANG menagih uang pangkal lagi — yang dilarang cuma jenjang & T.A yang sama. */
    public function test_jenjang_berbeda_boleh_ditagih_uang_pangkal_lagi(): void
    {
        (new JenisBiayaService)->create(['kode' => 'UP-SMA', 'nama' => 'UP SMA', 'tipe' => 'uang_pangkal',
            'kode_jenjang' => 'SMA', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
        $svc = new TarifService;
        $svc->simpan(self::TA, 'SMP', ['-' => ['uang_pangkal' => ['nominal' => '50000000']]]);
        $svc->simpan(self::TA, 'SMA', ['-' => ['uang_pangkal' => ['nominal' => '20000000']]]);

        $santri = $this->calon('REG');
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '50000000']);

        // Naik ke SMA lalu ditagih lagi dengan tarif jenjang tujuan.
        $santri->update(['status' => 'aktif', 'kode_jenjang' => 'SMA', 'tingkat' => 1]);
        $kedua = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '20000000'])['uang_pangkal'];

        $this->assertSame('SMA', $kedua->kode_jenjang);
        $this->assertSame(2, TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'uang_pangkal')->count());
    }
}
