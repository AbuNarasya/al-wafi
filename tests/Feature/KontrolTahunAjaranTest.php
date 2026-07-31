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
use App\Models\User;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\TahunAjaranService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * KONTROL TAHUN AJARAN.
 *
 * Aturan yang dijaga:
 *  • SPP dicap tahun ajaran PERIODE-nya, bukan tahun santrinya. Dulu SPP Juli
 *    2026 tercap 2027/2028 hanya karena santrinya terdaftar untuk tahun itu.
 *  • Menerbitkan di luar tahun berjalan tetap boleh — bulan terlewat memang
 *    terjadi — tetapi harus disengaja: alasannya wajib & tercatat.
 *  • PPSB boleh maju, tak boleh mundur.
 *  • PEMBAYARAN tak pernah dikunci: tunggakan lintas tahun harus tetap lunas.
 */
class KontrolTahunAjaranTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZTA';

    private const PEND = '4.ZZTA.PEND';

    private const PIUT = '1.ZZTA.PIUT';

    private const KAS = '1.ZZTA.KAS';

    private const UNIT = 'ZZTAU';

    /** Tahun yang SEDANG BERJALAN pada tanggal uji. */
    private const TA_KINI = '2026/2027';

    private const TA_DEPAN = '2027/2028';

    private const TA_LALU = '2025/2026';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Tanggal dibekukan supaya "tahun berjalan" tak bergeser bersama kalender.
        Carbon::setTestNow('2026-09-15');

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'TA']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet'], [self::KAS, 'Kas', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        \App\Models\BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);

        // Tanggalnya diisi sendiri oleh model dari kodenya (1 Juli – 30 Juni).
        foreach ([self::TA_LALU, self::TA_KINI, self::TA_DEPAN] as $kode) {
            TahunAjaran::create(['kode' => $kode, 'status' => 'aktif',
                'default_pendaftaran' => $kode === self::TA_DEPAN]);
        }

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true])->id_pengguna;

        foreach ([self::TA_LALU, self::TA_KINI, self::TA_DEPAN] as $kode) {
            $this->pasangTarif($kode, 'SD', null, 'registrasi', '500000');
            $this->pasangTarif($kode, 'SD', null, 'spp', '250000');
        }
        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT]);
        $this->buatBiaya(['kode' => 'SPP', 'nama' => 'SPP', 'tipe' => 'spp', 'berulang' => true,
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function santriAktif(string $ta = self::TA_KINI): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => $ta, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tingkat' => 1]);

        return $santri->refresh();
    }

    /** Jangkar: tahun berjalan diturunkan dari kalender, bukan dari flag pendaftaran. */
    public function test_tahun_berjalan_dari_kalender_bukan_flag(): void
    {
        $svc = new TahunAjaranService;

        $this->assertSame(self::TA_KINI, $svc->berjalan()->kode);
        // Flag `default_pendaftaran` menunjuk tahun DEPAN — sengaja berbeda.
        $this->assertSame(self::TA_DEPAN, $svc->defaultPendaftaran()->kode);

        $this->assertSame(self::TA_KINI, $svc->yangMemuatPeriode('2026-09')->kode);
        $this->assertSame(self::TA_LALU, $svc->yangMemuatPeriode('2026-05')->kode);
        $this->assertSame([], $svc->celahKalender());
    }

    /** Kalender harus rapat: tanggal kembar & rentang tumpang tindih ditolak. */
    public function test_master_menolak_rentang_cacat(): void
    {
        $svc = new TahunAjaranService;

        try {
            $svc->create(['kode' => '2033/2034', 'status' => 'aktif',
                'tanggal_mulai' => '2033-07-01', 'tanggal_selesai' => '2033-07-01']);
            $this->fail('tanggal selesai = mulai seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('SETELAH tanggal mulai', $e->getMessage());
        }

        try {
            $svc->create(['kode' => '2026/2027-B', 'status' => 'aktif',
                'tanggal_mulai' => '2026-09-01', 'tanggal_selesai' => '2027-03-31']);
            $this->fail('rentang tumpang tindih seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('bertumpang tindih', $e->getMessage());
        }
    }

    /** Celah kalender dilaporkan, bukan ditolak — masternya yang perlu dilengkapi. */
    public function test_celah_kalender_terdeteksi(): void
    {
        TahunAjaran::where('kode', self::TA_KINI)->delete();

        $celah = (new TahunAjaranService)->celahKalender();
        $this->assertCount(1, $celah);
        $this->assertStringContainsString('2025/2026', $celah[0]);
        $this->assertStringContainsString('2027/2028', $celah[0]);
    }

    /**
     * INTI: tagihan SPP dicap tahun ajaran PERIODE-nya, walau santrinya
     * terdaftar untuk tahun yang lain.
     */
    public function test_spp_dicap_tahun_ajaran_periodenya(): void
    {
        // Santri terdaftar untuk tahun DEPAN — persis keadaan yang dulu menipu.
        $santri = $this->santriAktif(self::TA_DEPAN);
        $santri->update(['tahun_ajaran_berjalan' => self::TA_DEPAN]);

        (new SppService)->generate(['periode' => '2026-09', 'tanggal' => '2026-09-01'], $this->admin);

        $t = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'spp')->sole();
        $this->assertSame(self::TA_KINI, $t->tahun_ajaran, 'T.A harus mengikuti periode, bukan santri');
    }

    /** Periode yang tak dimiliki tahun ajaran mana pun ditolak dengan sebab yang jelas. */
    public function test_periode_tanpa_tahun_ajaran_ditolak(): void
    {
        $this->santriAktif();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Belum ada tahun ajaran yang memuat periode 2019-03/');
        (new SppService)->pratinjau('2019-03');
    }

    /** Lintas tahun ajaran: ditolak tanpa alasan, diterima dengan alasan, dan tercatat. */
    public function test_lintas_tahun_ajaran_butuh_alasan_dan_tercatat(): void
    {
        $santri = $this->santriAktif();

        // 2026-05 masuk T.A 2025/2026 — bukan tahun berjalan.
        try {
            (new SppService)->generate(['periode' => '2026-05', 'tanggal' => '2026-09-01'], $this->admin);
            $this->fail('penerbitan lintas T.A tanpa alasan seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('alasannya wajib diisi', $e->getMessage());
        }

        $hasil = (new SppService)->generate([
            'periode' => '2026-05', 'tanggal' => '2026-09-01',
            'alasan_lintas_ta' => 'menyusul SPP Mei yang terlewat',
        ], $this->admin);

        $this->assertTrue($hasil['lintas_ta']);
        $this->assertSame(self::TA_LALU, $hasil['tahun_ajaran']);
        $this->assertSame(self::TA_LALU, TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'spp')->sole()->tahun_ajaran);

        $this->assertDatabaseHas('activity_log', ['aksi' => 'terbitkan_spp_lintas_tahun_ajaran']);
    }

    /** Periode di dalam tahun berjalan tidak menuntut alasan apa pun. */
    public function test_periode_tahun_berjalan_tidak_butuh_alasan(): void
    {
        $this->santriAktif();

        $hasil = (new SppService)->generate(['periode' => '2026-09', 'tanggal' => '2026-09-01'], $this->admin);

        $this->assertFalse($hasil['lintas_ta']);
        $this->assertSame(self::TA_KINI, $hasil['tahun_ajaran']);
    }

    /** PPSB boleh mendaftarkan untuk tahun depan, tak boleh mundur ke tahun lewat. */
    public function test_ppsb_boleh_maju_tidak_boleh_mundur(): void
    {
        $this->santriAktif(self::TA_DEPAN); // tahun depan → lolos

        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah lewat/');
        (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Mundur', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA_LALU, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
    }

    /**
     * PEMBAYARAN tak pernah dikunci tahun ajaran — tunggakan lintas tahun harus
     * tetap bisa dilunasi. Inilah batas yang membedakan kontrol ini dari
     * penghalang: yang dijaga penerbitannya, bukan pelunasannya.
     */
    public function test_tunggakan_tahun_lalu_tetap_bisa_dibayar(): void
    {
        $santri = $this->santriAktif();
        (new SppService)->generate([
            'periode' => '2026-05', 'tanggal' => '2026-09-01',
            'alasan_lintas_ta' => 'susulan',
        ], $this->admin);

        $t = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'spp')->sole();
        $this->assertSame(self::TA_LALU, $t->tahun_ajaran);

        $svc = new \App\Services\Modules\PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $t->id, 'tanggal' => '2026-09-20',
            'nominal' => '250000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');
        $svc->verifikasi($p->id, $this->admin);

        $this->assertSame('lunas', $t->refresh()->status);
    }

    /** Rentang tanggal diisikan sendiri dari kodenya — sumber salah-ketik yang lama. */
    public function test_tanggal_tahun_ajaran_terisi_dari_kode(): void
    {
        $ta = TahunAjaran::create(['kode' => '2040/2041', 'status' => 'aktif']);

        $this->assertSame('2040-07-01', $ta->tanggal_mulai->toDateString());
        $this->assertSame('2041-06-30', $ta->tanggal_selesai->toDateString());

        // Yang disebut eksplisit tak ditimpa.
        $lain = TahunAjaran::create(['kode' => '2041/2042', 'status' => 'aktif',
            'tanggal_mulai' => '2041-08-15', 'tanggal_selesai' => '2042-07-14']);
        $this->assertSame('2041-08-15', $lain->tanggal_mulai->toDateString());
    }

    // ---- Kenaikan tingkat: kalender ikut menjaganya ----

    /**
     * Dulu modul ini TIDAK punya penjaga kalender sama sekali — controllernya
     * hanya `exists:tahun_ajaran,kode`. Akibatnya kenaikan bisa dijalankan ke
     * tahun yang masih lima tahun lagi, MUNDUR ke tahun yang sudah lewat, atau
     * ke T.A nonaktif. Padahal keputusan "Melanjutkan" dalam batch yang sama
     * sudah tertolak, karena ia lewat PendaftaranLanjutanService yang terjaga.
     */
    public function test_kenaikan_dibatasi_tahun_berjalan_atau_berikutnya(): void
    {
        $svc = new \App\Services\Modules\KenaikanTingkatService;

        // Tahun BERJALAN sah: kenaikan yang telat dikerjakan setelah 1 Juli.
        $this->assertIsArray($svc->pratinjau(['tahun_ajaran' => self::TA_KINI, 'kode_jenjang' => 'SD']));
        // Tahun BERIKUTNYA sah: persiapan sebelum tahunnya mulai.
        $this->assertIsArray($svc->pratinjau(['tahun_ajaran' => self::TA_DEPAN, 'kode_jenjang' => 'SD']));

        // MUNDUR ke tahun yang sudah lewat.
        try {
            $svc->pratinjau(['tahun_ajaran' => self::TA_LALU, 'kode_jenjang' => 'SD']);
            $this->fail('T.A yang sudah lewat seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('hanya boleh untuk tahun ajaran berjalan', $e->getMessage());
        }

        // MELOMPAT jauh ke depan.
        TahunAjaran::create(['kode' => '2030/2031', 'status' => 'aktif']);
        try {
            $svc->pratinjau(['tahun_ajaran' => '2030/2031', 'kode_jenjang' => 'SD']);
            $this->fail('T.A yang melompat jauh seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('bukan 2030/2031', $e->getMessage());
        }

        // NONAKTIF — `exists` di controller tak menyaring status.
        TahunAjaran::where('kode', self::TA_DEPAN)->update(['status' => 'nonaktif', 'default_pendaftaran' => false]);
        try {
            $svc->pratinjau(['tahun_ajaran' => self::TA_DEPAN, 'kode_jenjang' => 'SD']);
            $this->fail('T.A nonaktif seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('nonaktif', $e->getMessage());
        }
    }

    /** Penjaganya ditegakkan di EKSEKUSI juga — kiriman bisa datang tanpa pratinjau. */
    public function test_batas_tahun_ajaran_juga_ditegakkan_saat_menetapkan(): void
    {
        $santri = $this->santriAktif();
        $santri->update(['tahun_ajaran_berjalan' => self::TA_KINI]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/hanya boleh untuk tahun ajaran berjalan/');
        (new \App\Services\Modules\KenaikanTingkatService)->tetapkan(
            self::TA_LALU,
            [$santri->id => \App\Services\Modules\KenaikanTingkatService::NAIK],
            $this->admin,
        );
    }

    /**
     * Santri yang tahun berjalannya tertinggal DUA tahun tak boleh ikut terangkat
     * ke sasaran batch: tahun yang terlewat tak akan pernah punya baris
     * `riwayat_tingkat`, dan bolong itu tak bisa diperbaiki dari layar mana pun.
     * Dilewati — bukan membatalkan seluruh batch — dan pesannya menyebutkan jalan
     * keluarnya.
     */
    public function test_santri_yang_lompat_lebih_dari_setahun_dilewati(): void
    {
        $tertinggal = $this->santriAktif();
        $tertinggal->update(['tahun_ajaran_berjalan' => self::TA_LALU]);
        $wajar = $this->santriAktif();
        $wajar->update(['tahun_ajaran_berjalan' => self::TA_KINI]);

        $svc = new \App\Services\Modules\KenaikanTingkatService;
        $baris = collect($svc->pratinjau(['tahun_ajaran' => self::TA_DEPAN, 'kode_jenjang' => 'SD'])['baris'])
            ->keyBy('id');

        $this->assertSame('lewati', $baris[$tertinggal->id]['usul']);
        $this->assertStringContainsString('melompati 1 tahun', $baris[$tertinggal->id]['alasan']);
        $this->assertStringContainsString('Koreksi Tahun Berjalan', $baris[$tertinggal->id]['alasan']);
        // Yang selisihnya wajar tetap diusulkan naik — satu santri bermasalah
        // tidak menahan seluruh angkatan.
        $this->assertSame('naik', $baris[$wajar->id]['usul']);

        // Dan kalau tetap dikirim langsung ke eksekusi, ditolak.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/melompati 1 tahun/');
        $svc->tetapkan(self::TA_DEPAN, [$tertinggal->id => \App\Services\Modules\KenaikanTingkatService::NAIK], $this->admin);
    }

    /**
     * PINTU KELUARNYA: koreksi `tahun_ajaran_berjalan`. Tanpa ini, santri yang
     * dilewati di atas macet selamanya — batch ke tahun perantara ditolak karena
     * tahun itu sudah lewat, dan kolomnya tak ada di form sunting santri.
     */
    public function test_koreksi_tahun_berjalan_membuka_jalan_kenaikan(): void
    {
        $santri = $this->santriAktif();
        $santri->update(['tahun_ajaran_berjalan' => self::TA_LALU]);

        $this->actingAs(\App\Models\User::find($this->admin))
            ->post(route('santri.aksi', ['id' => $santri->id, 'aksi' => 'set-tahun-berjalan']),
                ['tahun_ajaran_berjalan' => self::TA_KINI])
            ->assertRedirect();

        $santri->refresh();
        $this->assertSame(self::TA_KINI, $santri->tahun_ajaran_berjalan);
        // Angkatan & tingkat SENGAJA tak ikut berubah — ini koreksi satu kolom,
        // bukan kenaikan tingkat lewat pintu belakang.
        $this->assertSame(self::TA_KINI, $santri->tahun_ajaran);
        $this->assertSame(1, $santri->tingkat);

        // Sesudah dikoreksi, ia tak lagi dilewati.
        $baris = collect((new \App\Services\Modules\KenaikanTingkatService)
            ->pratinjau(['tahun_ajaran' => self::TA_DEPAN, 'kode_jenjang' => 'SD'])['baris'])->keyBy('id');
        $this->assertSame('naik', $baris[$santri->id]['usul']);
    }

    /** T.A yang tak terdaftar / nonaktif ditolak koreksinya. */
    public function test_koreksi_tahun_berjalan_menolak_ta_nonaktif(): void
    {
        $santri = $this->santriAktif();
        TahunAjaran::where('kode', self::TA_DEPAN)->update(['status' => 'nonaktif', 'default_pendaftaran' => false]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/nonaktif/');
        (new SantriService)->setTahunBerjalan($santri->id, self::TA_DEPAN);
    }
}
