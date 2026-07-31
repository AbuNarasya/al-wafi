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
use App\Services\Modules\PendaftaranLanjutanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\TarifService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * KENAIKAN JENJANG INTERNAL LEWAT PROSES PPSB.
 *
 * Yang dijaga di sini:
 *  • `santri.status` TETAP `aktif` sepanjang proses — yang bergerak adalah
 *    `pendaftaran.status`. Santri kelas akhir masih bersekolah & masih ditagih SPP
 *    selama mendaftar ke jenjang berikutnya;
 *  • tahap BERKAS dilewati (terbayar → langsung diseleksi);
 *  • biaya registrasi OPSIONAL lewat sel tarif: terisi = ditagih, Bebas = dilewati,
 *    kosong = berhenti;
 *  • data santri baru berubah pada langkah TERAKHIR, dan sekali saja.
 */
class PendaftaranLanjutanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPL';

    private const PEND = '4.ZZPL.PEND';

    private const PIUT = '1.ZZPL.PIUT';

    private const UNIT = 'ZZPLU';

    private const TA1 = '2026/2027';

    private const TA2 = '2027/2028';

    /** Tanggal uji dibekukan di dalam T.A1: kenaikan diurus SEBELUM T.A2 mulai. */
    private const HARI_UJI = '2026-09-15';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Sejak perpindahan santri dijadwalkan (menyala saat T.A tujuan dimulai),
        // "kapan sekarang" ikut menentukan hasilnya.
        Carbon::setTestNow(self::HARI_UJI);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PL']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA1, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => self::TA2, 'status' => 'aktif']);

        // SDTQ → SMP → SMA (SMA jenjang terakhir).
        Jenjang::create(['kode' => 'J003', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3, 'kode_jenjang_lanjutan' => 'J003']);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6, 'kode_jenjang_lanjutan' => 'J002']);

        // Reguler → Lanjutan Reguler; Anak Karyawan → tetap dirinya sendiri.
        JalurPendaftaran::create(['kode' => '003', 'nama' => 'Lanjutan Reguler', 'kode_jalur_lanjutan' => '003']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'kode_jalur_lanjutan' => '003']);
        JalurPendaftaran::create(['kode' => '005', 'nama' => 'Anak Karyawan', 'kode_jalur_lanjutan' => '005', 'bebas_uang_pangkal' => true]);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        $svc = new JenisBiayaService;
        foreach (['J001', 'J002'] as $j) {
            $svc->create(['kode' => "REG-{$j}", 'nama' => "Registrasi {$j}", 'tipe' => 'registrasi', 'kode_jenjang' => $j,
                'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT]);
            $svc->create(['kode' => "UP-{$j}", 'nama' => "Uang Pangkal {$j}", 'tipe' => 'uang_pangkal', 'kode_jenjang' => $j,
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
            $svc->create(['kode' => "PLK-{$j}", 'nama' => "Perlengkapan {$j}", 'tipe' => 'perlengkapan', 'kode_jenjang' => $j,
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
            $svc->create(['kode' => "SPP-{$j}", 'nama' => "SPP {$j}", 'tipe' => 'spp', 'kode_jenjang' => $j, 'berulang' => true,
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
        }

        $tarif = new TarifService;
        // Masuk SDTQ pada T.A pertama.
        $tarif->simpan(self::TA1, 'J001', ['-' => ['registrasi' => ['nominal' => '500000'], 'uang_pangkal' => ['nominal' => '25000000']]]);
        $tarif->simpanUmum(self::TA1, 'J001', ['spp' => ['nominal' => '1500000']]);
        // Naik ke SMP pada T.A kedua — jalur lanjutan 003 punya tarifnya sendiri.
        $tarif->simpan(self::TA2, 'J002', [
            '-' => ['registrasi' => ['nominal' => '1000000'], 'uang_pangkal' => ['nominal' => '50000000'], 'perlengkapan' => ['nominal' => '13000000']],
            '003' => ['registrasi' => ['nominal' => '250000'], 'uang_pangkal' => ['nominal' => '20000000']],
        ]);
        $tarif->simpanUmum(self::TA2, 'J002', ['spp' => ['nominal' => '4200000']]);
    }

    /** Santri SDTQ tingkat 6 yang sudah aktif — calon naik ke SMP. */
    private function santriKelasAkhir(string $jalur = '001'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'gelombang' => 1,
            'tahun_ajaran' => self::TA1, 'jalur' => $jalur, 'kode_jenjang' => 'J001', 'tingkat' => 6,
        ]);
        $santri->update(['status' => 'aktif', 'nis' => '260001', 'tahun_ajaran_berjalan' => self::TA1]);
        // Titik awal riwayatnya — dalam pemakaian nyata baris ini ditulis oleh
        // daftar ulang PPSB atau oleh impor santri lama.
        RiwayatTingkat::create([
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA1,
            'kode_jenjang' => 'J001', 'tingkat' => 6, 'catatan' => 'Fixture.',
        ]);

        return $santri->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Bawa satu siklus lanjutan sampai lolos kesehatan. */
    private function sampaiLolos(Santri $santri): Pendaftaran
    {
        $svc = new PendaftaranLanjutanService;
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
        if ($p->status === 'calon') {
            $svc->tandaiRegistrasiLunas($santri->id, $p->kode_jenjang, $p->tahun_ajaran);
            $p->refresh();
        }
        $svc->majukan($p->id, 'diseleksi', ['nilai_baca' => 85], $this->admin->id_pengguna);
        $svc->majukan($p->id, 'diterima', [], $this->admin->id_pengguna);
        $svc->majukan($p->id, 'lolos_kesehatan', ['medcheck_ok' => true], $this->admin->id_pengguna);

        return $p->refresh();
    }

    /**
     * Eksekusi kenaikan, LALU majukan kalender ke T.A tujuan supaya perpindahannya
     * menyala.
     *
     * Eksekusi sendiri hanya menerbitkan tagihan & mengakru — perpindahan
     * jenjang/tingkat/jalur/tahun berjalan menunggu tahun ajaran tujuan dimulai,
     * sama seperti naik tingkat biasa. Tanpa itu santri yang PPSB-nya tuntas
     * bulan Mei sudah ber-jenjang SMP sementara kalender masih tahun lama.
     */
    private function naikkanLaluBerlaku(Pendaftaran $p, array $data): array
    {
        $hasil = (new PendaftaranLanjutanService)->eksekusiKenaikan($p->id, $data, $this->admin->id_pengguna);

        $mulai = TahunAjaran::where('kode', $p->tahun_ajaran)->value('tanggal_mulai');
        Carbon::setTestNow(Carbon::parse($mulai));
        (new \App\Services\Modules\KenaikanTingkatService)->terapkanYangJatuhTempo();
        Carbon::setTestNow(self::HARI_UJI);

        $hasil['santri'] = $hasil['santri']->refresh();

        return $hasil;
    }

    // ---- Sasaran kenaikan ----

    public function test_sasaran_mengikuti_master_jenjang_dan_jalur(): void
    {
        $sasaran = (new PendaftaranLanjutanService)->sasaran($this->santriKelasAkhir());

        $this->assertSame('J002', $sasaran['kode_jenjang']);
        $this->assertSame('003', $sasaran['kode_jalur'], 'Reguler → Lanjutan Reguler');
        $this->assertNull($sasaran['alasan']);
    }

    /**
     * NAIK JENJANG HANYA DARI TINGKAT TERAKHIR.
     *
     * Dulu tak diperiksa sama sekali: santri SDTQ tingkat 1 pun ditawari naik ke
     * SMP dan bisa dieksekusi, melewati sisa tingkatnya tanpa gejala apa pun.
     */
    public function test_belum_tingkat_terakhir_tidak_boleh_naik_jenjang(): void
    {
        $svc = new PendaftaranLanjutanService;
        $santri = $this->santriKelasAkhir();

        foreach ([1, 5] as $tingkat) {
            $santri->update(['tingkat' => $tingkat]);
            $sasaran = $svc->sasaran($santri->refresh());

            // Sasarannya tetap disebut (SMP), tapi dengan alasan mengapa belum boleh.
            $this->assertSame('J002', $sasaran['kode_jenjang']);
            $this->assertStringContainsString("masih tingkat {$tingkat} dari 6", $sasaran['alasan']);
            $this->assertStringContainsString('Kenaikan Tingkat', $sasaran['alasan']);

            // Dan kiriman langsung pun ditolak — bukan cuma formulirnya disembunyikan.
            try {
                $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
                $this->fail('harus 422');
            } catch (AppException $e) {
                $this->assertSame(422, $e->status);
            }
            $this->assertSame(0, Pendaftaran::where('id_santri', $santri->id)->lanjutan()->count());
        }

        // Di tingkat terakhir barulah boleh.
        $santri->update(['tingkat' => 6]);
        $this->assertNull($svc->sasaran($santri->refresh())['alasan']);
    }

    /**
     * Siklus yang SUDAH TERBUKA pun tak bisa dieksekusi bila tingkatnya turun di
     * tengah jalan. Eksekusi adalah satu-satunya langkah yang mengubah data
     * santri, jadi penjaganya harus ada di situ juga — bukan cuma saat membuka.
     * Ini juga yang menutup siklus yang dibuka sebelum penjaga ini ada.
     */
    public function test_eksekusi_menolak_bila_santri_tak_lagi_di_tingkat_terakhir(): void
    {
        $svc = new PendaftaranLanjutanService;
        $santri = $this->santriKelasAkhir();
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        // Bawa siklusnya sampai siap dieksekusi.
        $p->update(['status' => 'lolos_kesehatan']);
        // …lalu tingkatnya ternyata bukan tingkat terakhir.
        $santri->update(['tingkat' => 4]);

        try {
            $svc->eksekusiKenaikan($p->id, ['tingkat' => 1], $this->admin->id_pengguna);
            $this->fail('harus 422');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('masih tingkat 4 dari 6', $e->getMessage());
        }

        // Data santri tak tersentuh sama sekali.
        $this->assertSame('J001', $santri->refresh()->kode_jenjang);
        $this->assertSame(4, $santri->tingkat);
    }

    /**
     * Yang TAK BISA DIPASTIKAN juga dihalangi, dan dikatakan terus terang —
     * santri hasil impor boleh belum bertingkat, dan jenjang boleh belum
     * mengisi jumlah tingkatnya. Keduanya jangan diloloskan diam-diam.
     */
    public function test_tingkat_atau_jumlah_tingkat_kosong_menghalangi_dengan_pesan_menuntun(): void
    {
        $svc = new PendaftaranLanjutanService;
        $santri = $this->santriKelasAkhir();

        $santri->update(['tingkat' => null]);
        $this->assertStringContainsString('Tingkat santri ini belum terisi', $svc->sasaran($santri->refresh())['alasan']);

        $santri->update(['tingkat' => 6]);
        Jenjang::whereKey('J001')->update(['jumlah_tingkat' => null]);
        $this->assertStringContainsString('Jumlah tingkat jenjang', $svc->sasaran($santri->refresh())['alasan']);
    }

    public function test_jenjang_terakhir_tak_punya_sasaran(): void
    {
        $santri = $this->santriKelasAkhir();
        $santri->update(['kode_jenjang' => 'J003', 'tingkat' => 3]);

        $this->assertNull((new PendaftaranLanjutanService)->sasaran($santri->refresh()));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('menjadi alumni');
        (new PendaftaranLanjutanService)->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
    }

    // ---- Membuka siklus ----

    public function test_membuka_siklus_menagih_registrasi_dan_tidak_menyentuh_status_santri(): void
    {
        $santri = $this->santriKelasAkhir();

        $p = (new PendaftaranLanjutanService)->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        $this->assertSame('lanjutan', $p->jenis);
        $this->assertSame('calon', $p->status);
        $this->assertSame('J002', $p->kode_jenjang);
        $this->assertSame('003', $p->kode_jalur);
        $this->assertStringStartsWith('PSL-', $p->nomor);
        // Berkas dilewati — ditandai selesai sejak awal.
        $this->assertTrue((bool) $p->verifikasi_ok);

        // INTI: santrinya tak bergeser sedikit pun.
        $santri->refresh();
        $this->assertSame('aktif', $santri->status);
        $this->assertSame('J001', $santri->kode_jenjang);
        $this->assertSame(6, $santri->tingkat);
        $this->assertSame(self::TA1, $santri->tahun_ajaran_berjalan);

        // Registrasi jalur lanjutan (250rb, bukan 1jt tarif umum).
        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')
            ->where('tahun_ajaran', self::TA2)->firstOrFail();
        $this->assertSame(250000.0, (float) $tagihan->nominal);
        $this->assertSame('J002', $tagihan->kode_jenjang);
    }

    /** Registrasi OPSIONAL: sel bertanda Bebas → tahapnya langsung lewat. */
    public function test_registrasi_bebas_melewati_tahap_pembayaran(): void
    {
        (new TarifService)->simpan(self::TA2, 'J002', ['003' => ['registrasi' => ['bebas' => true]]]);
        $santri = $this->santriKelasAkhir();

        $p = (new PendaftaranLanjutanService)->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        $this->assertSame('terbayar', $p->status);
        $this->assertSame(0, TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')
            ->where('tahun_ajaran', self::TA2)->count());
    }

    /** Sel registrasi yang belum diisi MENGHENTIKAN, bukan diam-diam dilewati. */
    public function test_registrasi_belum_diisi_menghalangi_pembukaan(): void
    {
        (new TarifService)->simpan(self::TA2, 'J002', [
            '-' => ['registrasi' => ['nominal' => '']],
            '003' => ['registrasi' => ['nominal' => '']],
        ]);
        $santri = $this->santriKelasAkhir();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('belum diisi');
        (new PendaftaranLanjutanService)->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
    }

    public function test_dua_siklus_berjalan_ditolak(): void
    {
        $santri = $this->santriKelasAkhir();
        $svc = new PendaftaranLanjutanService;
        $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('masih punya pendaftaran lanjutan yang berjalan');
        $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
    }

    public function test_hanya_untuk_santri_aktif(): void
    {
        $santri = $this->santriKelasAkhir();
        $santri->update(['status' => 'keluar']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('hanya untuk santri AKTIF');
        (new PendaftaranLanjutanService)->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
    }

    // ---- Tahapan ----

    /** BERKAS DILEWATI: dari terbayar langsung ke diseleksi. */
    public function test_tahap_berkas_dilewati(): void
    {
        $santri = $this->santriKelasAkhir();
        $svc = new PendaftaranLanjutanService;
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);
        $svc->tandaiRegistrasiLunas($santri->id, 'J002', self::TA2);

        // Tahap berkas TIDAK ada di rantai lanjutan.
        try {
            $svc->majukan($p->id, 'terverifikasi', [], $this->admin->id_pengguna);
            $this->fail('tahap berkas seharusnya tak ada di rantai lanjutan');
        } catch (AppException $e) {
            $this->assertStringContainsString('Sudah Diseleksi', $e->getMessage(), 'pesannya menunjukkan tahap sah berikutnya');
        }

        $this->assertSame('diseleksi', $svc->majukan($p->id, 'diseleksi', ['nilai_baca' => 90], $this->admin->id_pengguna)->status);
    }

    public function test_registrasi_lunas_memajukan_pendaftaran_bukan_santri(): void
    {
        $santri = $this->santriKelasAkhir();
        $svc = new PendaftaranLanjutanService;
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        $svc->tandaiRegistrasiLunas($santri->id, 'J002', self::TA2);

        $this->assertSame('terbayar', $p->refresh()->status);
        $this->assertSame('aktif', $santri->refresh()->status, 'status santri tak boleh ikut mundur ke terbayar');
    }

    // ---- Eksekusi kenaikan ----

    public function test_eksekusi_memindahkan_santri_dan_menagihkan_biaya_tujuan(): void
    {
        $santri = $this->santriKelasAkhir();
        $p = $this->sampaiLolos($santri);

        $hasil = $this->naikkanLaluBerlaku($p, [
            'tingkat' => 1, 'nominal_uang_pangkal' => '20000000', 'nominal_perlengkapan' => '13000000',
        ]);

        $s = $hasil['santri'];
        $this->assertSame('J002', $s->kode_jenjang);
        $this->assertSame(1, $s->tingkat);
        $this->assertSame('003', $s->jalur, 'jalur berganti mengikuti kode_jalur_lanjutan');
        $this->assertSame(self::TA2, $s->tahun_ajaran_berjalan, 'tahun BERJALAN maju');
        $this->assertSame(self::TA1, $s->tahun_ajaran, 'angkatan TIDAK ikut maju');
        $this->assertSame('aktif', $s->status);
        $this->assertSame('naik', $p->refresh()->status);

        // Tagihan memakai jenjang & T.A tujuan.
        $this->assertSame(20000000.0, (float) $hasil['uang_pangkal']->nominal);
        $this->assertSame('J002', $hasil['uang_pangkal']->kode_jenjang);
        $this->assertSame(self::TA2, $hasil['uang_pangkal']->tahun_ajaran);
        $this->assertSame(13000000.0, (float) $hasil['perlengkapan']->nominal);

        // Riwayat tingkat: dua baris — SDTQ tingkat 6, lalu SMP tingkat 1.
        $riwayat = RiwayatTingkat::where('id_santri', $s->id)->orderBy('tahun_ajaran')->get();
        $this->assertSame([self::TA1, self::TA2], $riwayat->pluck('tahun_ajaran')->all());
        $this->assertSame(['J001', 'J002'], $riwayat->pluck('kode_jenjang')->all());
        $this->assertSame([6, 1], $riwayat->pluck('tingkat')->all());
    }

    /** Uang pangkal jenjang lama TIDAK menghalangi tagihan jenjang baru. */
    public function test_uang_pangkal_jenjang_lama_tidak_menghalangi(): void
    {
        $santri = $this->santriKelasAkhir();
        // Tagihan uang pangkal SDTQ dari saat ia masuk.
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '25000000']);

        $p = $this->sampaiLolos($santri);
        (new PendaftaranLanjutanService)->eksekusiKenaikan($p->id, ['tingkat' => 1, 'nominal_uang_pangkal' => '20000000'], $this->admin->id_pengguna);

        $up = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'uang_pangkal')
            ->orderBy('tahun_ajaran')->get();
        $this->assertCount(2, $up);
        $this->assertSame(['J001', 'J002'], $up->pluck('kode_jenjang')->all());
    }

    public function test_tingkat_di_luar_jangkauan_jenjang_tujuan_ditolak(): void
    {
        $p = $this->sampaiLolos($this->santriKelasAkhir());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Tingkat 5');
        (new PendaftaranLanjutanService)->eksekusiKenaikan($p->id, ['tingkat' => 5, 'nominal_uang_pangkal' => '20000000'], $this->admin->id_pengguna);
    }

    public function test_eksekusi_sebelum_lolos_kesehatan_ditolak(): void
    {
        $santri = $this->santriKelasAkhir();
        $p = (new PendaftaranLanjutanService)->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        $this->expectException(AppException::class);
        (new PendaftaranLanjutanService)->eksekusiKenaikan($p->id, ['tingkat' => 1], $this->admin->id_pengguna);
    }

    /** SPP mengikuti tahun BERJALAN — inilah gunanya kolom itu dipisah dari angkatan. */
    public function test_spp_memakai_tarif_tahun_berjalan_setelah_naik(): void
    {
        $santri = $this->santriKelasAkhir();
        $this->assertSame(1500000.0, (float) (new SppService)->nominalSppSantri($santri->id)['nominal']);

        $p = $this->sampaiLolos($santri);
        $this->naikkanLaluBerlaku($p, ['tingkat' => 1, 'nominal_uang_pangkal' => '20000000']);

        // Tarif SMP T.A 2027/2028, bukan SDTQ T.A 2026/2027.
        $this->assertSame(4200000.0, (float) (new SppService)->nominalSppSantri($santri->id)['nominal']);
    }

    public function test_pembatalan_tidak_meninggalkan_bekas_pada_santri(): void
    {
        $santri = $this->santriKelasAkhir();
        $svc = new PendaftaranLanjutanService;
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna);

        $svc->batalkan($p->id, 'Wali membatalkan', $this->admin->id_pengguna);

        $this->assertSame('mengundurkan_diri', $p->refresh()->status);
        $santri->refresh();
        $this->assertSame('J001', $santri->kode_jenjang);
        $this->assertSame(6, $santri->tingkat);
        $this->assertSame(self::TA1, $santri->tahun_ajaran_berjalan);
        // Siklus baru boleh dibuka lagi setelah yang lama ditutup.
        $this->assertSame('calon', $svc->buat($santri->id, ['tahun_ajaran' => self::TA2], $this->admin->id_pengguna)->status);
    }

    // ---- Sambungan ke jadwal perubahan ----

    /**
     * Eksekusi kenaikan MENAGIH sekarang, MEMINDAHKAN nanti.
     *
     * Dulu keduanya terjadi bersamaan, sehingga siklus yang tuntas bulan Mei
     * membuat santri ber-jenjang SMP sementara kalender masih tahun lama — dan
     * setiap pencarian tarif yang bersandar pada jenjang & tahun berjalan ikut
     * mendahului kalender.
     */
    public function test_eksekusi_menagih_sekarang_memindahkan_saat_tahun_tujuan_mulai(): void
    {
        $santri = $this->santriKelasAkhir();
        $p = $this->sampaiLolos($santri);

        $hasil = (new PendaftaranLanjutanService)->eksekusiKenaikan($p->id, [
            'tingkat' => 1, 'nominal_uang_pangkal' => '20000000',
        ], $this->admin->id_pengguna);

        // TAGIHANNYA sudah terbit, memakai jenjang & T.A TUJUAN — walau santrinya
        // belum berpindah. Inilah gunanya siklus dibuka jauh hari.
        $this->assertSame('J002', $hasil['uang_pangkal']->kode_jenjang);
        $this->assertSame(self::TA2, $hasil['uang_pangkal']->tahun_ajaran);
        $this->assertTrue((bool) $hasil['uang_pangkal']->refresh()->sudah_akrual);

        // SANTRINYA belum.
        $santri->refresh();
        $this->assertSame('J001', $santri->kode_jenjang);
        $this->assertSame(6, $santri->tingkat);
        $this->assertSame(self::TA1, $santri->tahun_ajaran_berjalan);
        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA2,
            'status' => 'siap', 'tingkat_tujuan' => 1, 'kode_jenjang_tujuan' => 'J002',
        ]);

        // 1 Juli tiba → menyala, lengkap dengan riwayat tingkatnya.
        Carbon::setTestNow('2027-07-01');
        (new \App\Services\Modules\KenaikanTingkatService)->terapkanYangJatuhTempo();

        $santri->refresh();
        $this->assertSame('J002', $santri->kode_jenjang);
        $this->assertSame(1, $santri->tingkat);
        $this->assertSame('003', $santri->jalur);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
        $this->assertSame(1, RiwayatTingkat::where('id_santri', $santri->id)
            ->where('tahun_ajaran', self::TA2)->where('kode_jenjang', 'J002')->count());
    }

    /**
     * SPP SUSULAN untuk periode tahun LALU memakai jenjang tahun itu.
     *
     * Dulu jenjangnya diambil dari keadaan sekarang: santri yang sudah naik ke
     * SMP ditagih dengan tarif DAN akun SMP untuk bulan ketika ia masih di SDTQ.
     * Akun itu menentukan pendapatan unit bisnis mana yang bertambah, jadi yang
     * keliru bukan cuma angkanya.
     */
    public function test_spp_susulan_memakai_jenjang_tahun_itu(): void
    {
        $santri = $this->santriKelasAkhir();
        $p = $this->sampaiLolos($santri);
        $this->naikkanLaluBerlaku($p, ['tingkat' => 1, 'nominal_uang_pangkal' => '20000000']);

        // Sekarang ia SMP pada T.A2 — dan itu memang benar untuk T.A2.
        Carbon::setTestNow('2027-09-10');
        $santri->refresh();
        $this->assertSame('J002', $santri->kode_jenjang);
        $this->assertSame(4200000.0, (float) (new SppService)->nominalSppSantri($santri->id, self::TA2)['nominal']);

        // Tetapi SPP susulan untuk periode T.A1 harus memakai SDTQ — jenjangnya
        // pada tahun itu, bukan jenjangnya hari ini.
        $susulan = (new SppService)->nominalSppSantri($santri->id, self::TA1);
        $this->assertSame(1500000.0, (float) $susulan['nominal'], 'tarif SDTQ T.A1, bukan SMP');
        $this->assertStringContainsString('SDTQ', $susulan['asal_label']);
        // Akun & unit bisnisnya ikut benar, karena jenis biayanya per jenjang.
        $this->assertSame('J001', \App\Models\JenisBiaya::find($susulan['kode_jenis'])->kode_jenjang);
    }

    /** PPSB baru tuntas SETELAH tahun tujuannya berjalan → langsung berlaku. */
    public function test_ppsb_tuntas_setelah_tahun_mulai_langsung_memindahkan(): void
    {
        $santri = $this->santriKelasAkhir();
        $p = $this->sampaiLolos($santri);

        Carbon::setTestNow('2027-08-20'); // T.A2 sudah berjalan
        (new PendaftaranLanjutanService)->eksekusiKenaikan($p->id, [
            'tingkat' => 1, 'nominal_uang_pangkal' => '20000000',
        ], $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame('J002', $santri->kode_jenjang);
        $this->assertSame(self::TA2, $santri->tahun_ajaran_berjalan);
    }

    // ---- Alur lewat HTTP ----

    public function test_alur_http_dari_halaman_santri(): void
    {
        $santri = $this->santriKelasAkhir();

        $this->actingAs($this->admin)->get(route('santri.show', $santri->id))
            ->assertOk()->assertSee('Jenjang Lanjutan')->assertSee('Daftarkan ke Jenjang Lanjutan');

        $this->actingAs($this->admin)
            ->post(route('pendaftaran_lanjutan.store', $santri->id), ['tahun_ajaran' => self::TA2])
            ->assertRedirect(route('santri.show', $santri->id));

        $p = Pendaftaran::where('id_santri', $santri->id)->lanjutan()->firstOrFail();
        (new PendaftaranLanjutanService)->tandaiRegistrasiLunas($santri->id, 'J002', self::TA2);

        foreach ([['seleksi', ['nilai_baca' => 80]], ['pengumuman', ['lulus' => 1]], ['medcheck', ['lolos' => 1]]] as [$aksi, $isi]) {
            $this->actingAs($this->admin)->post(
                route('pendaftaran_lanjutan.aksi', ['id' => $santri->id, 'pendaftaran' => $p->id, 'aksi' => $aksi]), $isi,
            )->assertRedirect();
        }
        $this->assertSame('lolos_kesehatan', $p->refresh()->status);

        $this->actingAs($this->admin)->post(
            route('pendaftaran_lanjutan.aksi', ['id' => $santri->id, 'pendaftaran' => $p->id, 'aksi' => 'naik']),
            ['tingkat' => 1, 'nominal_uang_pangkal' => '20000000'],
        )->assertRedirect();

        $this->assertSame('naik', $p->refresh()->status);
        // Siklusnya tuntas, tetapi santrinya BELUM pindah: T.A tujuan belum mulai.
        $this->assertSame('J001', $santri->refresh()->kode_jenjang, 'perpindahan menunggu tahun ajarannya');
        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA2, 'status' => 'siap',
        ]);

        // Tahun barunya tiba → membuka daftar santri saja sudah menyalakannya.
        Carbon::setTestNow('2027-07-01');
        $this->actingAs($this->admin)->get(route('santri.aktif'))->assertOk();
        $this->assertSame('J002', $santri->refresh()->kode_jenjang);
    }
}
