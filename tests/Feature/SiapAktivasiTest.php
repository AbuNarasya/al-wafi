<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JadwalPerubahanSantri;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Services\Modules\JadwalPerubahanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * CALON SIAP AKTIVASI — ditandai sekarang, aktif saat tahun ajarannya dimulai.
 *
 * Dulu tombol "Mutasi → Santri Aktif" mengerjakan enam hal dalam satu tekan,
 * termasuk MEMPOSTING JURNAL akrual. Akibatnya calon yang berkasnya tuntas bulan
 * Mei sudah menjadi santri aktif — ikut terhitung penagihan SPP dan kenaikan
 * tingkat — padahal tahun ajarannya baru mulai 1 Juli, dan pendapatan tahun itu
 * sudah diakui di tahun sebelumnya.
 *
 * Yang dijaga di sini:
 *  • menandai siap TIDAK memposting jurnal apa pun & tidak mengaktifkan;
 *  • jurnalnya terbit tepat saat aktivasinya menyala;
 *  • calon yang MUNDUR pada masa itu tak meninggalkan jurnal yang perlu dibalik
 *    — inilah yang membuat `mengundurkanDiri` tetap boleh bercabang sederhana;
 *  • tombol manual & aktivasi massal untuk yang masuk di tengah tahun ajaran.
 */
class SiapAktivasiTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZSA';

    private const PEND = '4.ZZSA.PEND';

    private const PIUT = '1.ZZSA.PIUT';

    private const KAS = '1.ZZSA.KAS';

    private const UNIT = 'ZZSAU';

    /** T.A yang sedang BERJALAN pada tanggal uji. */
    private const TA_KINI = '2026/2027';

    /** T.A yang akan datang — calon didaftarkan ke sini. */
    private const TA_DEPAN = '2027/2028';

    /** Tanggal uji: masih di dalam T.A kini, jadi T.A depan belum mulai. */
    private const HARI_UJI = '2027-05-10';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::HARI_UJI);
        TipeBiaya::lupakan();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Siap Aktivasi Uji']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet'], [self::KAS, 'Kas', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        // Tanggalnya diisi sendiri oleh model dari kodenya (1 Juli – 30 Juni).
        foreach ([self::TA_KINI, self::TA_DEPAN] as $kode) {
            TahunAjaran::create(['kode' => $kode, 'status' => 'aktif',
                'default_pendaftaran' => $kode === self::TA_DEPAN]);
        }
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA_DEPAN]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '5000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT,
            'tahun_ajaran' => self::TA_DEPAN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Calon yang sudah lolos med check & uang pangkalnya sudah ditagihkan. */
    private function calonSiap(string $ta = self::TA_DEPAN): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => $ta, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'tingkat' => 1, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'lolos_kesehatan']);
        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'komponen' => ['uang_pangkal'], 'nominal' => '5000000', 'tahun_ajaran' => $ta,
        ]);

        return $santri->refresh();
    }

    private function jurnalAkrual(): int
    {
        return JournalEntry::where('sumber_modul', 'PembayaranSantri')->count();
    }

    // ---- Menandai siap: belum ada akibat apa pun ----

    public function test_menandai_siap_tidak_mengaktifkan_dan_tidak_menjurnal(): void
    {
        $santri = $this->calonSiap();

        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame('siap_aktivasi', $santri->status);
        $this->assertNull($santri->tahun_ajaran_berjalan, 'tahun berjalan belum boleh terisi');
        $this->assertSame(0, $this->jurnalAkrual(), 'belum boleh ada jurnal akrual');
        $this->assertFalse((bool) TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'uang_pangkal')->sole()->sudah_akrual);
        // Riwayat tingkatnya lahir bersama aktivasinya, bukan sebelum itu.
        $this->assertSame(0, RiwayatTingkat::where('id_santri', $santri->id)->count());

        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'tahun_ajaran' => self::TA_DEPAN,
            'keputusan' => 'aktivasi', 'status' => 'siap', 'tingkat_tujuan' => 1,
        ]);
    }

    public function test_uang_pangkal_belum_ditagihkan_ditolak(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'B', 'telepon_ayah' => '0812345']);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Belum Ditagih', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA_DEPAN, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'tingkat' => 1, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'lolos_kesehatan']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Uang pangkal belum ditagihkan');
        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);
    }

    // ---- Jadwalnya menyala saat tahun ajarannya dimulai ----

    public function test_aktivasi_menyala_saat_tahun_ajarannya_dimulai(): void
    {
        $santri = $this->calonSiap();
        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);

        // Sehari sebelum tahun barunya: masih belum menyala.
        Carbon::setTestNow('2027-06-30');
        $this->assertSame(0, (new JadwalPerubahanService)->terapkanYangJatuhTempo()['diterapkan']);
        $this->assertSame('siap_aktivasi', $santri->refresh()->status);
        $this->assertSame(0, $this->jurnalAkrual());

        // Hari pertama T.A tujuan: menyala, berikut jurnalnya.
        Carbon::setTestNow('2027-07-01');
        $this->assertSame(1, (new JadwalPerubahanService)->terapkanYangJatuhTempo()['diterapkan']);

        $santri->refresh();
        $this->assertSame('aktif', $santri->status);
        $this->assertSame(self::TA_DEPAN, $santri->tahun_ajaran_berjalan);
        $this->assertSame(1, $this->jurnalAkrual(), 'jurnal akrual terbit SEKARANG, bukan saat ditandai');
        $this->assertTrue((bool) TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'uang_pangkal')->sole()->sudah_akrual);
        // Baris pertama riwayat tingkatnya ikut lahir.
        $this->assertSame(1, RiwayatTingkat::where('id_santri', $santri->id)
            ->where('tahun_ajaran', self::TA_DEPAN)->count());
        $this->assertDatabaseHas('jadwal_perubahan_santri', [
            'id_santri' => $santri->id, 'status' => 'diterapkan',
        ]);
    }

    /** Idempoten — penerapnya dipanggil dari cron DAN tiap halaman dibuka. */
    public function test_penerap_tidak_menjurnal_dua_kali(): void
    {
        $santri = $this->calonSiap();
        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);

        Carbon::setTestNow('2027-07-01');
        $svc = new JadwalPerubahanService;
        $this->assertSame(1, $svc->terapkanYangJatuhTempo()['diterapkan']);
        $this->assertSame(0, $svc->terapkanYangJatuhTempo()['diterapkan']);
        $this->assertSame(0, $svc->terapkanYangJatuhTempo()['diterapkan']);

        $this->assertSame(1, $this->jurnalAkrual());
    }

    /**
     * Calon yang mendaftar di TENGAH tahun ajaran berjalan langsung aktif —
     * tahun masuknya sudah dimulai, jadi tak ada gunanya menunggu.
     */
    public function test_calon_tahun_berjalan_langsung_aktif(): void
    {
        $this->pasangTarif(self::TA_KINI, 'SD', null, 'registrasi', '500000');
        $this->pasangTarif(self::TA_KINI, 'SD', null, 'uang_pangkal', '5000000');
        $santri = $this->calonSiap(self::TA_KINI);

        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);

        $this->assertSame('aktif', $santri->refresh()->status);
        $this->assertSame(self::TA_KINI, $santri->tahun_ajaran_berjalan);
        $this->assertSame(1, $this->jurnalAkrual());
    }

    // ---- Pengunduran diri sebelum aktivasi ----

    /**
     * INI ALASAN akrualnya ditunda. `mengundurkanDiri` membalik jurnal HANYA
     * untuk status `aktif`; kalau jurnalnya terbit sejak ditandai siap, calon
     * yang mundur akan masuk cabang yang salah — tagihannya ditutup, tetapi
     * pendapatan & piutangnya tetap tercatat untuk orang yang tak pernah masuk.
     */
    public function test_mundur_sebelum_aktivasi_tak_meninggalkan_jurnal(): void
    {
        $santri = $this->calonSiap();
        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);

        (new SantriService)->mengundurkanDiri($santri->id, 'Pindah kota', $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame('mengundurkan_diri', $santri->status);
        $this->assertSame(0, $this->jurnalAkrual(), 'tak ada jurnal, jadi tak ada yang perlu dibalik');
        $this->assertSame(0, JournalEntry::count(), 'tak ada jurnal pembalik pula');

        // Dan jadwalnya tak boleh menyala belakangan.
        Carbon::setTestNow('2027-07-01');
        $hasil = (new JadwalPerubahanService)->terapkanYangJatuhTempo();
        $this->assertSame(0, $hasil['diterapkan']);
        $this->assertNotEmpty($hasil['gagal'], 'ditolak dengan sebab, bukan diam-diam dilewati');
        $this->assertSame('mengundurkan_diri', $santri->refresh()->status);
    }

    // ---- Layar & tombol ----

    public function test_daftar_terpisah_dari_calon_santri(): void
    {
        $santri = $this->calonSiap();
        (new SantriService)->siapkanAktivasi($santri->id, $this->admin->id_pengguna);

        // Sudah selesai diurus → tak lagi menaikkan angka "calon yang diproses".
        $this->actingAs($this->admin)->get(route('santri.calon'))->assertOk()
            ->assertDontSee($santri->no_pendaftaran);

        $this->actingAs($this->admin)->get(route('santri.siap_aktivasi'))->assertOk()
            ->assertSee('Calon Santri Siap Aktivasi')
            ->assertSee($santri->no_pendaftaran)
            ->assertSee('Menunggu tahun ajarannya');
    }

    public function test_tombol_siap_lalu_aktifkan_sekarang_lewat_http(): void
    {
        $santri = $this->calonSiap();

        $this->actingAs($this->admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('Siap di Aktifkan', false);

        $this->actingAs($this->admin)->post(
            route('santri.aksi', ['id' => $santri->id, 'aksi' => 'siap-aktivasi']),
        )->assertRedirect();
        $this->assertSame('siap_aktivasi', $santri->refresh()->status);
        $this->assertSame(0, $this->jurnalAkrual());

        // Tombol manual: aktif hari itu juga, tanpa menunggu 1 Juli.
        $this->actingAs($this->admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('Aktifkan Sekarang', false);

        $this->actingAs($this->admin)->post(
            route('santri.aksi', ['id' => $santri->id, 'aksi' => 'aktifkan-sekarang']),
        )->assertRedirect();

        $this->assertSame('aktif', $santri->refresh()->status);
        $this->assertSame(1, $this->jurnalAkrual());
    }

    public function test_aktivasi_massal(): void
    {
        $a = $this->calonSiap();
        $b = $this->calonSiap();
        $c = $this->calonSiap(); // ini TIDAK dicentang
        foreach ([$a, $b, $c] as $s) {
            (new SantriService)->siapkanAktivasi($s->id, $this->admin->id_pengguna);
        }

        $this->actingAs($this->admin)->post(route('santri.aktivasi_massal'), [
            'id_santri' => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertSame('aktif', $a->refresh()->status);
        $this->assertSame('aktif', $b->refresh()->status);
        $this->assertSame('siap_aktivasi', $c->refresh()->status, 'yang tak dicentang tak ikut');
        $this->assertSame(2, $this->jurnalAkrual());

        // Jadwal keduanya ditandai diterapkan, jadi 1 Juli nanti tak menyala lagi.
        $this->assertSame(2, JadwalPerubahanSantri::whereIn('id_santri', [$a->id, $b->id])
            ->where('status', 'diterapkan')->count());
    }
}
