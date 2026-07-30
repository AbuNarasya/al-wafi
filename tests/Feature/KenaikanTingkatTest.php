<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Pendaftaran;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\KenaikanTingkatService as KT;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\TagihanMassalService;
use App\Services\Modules\TarifService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KENAIKAN TINGKAT & KELULUSAN MASSAL.
 *
 * Yang dijaga di sini:
 *  • naik memajukan tingkat DAN `tahun_ajaran_berjalan`, serta menulis riwayat —
 *    ini yang dulu bolong karena `set-tingkat` cuma mengubah satu kolom;
 *  • MENGULANG tetap memajukan tahun berjalan (tahunnya memang berganti), supaya
 *    tarif SPP-nya ikut tahun baru meski kelasnya sama;
 *  • kelulusan hanya di tingkat terakhir, dan tunggakan tak menghalanginya;
 *  • daftar ulang ditagih SESUDAH kenaikan, memakai tingkat yang BARU.
 */
class KenaikanTingkatTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZKT';
    private const PEND = '4.ZZKT.PEND';
    private const PIUT = '1.ZZKT.PIUT';
    private const UNIT = 'ZZKTU';
    private const TA1 = '2026/2027';
    private const TA2 = '2027/2028';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'KT']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA1, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => self::TA2, 'status' => 'aktif']);

        // J002 (SMP, 3 tingkat) punya lanjutan; J003 (SMA, 3 tingkat) jenjang terakhir.
        Jenjang::create(['kode' => 'J003', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3, 'kode_jenjang_lanjutan' => 'J003']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        $svc = new JenisBiayaService;
        foreach (['J002', 'J003'] as $j) {
            $svc->create(['kode' => "REG-{$j}", 'nama' => "Registrasi {$j}", 'tipe' => 'registrasi', 'kode_jenjang' => $j,
                'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT]);
            $svc->create(['kode' => "SPP-{$j}", 'nama' => "SPP {$j}", 'tipe' => 'spp', 'kode_jenjang' => $j, 'berulang' => true,
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
            $svc->create(['kode' => "DU-{$j}", 'nama' => "Daftar Ulang {$j}", 'tipe' => 'daftar_ulang', 'kode_jenjang' => $j,
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
        }

        $tarif = new TarifService;
        foreach ([self::TA1, self::TA2] as $ta) {
            $tarif->simpan($ta, 'J002', ['-' => ['registrasi' => ['nominal' => '500000']]]);
            $tarif->simpan($ta, 'J003', ['-' => ['registrasi' => ['nominal' => '500000']]]);
        }
        // SPP berubah tiap tahun — inilah yang membuktikan tahun berjalan dipakai.
        $tarif->simpanUmum(self::TA1, 'J002', ['spp' => ['nominal' => '1000000']]);
        $tarif->simpanUmum(self::TA2, 'J002', ['spp' => ['nominal' => '1200000']]);
        // Daftar ulang disimpan pada tingkat TUJUAN: 2 & 3.
        $tarif->simpanUmum(self::TA2, 'J002', ['daftar_ulang' => [2 => ['nominal' => '800000'], 3 => ['nominal' => '900000']]]);
    }

    private function santri(string $nama, int $tingkat, string $jenjang = 'J002', string $angkatan = self::TA1): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L', 'gelombang' => 1,
            'tahun_ajaran' => $angkatan, 'jalur' => '001', 'kode_jenjang' => $jenjang, 'tingkat' => $tingkat,
        ]);
        $santri->update(['status' => 'aktif', 'tahun_ajaran_berjalan' => $angkatan]);
        RiwayatTingkat::create(['id_santri' => $santri->id, 'tahun_ajaran' => $angkatan,
            'kode_jenjang' => $jenjang, 'tingkat' => $tingkat, 'catatan' => 'Fixture.']);

        return $santri->refresh();
    }

    // ---- Pratinjau & usulan ----

    public function test_usulan_mengikuti_tingkat_dan_jenjang_lanjutan(): void
    {
        $tengah = $this->santri('Tengah', 1);
        $akhirBerlanjut = $this->santri('Akhir SMP', 3);
        $akhirTerakhir = $this->santri('Akhir SMA', 3, 'J003');

        $smp = collect((new KT)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002'])['baris'])->keyBy('id');
        $sma = collect((new KT)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J003'])['baris'])->keyBy('id');

        // Belum di tingkat terakhir → naik.
        $this->assertSame(KT::NAIK, $smp[$tengah->id]['usul']);
        $this->assertSame([KT::NAIK, KT::MENGULANG, KT::LEWATI], $smp[$tengah->id]['pilihan']);

        // Tingkat terakhir & jenjangnya punya lanjutan → diarahkan ke Pendaftaran Lanjutan.
        $this->assertSame(KT::LEWATI, $smp[$akhirBerlanjut->id]['usul']);
        $this->assertStringContainsString('Pendaftaran Lanjutan', $smp[$akhirBerlanjut->id]['alasan']);
        $this->assertContains(KT::LULUS, $smp[$akhirBerlanjut->id]['pilihan'], 'yang tak melanjutkan tetap bisa diluluskan');

        // Tingkat terakhir jenjang TERAKHIR → lulus.
        $this->assertSame(KT::LULUS, $sma[$akhirTerakhir->id]['usul']);
    }

    public function test_santri_yang_sudah_di_ta_tujuan_dilewati(): void
    {
        $santri = $this->santri('Sudah Naik', 1);
        $santri->update(['tahun_ajaran_berjalan' => self::TA2]);

        $baris = (new KT)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002'])['baris'][0];

        $this->assertSame(KT::LEWATI, $baris['usul']);
        $this->assertSame([KT::LEWATI], $baris['pilihan'], 'tak ada pilihan lain — sudah pernah dijalankan');
        $this->assertStringContainsString('sudah pernah dijalankan', $baris['alasan']);
    }

    // ---- Eksekusi ----

    public function test_naik_memajukan_tingkat_tahun_berjalan_dan_riwayat(): void
    {
        $santri = $this->santri('Ahmad', 1);

        $hasil = (new KT)->eksekusi(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->assertSame(1, $hasil['naik']);
        $santri->refresh();
        $this->assertSame(2, $santri->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
        $this->assertSame(self::TA1, $santri->tahun_ajaran, 'angkatan tidak ikut maju');

        $riwayat = RiwayatTingkat::where('id_santri', $santri->id)->orderBy('tahun_ajaran')->get();
        $this->assertSame([self::TA1, self::TA2], $riwayat->pluck('tahun_ajaran')->all());
        $this->assertSame([1, 2], $riwayat->pluck('tingkat')->all());
    }

    /** MENGULANG: tingkat tetap, tapi tahun berjalan MAJU — tahunnya memang berganti. */
    public function test_mengulang_menahan_tingkat_tapi_tahun_tetap_maju(): void
    {
        $santri = $this->santri('Mengulang', 2);

        (new KT)->eksekusi(self::TA2, [$santri->id => KT::MENGULANG], $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame(2, $santri->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
        $this->assertSame('Mengulang di tingkat 2.',
            RiwayatTingkat::where('id_santri', $santri->id)->where('tahun_ajaran', self::TA2)->value('catatan'));
    }

    /**
     * LUBANG YANG DIPERBAIKI: tarif SPP santri yang naik tingkat (tanpa ganti
     * jenjang) dulu tetap memakai tarif tahun masuknya.
     */
    public function test_tarif_spp_ikut_tahun_baru_setelah_naik_tingkat(): void
    {
        $santri = $this->santri('Ahmad', 1);
        $this->assertSame(1000000.0, (float) (new SppService)->nominalSppSantri($santri->id)['nominal']);

        (new KT)->eksekusi(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->assertSame(1200000.0, (float) (new SppService)->nominalSppSantri($santri->id)['nominal']);
    }

    public function test_lulus_menjadi_alumni_dengan_tanggal(): void
    {
        $santri = $this->santri('Lulusan', 3, 'J003');

        (new KT)->eksekusi(self::TA2, [$santri->id => KT::LULUS], $this->admin->id_pengguna, ['tanggal_lulus' => '2028-06-20']);

        $santri->refresh();
        $this->assertSame('alumni', $santri->status);
        $this->assertSame('2028-06-20', $santri->tanggal_lulus->toDateString());
        // Siklus pendaftarannya ikut ditutup, tak tertinggal di "aktif".
        $this->assertSame('alumni', Pendaftaran::where('id_santri', $santri->id)->orderByDesc('id')->value('status'));
    }

    public function test_lulus_sebelum_tingkat_terakhir_ditolak(): void
    {
        $santri = $this->santri('Belum Waktunya', 1, 'J003');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('belum bisa diluluskan');
        (new KT)->eksekusi(self::TA2, [$santri->id => KT::LULUS], $this->admin->id_pengguna);
    }

    public function test_naik_dari_tingkat_terakhir_ditolak(): void
    {
        $santri = $this->santri('Tingkat Akhir', 3);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah di tingkat terakhir');
        (new KT)->eksekusi(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);
    }

    public function test_kenaikan_kedua_untuk_ta_sama_ditolak(): void
    {
        $santri = $this->santri('Ahmad', 1);
        $svc = new KT;
        $svc->eksekusi(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah berada di T.A');
        $svc->eksekusi(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);
    }

    /** Satu gagal → seluruh batch batal; angkatan yang naik separuh sulit dibereskan. */
    public function test_batch_gagal_membatalkan_seluruhnya(): void
    {
        $a = $this->santri('A', 1);
        $b = $this->santri('B', 3); // di tingkat terakhir → "naik" akan ditolak

        try {
            (new KT)->eksekusi(self::TA2, [$a->id => KT::NAIK, $b->id => KT::NAIK], $this->admin->id_pengguna);
            $this->fail('batch dengan baris bermasalah seharusnya gagal seluruhnya');
        } catch (AppException) {
            // sengaja diabaikan — yang diperiksa keadaan sesudahnya
        }

        $this->assertSame(1, $a->refresh()->tingkat, 'A tidak boleh ikut naik');
        $this->assertSame(self::TA1, $a->tahun_ajaran_berjalan);
    }

    // ---- Sambungan ke daftar ulang ----

    /**
     * Urutan kerja di pesantren ini: NAIK dulu, tagih daftar ulang kemudian.
     * Tarifnya karena itu diambil dari tingkat yang BARU.
     */
    public function test_daftar_ulang_memakai_tingkat_baru_setelah_kenaikan(): void
    {
        $santri = $this->santri('Ahmad', 1);

        // Sebelum naik: masih tingkat 1 → tak ada daftar ulang.
        $sebelum = collect((new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002',
        ])['baris'])->firstWhere('id', $santri->id);
        $this->assertSame('dilewati', $sebelum['daftar_ulang']['keputusan']);
        $this->assertStringContainsString('tingkat 1', $sebelum['daftar_ulang']['alasan']);

        (new KT)->eksekusi(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        // Sesudah naik: tingkat 2 → tarif kenaikan 1→2 (disimpan pada tingkat 2).
        $sesudah = collect((new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002',
        ])['baris'])->firstWhere('id', $santri->id);
        $this->assertSame('terbit', $sesudah['daftar_ulang']['keputusan']);
        $this->assertSame('800000.00', $sesudah['daftar_ulang']['nominal']);
    }

    public function test_sel_daftar_ulang_disimpan_pada_tingkat_tujuan(): void
    {
        $grid = (new TarifService)->grid(self::TA2, 'J002');

        // SMP bertingkat 3 → sel pada tingkat TUJUAN 2 & 3, bukan 1 & 2.
        $this->assertSame([2, 3], $grid['tingkat_kenaikan']);
        $this->assertSame([2, 3], array_keys($grid['umum']['daftar_ulang']));
        $this->assertSame('800000.00', $grid['umum']['daftar_ulang'][2]['nominal']);

        // Tingkat 1 bukan hasil kenaikan → tak boleh diberi tarif.
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('bukan hasil kenaikan');
        (new TarifService)->simpanUmum(self::TA2, 'J002', ['daftar_ulang' => [1 => ['nominal' => '700000']]]);
    }

    // ---- Alur HTTP ----

    public function test_alur_http_pratinjau_lalu_eksekusi(): void
    {
        $santri = $this->santri('Ahmad', 1);

        $this->actingAs($this->admin)->get(route('kenaikan_tingkat.index'))
            ->assertOk()->assertSee('Kenaikan Tingkat');

        $this->actingAs($this->admin)->post(route('kenaikan_tingkat.pratinjau'), [
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002',
        ])->assertOk()->assertSee($santri->nama)->assertSee('Naik tingkat');

        $this->actingAs($this->admin)->post(route('kenaikan_tingkat.eksekusi'), [
            'tahun_ajaran' => self::TA2, 'keputusan' => [$santri->id => KT::NAIK],
        ])->assertRedirect(route('kenaikan_tingkat.index'));

        $this->assertSame(2, $santri->refresh()->tingkat);
    }
}
