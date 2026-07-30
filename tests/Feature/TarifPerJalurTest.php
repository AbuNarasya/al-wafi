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
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\TarifService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tarif dibedakan per JALUR — kini lewat grid `tarif_biaya`, bukan lagi lewat
 * baris master jenis biaya.
 *
 * Latar aslinya: dua baris uang pangkal SMP (jalur OSS 70jt & reguler 50jt) tak
 * terbedakan, yang terpilih baris pertama urut kode, sehingga calon SMP reguler
 * ditawari tarif OSS. Sejak tarif pindah ke grid, yang membedakan adalah SEL —
 * dan sel yang tidak ada berarti "belum diisi", bukan "pakai yang mana saja".
 */
class TarifPerJalurTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

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
            JalurPendaftaran::create(['kode' => $kode, 'nama' => $nama]);
        }
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SD Tahfizh', 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

        // Registrasi dasar agar pendaftaran calon bisa jalan (semua jenjang, 500rb).
        $this->buatBiaya([
            'kode' => 'REG-UMUM', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    /** Satu baris identitas akuntansi per (perilaku, jenjang) — tanpa nominal. */
    private function buatJenis(string $kode, string $tipe, ?string $jenjang): JenisBiaya
    {
        return (new \App\Services\Modules\JenisBiayaService)->create([
            'kode' => $kode, 'nama' => $kode, 'tipe' => $tipe, 'kode_jenjang' => $jenjang,
            'kode_coa_pendapatan' => self::PEND,
            'kode_coa_piutang' => $tipe === 'registrasi' ? null : self::PIUT,
            'kode_unit' => self::UNIT,
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

    /** KASUS ASLI: SMP punya baris Umum 50jt + sel khusus jalur OSS 70jt. */
    public function test_sel_jalur_khusus_tidak_bocor_ke_jalur_lain(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', 'OSS', 'uang_pangkal', '70000000');
        $this->pasangTarif(self::TA, 'SMP', null, 'uang_pangkal', '50000000');
        $tarif = new TarifService;

        $this->assertSame('70000000.00', $tarif->cari('uang_pangkal', self::TA, 'SMP', 'OSS')['nominal']);
        $this->assertSame('50000000.00', $tarif->cari('uang_pangkal', self::TA, 'SMP', 'REG')['nominal']);
        // Jalur yang tak punya selnya sendiri ikut baris Umum, bukan sel OSS.
        $this->assertSame('50000000.00', $tarif->cari('uang_pangkal', self::TA, 'SMP', 'YTM')['nominal']);
        // Asal angkanya selalu bisa disebut — inilah yang dulu tak terlihat.
        $this->assertStringContainsString('jalur OSS', $tarif->cari('uang_pangkal', self::TA, 'SMP', 'OSS')['asal']);
        $this->assertStringContainsString('baris Umum', $tarif->cari('uang_pangkal', self::TA, 'SMP', 'YTM')['asal']);
    }

    /** Jenjang harus COCOK PERSIS — sel SMP tak boleh melayani calon SDTQ. */
    public function test_jenjang_tidak_saling_meminjam_tarif(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', null, 'uang_pangkal', '50000000');
        $tarif = new TarifService;

        $this->assertSame('ada', $tarif->cari('uang_pangkal', self::TA, 'SMP', 'REG')['status']);
        $this->assertSame('kosong', $tarif->cari('uang_pangkal', self::TA, 'SDTQ', 'REG')['status']);
    }

    /** Tahun ajaran juga harus cocok persis. */
    public function test_tahun_ajaran_tidak_saling_meminjam_tarif(): void
    {
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', null, 'uang_pangkal', '50000000');

        $this->assertSame('kosong', (new TarifService)->cari('uang_pangkal', '2027/2028', 'SMP', 'REG')['status']);
    }

    /** Sel kosong → GAGAL dengan pesan menuntun, bukan diam-diam memakai tarif lain. */
    public function test_tanpa_sel_gagal_bukan_asal_pilih(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', 'OSS', 'uang_pangkal', '70000000');
        $santri = $this->calonLulus('SMP', 'REG');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('belum diisi');
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '50000000']);
    }

    /**
     * SEL BERTANDA BEBAS: tagihannya TIDAK terbit sama sekali — inilah yang
     * menjaga santri OSS lanjutan & anak karyawan tak ditagih uang pangkal lagi.
     * Bedanya dengan sel kosong: bebas berjalan mulus, kosong menghentikan.
     */
    public function test_sel_bebas_tidak_menerbitkan_tagihan_uang_pangkal(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', null, 'uang_pangkal', '50000000');
        $this->pasangTarif(self::TA, 'SMP', 'OSS', 'uang_pangkal', null, bebas: true);

        $hasil = (new SantriService)->tagihkanUangPangkal($this->calonLulus('SMP', 'OSS')->id, []);

        $this->assertNull($hasil['uang_pangkal'], 'jalur bebas tak boleh menerbitkan tagihan uang pangkal');
        $this->assertSame(0, TagihanSantri::where('perilaku', 'uang_pangkal')->count());
    }

    /** Dua baris identitas untuk (perilaku, jenjang) yang sama tetap ditolak. */
    public function test_dua_baris_jenis_biaya_sejenjang_ditolak(): void
    {
        $this->buatJenis('UP-SMP-A', 'uang_pangkal', 'SMP');

        try {
            $this->buatJenis('UP-SMP-B', 'uang_pangkal', 'SMP');
            $this->fail('Baris kedua untuk jenjang yang sama seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('UP-SMP-A', $e->getMessage());
        }

        // Jenjang lain tetap boleh punya barisnya sendiri.
        $this->buatJenis('UP-SDTQ', 'uang_pangkal', 'SDTQ');
        $this->assertSame(2, JenisBiaya::where('tipe', 'uang_pangkal')->count());
    }

    public function test_registrasi_dan_spp_ikut_aturan_jalur_yang_sama(): void
    {
        $this->buatJenis('REG-SMP', 'registrasi', 'SMP');
        $this->buatJenis('SPP-SMP', 'spp', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', 'OSS', 'registrasi', '1500000');
        $this->pasangTarif(self::TA, 'SMP', null, 'registrasi', '500000');
        $this->pasangTarif(self::TA, 'SMP', null, 'spp', '4200000');
        $this->pasangTarif(self::TA, 'SMP', 'OSS', 'spp', '6000000');

        // Registrasi terbit otomatis saat mendaftar → nominalnya ikut jalur.
        $this->assertSame(1500000.0, (float) $this->calonLulus('SMP', 'OSS')->tagihan()->first()->nominal);
        $this->assertSame(500000.0, (float) $this->calonLulus('SMP', 'REG')->tagihan()->first()->nominal);

        $spp = new SppService;
        $this->assertSame(6000000.0, (float) $spp->nominalSppSantri($this->santriAktif('SMP', 'OSS')->id)['nominal']);
        $this->assertSame(4200000.0, (float) $spp->nominalSppSantri($this->santriAktif('SMP', 'REG')->id)['nominal']);
    }

    /** Form "Tagihkan Uang Pangkal" terisi tarif jalur calon yang bersangkutan. */
    public function test_form_penagihan_terisi_tarif_sesuai_jalur(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->pasangTarif(self::TA, 'SMP', 'OSS', 'uang_pangkal', '70000000');
        $this->pasangTarif(self::TA, 'SMP', null, 'uang_pangkal', '50000000');

        $this->actingAs(User::find($this->admin))
            ->get(route('santri.show', $this->calonLulus('SMP', 'REG')->id))
            ->assertOk()
            ->assertSee('value="50000000.00"', false)
            ->assertDontSee('value="70000000.00"', false)
            // Asal tarifnya ikut tampil di layar.
            ->assertSee('baris Umum');

        $this->actingAs(User::find($this->admin))
            ->get(route('santri.show', $this->calonLulus('SMP', 'OSS')->id))
            ->assertOk()
            ->assertSee('value="70000000.00"', false);
    }

    /** Tagihan dicari lewat PERILAKU, jadi baris master lain tak membuatnya "hilang". */
    public function test_tagihan_uang_pangkal_tetap_ditemukan_meski_master_banyak_baris(): void
    {
        $this->buatJenis('UP-SMP', 'uang_pangkal', 'SMP');
        $this->buatJenis('UP-SDTQ', 'uang_pangkal', 'SDTQ');
        $this->pasangTarif(self::TA, 'SMP', null, 'uang_pangkal', '50000000');

        $santri = $this->calonLulus('SMP', 'REG');
        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '50000000'])['uang_pangkal'];
        $this->assertSame('UP-SMP', $tagihan->kode_jenis);
        $this->assertSame('SMP', $tagihan->kode_jenjang);
        $this->assertSame(self::TA, $tagihan->tahun_ajaran);

        $detail = (new \App\Services\Modules\AngsuranUangPangkalService)->detail($santri->id);
        $this->assertSame($santri->id, $detail['id_santri']);
        $this->assertSame('50000000.00', $detail['total']);
    }

    private function santriAktif(string $jenjang, string $jalur): Santri
    {
        $santri = $this->calonLulus($jenjang, $jalur);
        $santri->update(['status' => 'aktif']);

        return $santri->refresh();
    }
}
