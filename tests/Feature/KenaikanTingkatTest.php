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
use App\Models\TagihanSantri;
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
use Illuminate\Support\Carbon;
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

    /** Tanggal uji dibekukan di dalam T.A1: keputusan diambil SEBELUM T.A2 mulai. */
    private const HARI_UJI = '2026-09-15';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Sejak perubahan dijadwalkan, "kapan sekarang" ikut menentukan hasil —
        // jadi tanggalnya tak boleh ikut bergeser bersama kalender sungguhan.
        Carbon::setTestNow(self::HARI_UJI);
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
        // Jalur setelah naik jenjang: Reguler → Lanjutan Reguler. Pemetaan ini yang
        // dipakai keputusan "Melanjutkan" (lihat PendaftaranLanjutanService::sasaran).
        JalurPendaftaran::create(['kode' => '003', 'nama' => 'Lanjutan Reguler', 'kode_jalur_lanjutan' => '003']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'kode_jalur_lanjutan' => '003']);

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * TETAPKAN, lalu majukan kalender ke T.A tujuan supaya jadwalnya menyala.
     *
     * Sejak modul ini menjadwalkan alih-alih mengubah seketika, hampir seluruh
     * test di sini memeriksa KEADAAN SESUDAH perubahannya berlaku — dan itu baru
     * terjadi pada 1 Juli tahun tujuan. Penetapannya sendiri tetap dilakukan
     * pada tanggal uji (T.A1 berjalan), persis seperti pemakaian sebenarnya:
     * keputusan diambil sebelum tahun barunya mulai.
     */
    private function tetapkanLaluBerlaku(string $taTujuan, array $keputusan, int $idPengguna, array $opsi = []): array
    {
        $hasil = (new KT)->tetapkan($taTujuan, $keputusan, $idPengguna, $opsi);

        $mulai = TahunAjaran::where('kode', $taTujuan)->value('tanggal_mulai');
        Carbon::setTestNow(Carbon::parse($mulai));
        (new KT)->terapkanYangJatuhTempo();
        Carbon::setTestNow(self::HARI_UJI);

        return $hasil;
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

        // Tingkat terakhir & jenjangnya punya lanjutan → MELANJUTKAN yang diusulkan.
        $this->assertSame(KT::MELANJUTKAN, $smp[$akhirBerlanjut->id]['usul']);
        $this->assertSame(
            [KT::MELANJUTKAN, KT::LULUS, KT::MENGULANG, KT::LEWATI],
            $smp[$akhirBerlanjut->id]['pilihan'],
            'yang tak melanjutkan tetap bisa diluluskan',
        );

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

    /**
     * ALUMNI & SANTRI KELUAR berdaftar SENDIRI, terpisah dari santri aktif.
     *
     * Dulu ketiganya bercampur di satu daftar, sehingga jumlah "santri" di layar
     * tak pernah sama dengan jumlah santri yang benar-benar bersekolah. Barisnya
     * TETAP di tabel `santri` yang sama — pemisahan ini murni tampilan, supaya
     * tagihan bersisa milik alumni tetap bisa ditagih & dibayar.
     */
    public function test_daftar_alumni_dan_keluar_terpisah_dari_santri_aktif(): void
    {
        $aktif = $this->santri('Masih Sekolah', 1);
        $alumni = $this->santri('Sudah Lulus', 3);
        $this->tetapkanLaluBerlaku(self::TA2, [$alumni->id => KT::LULUS], $this->admin->id_pengguna);
        $keluar = $this->santri('Berhenti', 2);
        (new SantriService)->mengundurkanDiri($keluar->id, 'pindah kota', $this->admin->id_pengguna);

        $this->assertSame('alumni', $alumni->refresh()->status);
        $this->assertSame('keluar', $keluar->refresh()->status);

        // Daftar Santri (Kependidikan) hanya yang AKTIF.
        $this->actingAs($this->admin)->get(route('santri.aktif'))->assertOk()
            ->assertSee('Masih Sekolah')
            ->assertDontSee('Sudah Lulus')
            ->assertDontSee('Berhenti');

        // Alumni punya daftarnya sendiri, lengkap dengan tanggal lulusnya.
        $this->actingAs($this->admin)->get(route('santri.alumni'))->assertOk()
            ->assertSee('Sudah Lulus')
            ->assertSee('Tanggal Lulus')
            ->assertDontSee('Masih Sekolah')
            ->assertDontSee('Berhenti');

        // Begitu pula yang keluar.
        $this->actingAs($this->admin)->get(route('santri.keluar'))->assertOk()
            ->assertSee('Berhenti')
            ->assertDontSee('Masih Sekolah')
            ->assertDontSee('Sudah Lulus');
    }

    /**
     * Santri yang sedang MELANJUTKAN muncul di daftar Calon Santri — di situlah
     * PPSB bekerja. Statusnya tetap `aktif`, jadi tanpa penyaring khusus
     * pekerjaan atas mereka tak akan terlihat oleh PPSB sama sekali.
     */
    public function test_yang_melanjutkan_muncul_di_daftar_calon_santri(): void
    {
        $santri = $this->santri('Calon Naik Jenjang', 3);

        $this->actingAs($this->admin)->get(route('santri.calon'))->assertOk()
            ->assertDontSee('Calon Naik Jenjang');

        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::MELANJUTKAN], $this->admin->id_pengguna);

        $this->actingAs($this->admin)->get(route('santri.calon'))->assertOk()
            ->assertSee('Calon Naik Jenjang');
        // …dan TIDAK hilang dari daftar santri aktif: ia masih bersekolah.
        $this->actingAs($this->admin)->get(route('santri.aktif'))->assertOk()
            ->assertSee('Calon Naik Jenjang');
    }

    // ---- Eksekusi ----

    public function test_naik_memajukan_tingkat_tahun_berjalan_dan_riwayat(): void
    {
        $santri = $this->santri('Ahmad', 1);

        $hasil = $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->assertSame(1, $hasil['naik']);
        $santri->refresh();
        $this->assertSame(2, $santri->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
        $this->assertSame(self::TA1, $santri->tahun_ajaran, 'angkatan tidak ikut maju');

        $riwayat = RiwayatTingkat::where('id_santri', $santri->id)->orderBy('tahun_ajaran')->get();
        $this->assertSame([self::TA1, self::TA2], $riwayat->pluck('tahun_ajaran')->all());
        $this->assertSame([1, 2], $riwayat->pluck('tingkat')->all());
    }

    /**
     * MELANJUTKAN — santri tingkat terakhir langsung DIDAFTARKAN ke jenjang
     * berikutnya dari layar massal ini, tanpa harus dibuka satu per satu dari
     * halaman detail tiap santri.
     *
     * Yang WAJIB tidak terjadi: data santri berubah. Ia masih bersekolah di
     * jenjang lama & masih ditagih SPP sampai seleksi + med check PPSB selesai.
     */
    public function test_melanjutkan_membuka_pendaftaran_lanjutan_tanpa_menyentuh_data_santri(): void
    {
        $santri = $this->santri('Akhir SMP', 3);

        $hasil = $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::MELANJUTKAN], $this->admin->id_pengguna);

        $this->assertSame(1, $hasil['melanjutkan']);
        $this->assertSame(0, $hasil['naik']);

        // Data santri UTUH.
        $santri->refresh();
        $this->assertSame('aktif', $santri->status);
        $this->assertSame('J002', $santri->kode_jenjang);
        $this->assertSame(3, $santri->tingkat);
        $this->assertSame(self::TA1, $santri->tahun_ajaran_berjalan, 'tahun berjalan belum maju');

        // Siklus pendaftaran lanjutannya terbuka, ke jenjang & jalur dari MASTER.
        $p = Pendaftaran::where('id_santri', $santri->id)->lanjutan()->firstOrFail();
        $this->assertSame('J003', $p->kode_jenjang);
        $this->assertSame('003', $p->kode_jalur, 'Reguler → Lanjutan Reguler, mengikuti master jalur');
        $this->assertSame(self::TA2, $p->tahun_ajaran);
        $this->assertStringStartsWith('PSL-', $p->nomor);
    }

    /**
     * "Melanjutkan" hanya ditawarkan bila memang bisa dikerjakan. Sumber
     * penghalangnya sama dengan yang akan mengerjakannya, supaya layar tak
     * menawarkan pilihan yang lalu membatalkan seluruh batch saat dijalankan.
     */
    public function test_melanjutkan_tidak_ditawarkan_bila_siklusnya_sudah_dibuka(): void
    {
        $santri = $this->santri('Akhir SMP', 3);
        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::MELANJUTKAN], $this->admin->id_pengguna);

        $baris = collect((new KT)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002'])['baris'])
            ->firstWhere('id', $santri->id);

        $this->assertSame(KT::LEWATI, $baris['usul']);
        $this->assertNotContains(KT::MELANJUTKAN, $baris['pilihan']);
        $this->assertStringContainsString('sudah dibuka', $baris['alasan']);
    }

    /** Jalur tanpa "Jalur Setelah Naik Jenjang" pun dihalangi & dikatakan sebabnya. */
    public function test_melanjutkan_dihalangi_bila_jalur_belum_punya_lanjutan(): void
    {
        JalurPendaftaran::whereKey('001')->update(['kode_jalur_lanjutan' => null]);
        $santri = $this->santri('Akhir SMP', 3);

        $baris = collect((new KT)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002'])['baris'])
            ->firstWhere('id', $santri->id);

        $this->assertSame(KT::LEWATI, $baris['usul']);
        $this->assertStringContainsString('Jalur Setelah Naik Jenjang', $baris['alasan']);

        // Dan kiriman langsung pun ditolak, bukan cuma tak ditawarkan.
        $this->expectException(AppException::class);
        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::MELANJUTKAN], $this->admin->id_pengguna);
    }

    /** MENGULANG: tingkat tetap, tapi tahun berjalan MAJU — tahunnya memang berganti. */
    public function test_mengulang_menahan_tingkat_tapi_tahun_tetap_maju(): void
    {
        $santri = $this->santri('Mengulang', 2);

        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::MENGULANG], $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame(2, $santri->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
        $this->assertSame('Mengulang di tingkat yang sama.',
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

        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->assertSame(1200000.0, (float) (new SppService)->nominalSppSantri($santri->id)['nominal']);
    }

    public function test_lulus_menjadi_alumni_dengan_tanggal(): void
    {
        $santri = $this->santri('Lulusan', 3, 'J003');

        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::LULUS], $this->admin->id_pengguna, ['tanggal_lulus' => '2028-06-20']);

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
        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::LULUS], $this->admin->id_pengguna);
    }

    public function test_naik_dari_tingkat_terakhir_ditolak(): void
    {
        $santri = $this->santri('Tingkat Akhir', 3);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah di tingkat terakhir');
        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);
    }

    public function test_kenaikan_kedua_untuk_ta_sama_ditolak(): void
    {
        $santri = $this->santri('Ahmad', 1);
        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah berada di T.A');
        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);
    }

    /** Satu gagal → seluruh batch batal; angkatan yang naik separuh sulit dibereskan. */
    public function test_batch_gagal_membatalkan_seluruhnya(): void
    {
        $a = $this->santri('A', 1);
        $b = $this->santri('B', 3); // di tingkat terakhir → "naik" akan ditolak

        try {
            $this->tetapkanLaluBerlaku(self::TA2, [$a->id => KT::NAIK, $b->id => KT::NAIK], $this->admin->id_pengguna);
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

        $this->tetapkanLaluBerlaku(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

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

    // ---- Penjadwalan: ditetapkan sekarang, berlaku nanti ----

    /**
     * INTI perubahan alur ini: menekan tombol MENJADWALKAN, bukan mengubah.
     * Sebelumnya data santri berubah seketika, sehingga ia berjalan berbulan-bulan
     * dengan tingkat & tahun berjalan yang belum berlaku.
     */
    public function test_menetapkan_tidak_langsung_mengubah_santri(): void
    {
        $santri = $this->santri('Ahmad', 1);

        (new KT)->tetapkan(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame(1, $santri->tingkat, 'tingkat belum boleh berubah');
        $this->assertSame(self::TA1, $santri->tahun_ajaran_berjalan);
        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA2,
            'keputusan' => KT::NAIK, 'status' => 'siap', 'tingkat_tujuan' => 2,
        ]);
        // Riwayat tahun tujuan belum ditulis — ia lahir bersama perpindahannya.
        $this->assertSame(0, RiwayatTingkat::where('id_santri', $santri->id)
            ->where('tahun_ajaran', self::TA2)->count());

        // Sehari sebelum tahun barunya: masih belum menyala.
        Carbon::setTestNow('2027-06-30');
        (new KT)->terapkanYangJatuhTempo();
        $this->assertSame(1, $santri->refresh()->tingkat);

        // Hari pertama T.A tujuan: menyala.
        Carbon::setTestNow('2027-07-01');
        $this->assertSame(1, (new KT)->terapkanYangJatuhTempo()['diterapkan']);
        $this->assertSame(2, $santri->refresh()->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
    }

    /** Penerapnya idempoten — dipanggil berkali-kali tak menaikkan dua kali. */
    public function test_penerap_tidak_menaikkan_dua_kali(): void
    {
        $santri = $this->santri('Ahmad', 1);
        (new KT)->tetapkan(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        Carbon::setTestNow('2027-07-01');
        $this->assertSame(1, (new KT)->terapkanYangJatuhTempo()['diterapkan']);
        $this->assertSame(0, (new KT)->terapkanYangJatuhTempo()['diterapkan']);
        $this->assertSame(0, (new KT)->terapkanYangJatuhTempo()['diterapkan']);

        $this->assertSame(2, $santri->refresh()->tingkat);
    }

    /**
     * Ditetapkan SETELAH tahun tujuannya sudah berjalan (kenaikan yang telat
     * dikerjakan) — tak ada gunanya menunggu, jadi langsung menyala.
     */
    public function test_penetapan_setelah_tahun_mulai_langsung_berlaku(): void
    {
        $santri = $this->santri('Telat', 1);
        Carbon::setTestNow('2027-08-10'); // T.A2 sudah berjalan

        (new KT)->tetapkan(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);

        $this->assertSame(2, $santri->refresh()->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
    }

    /**
     * MELANJUTKAN tidak ikut menyala hanya karena tanggalnya tiba: perpindahan
     * jenjang menuntut uang pangkal, med check, dan nominal yang diketik petugas.
     * Ia menunggu PPSB-nya tuntas — dan tetap terlihat sebagai pekerjaan yang
     * menggantung, bukan hilang diam-diam.
     */
    public function test_melanjutkan_tertunda_sampai_ppsb_tuntas(): void
    {
        $santri = $this->santri('Akhir SMP', 3);
        (new KT)->tetapkan(self::TA2, [$santri->id => KT::MELANJUTKAN], $this->admin->id_pengguna);

        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'status' => 'menunggu_ppsb', 'keputusan' => KT::MELANJUTKAN,
        ]);

        // Tahun barunya tiba, PPSB belum tuntas → TIDAK menyala.
        Carbon::setTestNow('2027-07-01');
        $this->assertSame(0, (new KT)->terapkanYangJatuhTempo()['diterapkan']);
        $santri->refresh();
        $this->assertSame('J002', $santri->kode_jenjang, 'belum pindah jenjang');
        $this->assertSame(self::TA1, $santri->tahun_ajaran_berjalan);

        // Dan ia tetap terlihat di daftar kerja, bukan hilang.
        $this->actingAs($this->admin)->get(route('kenaikan_tingkat.index'))
            ->assertOk()->assertSee('Menunggu PPSB')->assertSee($santri->nama);
    }

    /**
     * URUTAN KERJA JUNI — tetapkan kenaikan, LALU tagih daftar ulangnya, sebelum
     * tahun barunya mulai. Inilah urutan yang dicatat CLAUDE.md, dan inilah yang
     * sempat putus saat kenaikan berubah dari "langsung" menjadi "terjadwal":
     * tarif daftar ulang dicari memakai tingkat santri HARI ITU (masih lama),
     * sehingga seluruh angkatan tertolak "masih di tingkat 1 — jalankan Kenaikan
     * Tingkat lebih dulu", padahal petugas baru saja melakukannya.
     */
    public function test_daftar_ulang_juni_memakai_tingkat_yang_akan_berlaku(): void
    {
        $santri = $this->santri('Ahmad', 1);

        Carbon::setTestNow('2027-06-10'); // masih T.A1; T.A2 belum mulai
        (new KT)->tetapkan(self::TA2, [$santri->id => KT::NAIK], $this->admin->id_pengguna);
        $this->assertSame(1, $santri->refresh()->tingkat, 'belum berlaku — memang begitu rancangannya');

        $baris = collect((new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002',
        ])['baris'])->firstWhere('id', $santri->id);

        $this->assertSame('terbit', $baris['daftar_ulang']['keputusan']);
        // Tarif tingkat 2 (tujuan), bukan tingkat 1 (sekarang).
        $this->assertSame('800000.00', $baris['daftar_ulang']['nominal']);
        $this->assertSame(2, $baris['tingkat'], 'yang ditampilkan tingkat pada T.A tagihan');
        $this->assertSame(1, $baris['tingkat_sekarang']);

        // Yang benar-benar terbit memakai angka yang sama dengan pratinjaunya.
        (new TagihanMassalService)->terbitkan(self::TA2, [$santri->id => '800000'],
            $this->admin->id_pengguna, ['tanggal' => '2027-06-10']);

        $t = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'daftar_ulang')->sole();
        $this->assertSame('800000.00', $t->nominal);
        $this->assertSame(self::TA2, $t->tahun_ajaran);
        $this->assertStringContainsString('tingkat 2', $t->keterangan);
    }

    /** Santri tanpa kenaikan yang ditetapkan tetap tertolak — penjaganya tak longgar. */
    public function test_daftar_ulang_menolak_yang_kenaikannya_belum_ditetapkan(): void
    {
        $santri = $this->santri('Belum Ditetapkan', 1);

        Carbon::setTestNow('2027-06-10');
        $baris = collect((new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002',
        ])['baris'])->firstWhere('id', $santri->id);

        $this->assertSame('dilewati', $baris['daftar_ulang']['keputusan']);
        $this->assertStringContainsString('Tetapkan kenaikannya lebih dulu', $baris['daftar_ulang']['alasan']);
    }

    // ---- Alur HTTP ----

    public function test_alur_http_pratinjau_lalu_tetapkan(): void
    {
        $santri = $this->santri('Ahmad', 1);

        $this->actingAs($this->admin)->get(route('kenaikan_tingkat.index'))
            ->assertOk()->assertSee('Kenaikan Tingkat');

        $this->actingAs($this->admin)->post(route('kenaikan_tingkat.pratinjau'), [
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'J002',
        ])->assertOk()->assertSee($santri->nama)->assertSee('Naik tingkat');

        $this->actingAs($this->admin)->post(route('kenaikan_tingkat.tetapkan'), [
            'tahun_ajaran' => self::TA2, 'keputusan' => [$santri->id => KT::NAIK],
        ])->assertRedirect(route('kenaikan_tingkat.index'));

        // BELUM berubah: T.A tujuan baru mulai 1 Juli 2027.
        $this->assertSame(1, $santri->refresh()->tingkat, 'penetapan tidak boleh langsung mengubah');
        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA2,
            'keputusan' => KT::NAIK, 'status' => 'siap', 'tingkat_tujuan' => 2,
        ]);

        // Daftar kerjanya menyebutkan jadwal itu.
        $this->actingAs($this->admin)->get(route('kenaikan_tingkat.index'))
            ->assertOk()->assertSee('Perubahan Terjadwal')->assertSee($santri->nama);

        // Tahun barunya tiba → membuka halaman saja sudah menyalakannya, tanpa cron.
        Carbon::setTestNow('2027-07-01');
        $this->actingAs($this->admin)->get(route('kenaikan_tingkat.index'))->assertOk();

        $this->assertSame(2, $santri->refresh()->tingkat);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA2, 'status' => 'diterapkan',
        ]);
    }
}
