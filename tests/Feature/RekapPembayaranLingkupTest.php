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
use App\Services\Modules\RekapPembayaranService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Rekap Pembayaran Santri berdiri di DUA menu, dan sejak kini isinya berbeda.
 *
 * Dulu keduanya menunjuk URL yang sama persis, sehingga menu PPSB menampilkan
 * seluruh santri — termasuk yang uang pangkalnya lunas bertahun lalu dan sudah
 * tak ada urusannya dengan PPSB.
 */
class RekapPembayaranLingkupTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZRK';

    private const PEND = '4.ZZRK.PEND';

    private const PIUT = '1.ZZRK.PIUT';

    private const UNIT = 'ZZRKU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'RK']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '10000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    private function santri(string $nama): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tingkat' => 1]);

        return $santri->refresh();
    }

    /** Terbitkan tagihan uang pangkal langsung, lalu tandai lunas bila diminta. */
    private function uangPangkal(Santri $s, bool $lunas): TagihanSantri
    {
        return TagihanSantri::create([
            'id_santri' => $s->id, 'kode_jenis' => 'UP', 'perilaku' => 'uang_pangkal',
            'kode_jenjang' => $s->kode_jenjang, 'tahun_ajaran' => $s->tahun_ajaran,
            'nominal' => '10000000', 'sisa' => $lunas ? '0' : '10000000',
            'status' => $lunas ? 'lunas' : 'belum_bayar',
        ]);
    }

    /** Lingkup PPSB hanya memuat yang kewajibannya masih menggantung. */
    public function test_ppsb_hanya_memuat_yang_masih_punya_kewajiban(): void
    {
        $menunggak = $this->santri('Ahmad Menunggak');
        $this->uangPangkal($menunggak, lunas: false);

        $lunas = $this->santri('Budi Lunas');
        $this->uangPangkal($lunas, lunas: true);

        $svc = new RekapPembayaranService;

        $ppsb = $svc->opsiSantri('', 'ppsb')->pluck('nama')->all();
        $this->assertSame(['Ahmad Menunggak'], $ppsb);

        // Kependidikan tetap memuat keduanya — riwayatnya tak pernah hilang.
        $semua = $svc->opsiSantri('', 'semua')->pluck('nama')->all();
        $this->assertContains('Ahmad Menunggak', $semua);
        $this->assertContains('Budi Lunas', $semua);
    }

    /** Perlengkapan sendirian pun cukup menahan santrinya di lingkup PPSB. */
    public function test_perlengkapan_yang_masih_menggantung_ikut_menahan(): void
    {
        $this->buatBiaya(['kode' => 'PLK', 'nama' => 'Perlengkapan', 'tipe' => 'perlengkapan', 'nominal' => '2000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);

        $s = $this->santri('Citra');
        $this->uangPangkal($s, lunas: true);
        TagihanSantri::create([
            'id_santri' => $s->id, 'kode_jenis' => 'PLK', 'perilaku' => 'perlengkapan',
            'kode_jenjang' => $s->kode_jenjang, 'tahun_ajaran' => $s->tahun_ajaran,
            'nominal' => '2000000', 'sisa' => '2000000', 'status' => 'belum_bayar',
        ]);

        $this->assertSame(['Citra'], (new RekapPembayaranService)->opsiSantri('', 'ppsb')->pluck('nama')->all());
    }

    /**
     * SPP yang menunggak TIDAK menahan santrinya di lingkup PPSB — itu tagihan
     * berulang dan urusan Kependidikan.
     */
    public function test_tunggakan_spp_tidak_menahan_di_lingkup_ppsb(): void
    {
        $this->buatBiaya(['kode' => 'SPP', 'nama' => 'SPP', 'tipe' => 'spp', 'nominal' => '250000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'berulang' => true]);

        $s = $this->santri('Dewi');
        $this->uangPangkal($s, lunas: true);
        TagihanSantri::create([
            'id_santri' => $s->id, 'kode_jenis' => 'SPP', 'perilaku' => 'spp', 'periode' => '2026-07',
            'kode_jenjang' => $s->kode_jenjang, 'tahun_ajaran' => $s->tahun_ajaran,
            'nominal' => '250000', 'sisa' => '250000', 'status' => 'belum_bayar', 'sudah_akrual' => true,
        ]);

        $this->assertSame([], (new RekapPembayaranService)->opsiSantri('', 'ppsb')->pluck('nama')->all());
    }

    /** Dua menu, dua URL — bukan lagi halaman yang sama persis. */
    public function test_dua_menu_menunjuk_url_berbeda(): void
    {
        $items = collect(\App\Support\Navigation::ITEMS)
            ->where('label', 'Rekap Pembayaran Santri')
            ->values();

        $this->assertCount(2, $items);
        $this->assertSame('/ppsb/rekap-pembayaran', $items->firstWhere('group', 'PPSB')['url']);
        $this->assertSame('/rekap-pembayaran', $items->firstWhere('group', 'KEPENDIDIKAN')['url']);
    }

    /** Alur HTTP: kedua halaman terbuka & isinya memang berbeda. */
    public function test_alur_http_kedua_lingkup(): void
    {
        $menunggak = $this->santri('Ahmad Menunggak');
        $this->uangPangkal($menunggak, lunas: false);
        $lunas = $this->santri('Budi Lunas');
        $this->uangPangkal($lunas, lunas: true);
        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('rekap_pembayaran.ppsb'))->assertOk()
            ->assertSee('masih punya kewajiban uang pangkal atau perlengkapan', false)
            ->assertSee('Ahmad Menunggak')
            ->assertDontSee('Budi Lunas');

        $this->actingAs($admin)->get(route('rekap_pembayaran.index'))->assertOk()
            ->assertSee('Ahmad Menunggak')
            ->assertSee('Budi Lunas');
    }
}
