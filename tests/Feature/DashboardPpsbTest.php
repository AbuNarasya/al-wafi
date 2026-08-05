<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TargetSantri;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\PpsbDashboardService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard PPSB — enam angka + pemisahan hak akses antar tab.
 *
 * Aturan yang dikunci: yang menentukan terhitung adalah PEMBAYARAN TERVERIFIKASI,
 * bukan input data; bulan diambil dari tanggal pembayaran; dan tab keuangan vs
 * PPSB benar-benar terpisah haknya.
 */
class DashboardPpsbTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZDB';
    private const KAS = '1.ZZDB.KAS';
    private const PEND = '4.ZZDB.PEND';
    private const PIUT = '1.ZZDB.PIUT';
    private const UNIT = 'ZZDBU';
    private const TA = '2027/2028';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'DB']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '1000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
        $this->buatBiaya([
            'kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '10000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function calon(string $nama, string $jk = 'L'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);

        return (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => $jk, 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'sumber_informasi' => 'medsos',
        ]);
    }

    /** Bayar tagihan bertipe $tipe; $verifikasi=false meninggalkannya menunggu. */
    private function bayar(Santri $santri, string $tipe, string $nominal, string $tanggal, bool $verifikasi = true): void
    {
        $tagihan = $santri->tagihan()->whereHas('jenis', fn ($q) => $q->where('tipe', $tipe))->firstOrFail();
        $bayar = (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $tagihan->id, 'tanggal' => $tanggal,
            'nominal' => $nominal, 'kode_rekening' => self::KAS, 'metode' => 'tunai',
        ], (int) $this->admin->id_pengguna, 'ppsb');

        if ($verifikasi) {
            (new PembayaranSantriService)->verifikasi($bayar->id, (int) $this->admin->id_pengguna);
        }
    }

    private function tagihkanUp(Santri $santri): void
    {
        $santri->update(['status' => 'diterima']);
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '10000000']);
    }

    /** Musim penerimaan T.A 2027/2028 = Jul 2026 … Jun 2027. */
    public function test_kolom_bulan_mengikuti_musim_penerimaan_bukan_tahun_ajaran(): void
    {
        $bulan = (new PpsbDashboardService)->bulanTa(self::TA);

        $this->assertCount(12, $bulan);
        $this->assertSame('2026-07', $bulan[0]['kunci']);
        $this->assertSame('2027-06', $bulan[11]['kunci']);
    }

    public function test_pendaftar_hanya_yang_sudah_membayar_registrasi(): void
    {
        $bayar = $this->calon('SudahBayar');
        $this->bayar($bayar, 'registrasi', '1000000', '2026-09-10');
        $this->calon('BaruInput'); // tak pernah membayar

        $tabel = (new PpsbDashboardService)->tabelBulanan(self::TA, 'pendaftar');
        $smp = collect($tabel['baris'])->firstWhere('kode', 'SMP');

        $this->assertSame(1, $smp['total'], 'Calon yang baru diinput tak boleh ikut terhitung.');
        $this->assertSame(1, $smp['sel']['2026-09'], 'Masuk bulan pembayaran registrasinya.');
        $this->assertSame(0, $smp['sel']['2026-10']);
    }

    public function test_pembayaran_menunggu_verifikasi_belum_dihitung(): void
    {
        $santri = $this->calon('Menunggu');
        $this->bayar($santri, 'registrasi', '1000000', '2026-09-10', verifikasi: false);

        $tabel = (new PpsbDashboardService)->tabelBulanan(self::TA, 'pendaftar');
        $this->assertSame(0, $tabel['total']['total']);
    }

    public function test_closing_dihitung_saat_uang_pangkal_mulai_dibayar(): void
    {
        $santri = $this->calon('Closing');
        $this->bayar($santri, 'registrasi', '1000000', '2026-09-10');
        $this->tagihkanUp($santri);

        $svc = new PpsbDashboardService;
        $this->assertSame(0, $svc->tabelBulanan(self::TA, 'closing')['total']['total'], 'Ditagih saja belum berarti closing.');

        // Cicilan pertama saja sudah cukup untuk disebut closing.
        $this->bayar($santri, 'uang_pangkal', '2500000', '2026-11-05');
        $tabel = $svc->tabelBulanan(self::TA, 'closing');
        $smp = collect($tabel['baris'])->firstWhere('kode', 'SMP');

        $this->assertSame(1, $smp['total']);
        $this->assertSame(1, $smp['sel']['2026-11']);
    }

    public function test_outstanding_hanya_yang_sudah_mulai_membayar(): void
    {
        $mulai = $this->calon('SudahMulai');
        $this->tagihkanUp($mulai);
        $this->bayar($mulai, 'uang_pangkal', '4000000', '2026-11-05');

        $belum = $this->calon('BelumSamaSekali');
        $this->tagihkanUp($belum); // ditagih 10jt, tak pernah membayar

        $hasil = (new PpsbDashboardService)->outstandingClosing(self::TA);

        // Hanya sisa milik yang sudah mulai membayar: 10jt − 4jt = 6jt.
        $this->assertSame('6000000.00', $hasil['total']);
        $this->assertSame(1, $hasil['jumlah_santri']);
    }

    public function test_penerimaan_memisahkan_registrasi_dan_uang_pangkal(): void
    {
        $santri = $this->calon('Bayar');
        $this->bayar($santri, 'registrasi', '1000000', '2026-09-10');
        $this->tagihkanUp($santri);
        $this->bayar($santri, 'uang_pangkal', '2500000', '2026-11-05');
        $this->bayar($santri, 'uang_pangkal', '1500000', '2026-12-05'); // cicilan berikutnya

        $hasil = (new PpsbDashboardService)->penerimaan(self::TA);

        $this->assertSame('1000000.00', $hasil['registrasi']);
        $this->assertSame('4000000.00', $hasil['uang_pangkal']);
        $this->assertSame('5000000.00', $hasil['total']);
    }

    public function test_plan_vs_aktual_per_jenis_kelamin(): void
    {
        TargetSantri::create([
            'tahun_ajaran' => self::TA, 'kode_jenjang' => 'SMP',
            'target' => 5, 'target_l' => 3, 'target_p' => 2,
        ]);

        $l = $this->calon('Laki', 'L');
        $this->tagihkanUp($l);
        $this->bayar($l, 'uang_pangkal', '1000000', '2026-11-05');
        $p = $this->calon('Perempuan', 'P');
        $this->tagihkanUp($p);
        $this->bayar($p, 'uang_pangkal', '1000000', '2026-11-06');
        $this->calon('BelumClosing', 'P'); // tak ikut aktual

        $baris = collect((new PpsbDashboardService)->planVsAktual(self::TA)['baris'])->firstWhere('kode', 'SMP');

        $this->assertSame(3, $baris['target_l']);
        $this->assertSame(2, $baris['target_p']);
        $this->assertSame(1, $baris['aktual_l']);
        $this->assertSame(1, $baris['aktual_p']);
        $this->assertSame(-3, $baris['selisih']);
        $this->assertSame(40.0, $baris['persen']);
    }

    public function test_pencapaian_dihitung_per_jenis_kelamin(): void
    {
        TargetSantri::create([
            'tahun_ajaran' => self::TA, 'kode_jenjang' => 'SMP',
            'target' => 5, 'target_l' => 4, 'target_p' => 1,
        ]);
        $l = $this->calon('Ikhwan', 'L');
        $this->tagihkanUp($l);
        $this->bayar($l, 'uang_pangkal', '1000000', '2026-11-05');

        $baris = collect((new PpsbDashboardService)->planVsAktual(self::TA)['baris'])->firstWhere('kode', 'SMP');

        $this->assertSame(25.0, $baris['persen_l'], '1 dari target 4 ikhwan = 25%.');
        $this->assertSame(0.0, $baris['persen_p']);
        $this->assertSame(20.0, $baris['persen']);
    }

    /** Pencapaian null (bukan 0%) bila targetnya memang belum diisi — beda arti. */
    public function test_pencapaian_kosong_saat_target_belum_dirinci(): void
    {
        TargetSantri::create(['tahun_ajaran' => self::TA, 'kode_jenjang' => 'SMP', 'target' => 5]);

        $baris = collect((new PpsbDashboardService)->planVsAktual(self::TA)['baris'])->firstWhere('kode', 'SMP');

        $this->assertNull($baris['persen_l']);
        $this->assertNull($baris['persen_p']);
        $this->assertSame(0.0, $baris['persen']);
    }

    public function test_sebaran_jalur_per_jenis_kelamin_dan_jenjang(): void
    {
        JalurPendaftaran::create(['kode' => 'PDH', 'nama' => 'Pindahan', 'tahun_ajaran' => self::TA]);
        // Jalur yang lahir setelah tarif dipasang harus punya selnya sendiri —
        // biaya masuk tak lagi punya baris cadangan "semua jalur".
        foreach (['registrasi' => '500000', 'uang_pangkal' => '25000000', 'perlengkapan' => '8000000'] as $perilaku => $nominal) {
            $this->pasangTarif(self::TA, 'SMP', 'PDH', $perilaku, $nominal);
        }

        $ikhwan = $this->calon('Ikhwan', 'L');
        $this->tagihkanUp($ikhwan);
        $this->bayar($ikhwan, 'uang_pangkal', '1000000', '2026-11-05');

        $akhwat = $this->calon('Akhwat', 'P');
        $akhwat->update(['jalur' => 'PDH']);
        $this->tagihkanUp($akhwat);
        $this->bayar($akhwat, 'uang_pangkal', '1000000', '2026-11-06');

        $hasil = (new PpsbDashboardService)->sebaranJalur(self::TA);
        $reg = collect($hasil['baris'])->firstWhere('kode', 'reguler');
        $pdh = collect($hasil['baris'])->firstWhere('kode', 'PDH');

        $this->assertSame(1, $reg['sel']['L']['SMP']);
        $this->assertSame(0, $reg['sel']['P']['SMP']);
        $this->assertSame(1, $pdh['sel']['P']['SMP']);
        $this->assertSame(2, $hasil['total']['total']);
        // Jalur tanpa pendaftar tetap muncul sebagai baris nol — itu informasi.
        $this->assertNotNull(collect($hasil['baris'])->firstWhere('kode', 'PDH'));
    }

    /** Tren membandingkan JENJANG: tiap jenjang satu garis pada sumbu bulan Juli→Juni. */
    public function test_tren_menyusun_satu_garis_per_jenjang(): void
    {
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SD Tahfizh', 'urutan' => 0]);

        $smp = $this->calon('AnakSMP');
        $this->bayar($smp, 'registrasi', '1000000', '2026-09-10'); // September = indeks 2

        $sdtq = $this->calon('AnakSDTQ');
        $sdtq->update(['kode_jenjang' => 'SDTQ']);
        $this->bayar($sdtq, 'registrasi', '1000000', '2026-08-15'); // Agustus = indeks 1

        $tren = (new PpsbDashboardService)->trenBulanan('pendaftar', self::TA);
        $seri = collect($tren['seri'])->keyBy('label');

        $this->assertSame('Juli', $tren['bulan'][0]);
        $this->assertSame('Juni', $tren['bulan'][11]);
        // Legenda memakai NAMA jenjang, bukan kodenya: "SD Tahfizh", bukan "SDTQ".
        $this->assertSame(1, $seri['SD Tahfizh']['nilai'][1], 'SDTQ memuncak di Agustus.');
        $this->assertSame(1, $seri['SMP']['nilai'][2], 'SMP memuncak di September.');
        $this->assertSame(0, $seri['SMP']['nilai'][1]);
        $this->assertArrayNotHasKey('SDTQ', $seri->all(), 'Kode jenjang tak boleh jadi label grafik.');
        // Angka grafik harus sama persis dengan tabel bulanan di bawahnya.
        $tabel = (new PpsbDashboardService)->tabelBulanan(self::TA, 'pendaftar');
        $this->assertSame(
            collect($tabel['baris'])->firstWhere('kode', 'SMP')['total'],
            $seri['SMP']['total'],
        );
    }

    public function test_sebaran_jalur_bisa_dibaca_dari_sisi_pendaftar(): void
    {
        $santri = $this->calon('HanyaRegistrasi');
        $this->bayar($santri, 'registrasi', '1000000', '2026-09-10');

        $svc = new PpsbDashboardService;
        $this->assertSame(0, $svc->sebaranJalur(self::TA, 'closing')['total']['total']);
        $this->assertSame(1, $svc->sebaranJalur(self::TA, 'pendaftar')['total']['total']);
    }

    public function test_ranking_sumber_informasi_terurut(): void
    {
        foreach ([['A', 'medsos'], ['B', 'medsos'], ['C', 'rekomendasi']] as [$nama, $sumber]) {
            $s = $this->calon($nama);
            $s->update(['sumber_informasi' => $sumber]);
            $this->bayar($s, 'registrasi', '1000000', '2026-09-10');
        }
        $belum = $this->calon('TanpaBayar');
        $belum->update(['sumber_informasi' => 'iklan']); // tak pernah bayar → tak masuk ranking

        $hasil = (new PpsbDashboardService)->sumberInformasi(self::TA);

        $this->assertSame(3, $hasil['total']);
        $this->assertSame('Media Sosial', $hasil['baris'][0]['nama']);
        $this->assertSame(2, $hasil['baris'][0]['jumlah']);
        $this->assertSame('Rekomendasi', $hasil['baris'][1]['nama']);
        $this->assertFalse(
            collect($hasil['baris'])->pluck('kode')->contains('iklan'),
            'Sumber informasi milik calon yang belum membayar registrasi tak boleh masuk ranking.',
        );
    }

    public function test_tab_terpisah_haknya(): void
    {
        // Admin melihat dua tab.
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSee('Keuangan')->assertSee('PPSB');

        // Panitia: hanya berhak atas tab PPSB → tab keuangan tak ditawarkan,
        // dan meminta tab=keuangan pun jatuh kembali ke PPSB (bukan 403).
        $panitia = User::create([
            'username' => 'panitia', 'nama' => 'Panitia', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        HakAksesModul::create([
            'id_pengguna' => $panitia->id_pengguna, 'kode_modul' => 'dashboard-ppsb',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);

        $this->actingAs($panitia)->get('/dashboard?tab=keuangan')->assertOk()
            ->assertSee('Dashboard PPSB')
            ->assertDontSee('Saldo Kas');
    }

    public function test_tanpa_hak_dashboard_sama_sekali_ditolak(): void
    {
        $orang = User::create([
            'username' => 'lain', 'nama' => 'Lain', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);

        $this->actingAs($orang)->get('/dashboard')->assertForbidden();
    }

    /** "Lihat detail" pada kartu → daftar santri penyumbang angkanya. */
    public function test_rincian_kartu_penerimaan_menampilkan_santrinya(): void
    {
        $santri = $this->calon('Penyumbang');
        $this->bayar($santri, 'registrasi', '1000000', '2026-09-10');
        $this->tagihkanUp($santri);
        $this->bayar($santri, 'uang_pangkal', '2500000', '2026-11-05');

        $svc = new PpsbDashboardService;
        $reg = $svc->rincian(self::TA, 'registrasi');
        $this->assertSame(1, $reg->total());
        $this->assertSame('1000000.00', $reg[0]->registrasi);
        $this->assertSame($santri->no_pendaftaran, $reg[0]->no_pendaftaran);

        $total = $svc->rincian(self::TA, 'total');
        $this->assertSame('3500000.00', $total[0]->total, 'Registrasi + uang pangkal.');
        $this->assertSame(2, (int) $total[0]->jumlah_bayar);

        // Uang pangkal saja tak memuat pembayaran registrasi.
        $this->assertSame('2500000.00', $svc->rincian(self::TA, 'uang_pangkal')[0]->uang_pangkal);

        $this->actingAs($this->admin)->get('/dashboard?tab=ppsb&detail=total')->assertOk()
            ->assertSee('Rincian Total Penerimaan')
            ->assertSee($santri->no_pendaftaran)
            ->assertSee(route('rekap_pembayaran.show', $santri->id), false);
    }

    /** Rincian outstanding hanya memuat yang sudah mulai membayar & masih bersisa. */
    public function test_rincian_outstanding_mengikuti_definisi_kartunya(): void
    {
        $mulai = $this->calon('SudahMulai');
        $this->tagihkanUp($mulai);
        $this->bayar($mulai, 'uang_pangkal', '4000000', '2026-11-05');

        $belum = $this->calon('BelumBayar');
        $this->tagihkanUp($belum);

        $rincian = (new PpsbDashboardService)->rincian(self::TA, 'outstanding');

        $this->assertSame(1, $rincian->total());
        $this->assertSame('SudahMulai', $rincian[0]->nama);
        $this->assertSame('6000000.00', $rincian[0]->sisa);
        $this->assertSame('6000000.00', (string) $rincian[0]->sisa);
        $this->assertSame(4000000.0, (float) $rincian[0]->terbayar);
    }

    /**
     * Daftar ratusan santri: hanya satu halaman yang dimuat, dan pencarian
     * menyaring SELURUH data — bukan cuma halaman yang sedang tampil.
     */
    public function test_rincian_terpaginasi_dan_bisa_dicari(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $s = $this->calon('Calon '.$i);
            $this->bayar($s, 'registrasi', '1000000', '2026-09-10');
        }
        $dicari = Santri::where('nama', 'Calon 30')->firstOrFail();

        $svc = new PpsbDashboardService;
        $halaman1 = $svc->rincian(self::TA, 'registrasi');

        $this->assertSame(30, $halaman1->total(), 'Penghitung menyebut seluruh data…');
        $this->assertCount(25, $halaman1->items(), '…tapi yang dimuat hanya satu halaman.');
        $this->assertTrue($halaman1->hasPages());

        // Cari nomor pendaftaran santri yang berada di HALAMAN KEDUA.
        $hasil = $svc->rincian(self::TA, 'registrasi', $dicari->no_pendaftaran);
        $this->assertSame(1, $hasil->total());
        $this->assertSame('Calon 30', $hasil[0]->nama);

        // Lewat halaman: pencarian & pilihan kartu ikut terbawa saat pindah halaman.
        $this->actingAs($this->admin)
            ->get('/dashboard?tab=ppsb&detail=registrasi&cari='.$dicari->no_pendaftaran)
            ->assertOk()->assertSee('Calon 30')->assertDontSee('Calon 1<');

        $this->actingAs($this->admin)->get('/dashboard?tab=ppsb&detail=registrasi&page=2')
            ->assertOk()->assertSee('30 santri');
    }

    /** Unduhan memakai pencarian yang sama, tetapi TIDAK terpotong paginasi. */
    public function test_unduhan_rincian_utuh_dan_ikut_pencarian(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $s = $this->calon('Calon '.$i);
            $this->bayar($s, 'registrasi', '1000000', '2026-09-10');
        }

        $semua = (new PpsbDashboardService)->rincian(self::TA, 'registrasi', '', semua: true);
        $this->assertCount(30, $semua, 'Unduhan memuat seluruh baris, bukan 25.');

        $csv = $this->actingAs($this->admin)
            ->get(route('dashboard.ppsb_export', ['jenis' => 'registrasi', 'ta' => self::TA, 'format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));

        // Pengguna tanpa hak tab PPSB tak boleh mengunduh.
        $orang = User::create([
            'username' => 'lain2', 'nama' => 'Lain', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        $this->actingAs($orang)
            ->get(route('dashboard.ppsb_export', ['jenis' => 'registrasi', 'ta' => self::TA]))
            ->assertForbidden();
    }

    public function test_halaman_ppsb_menampilkan_keenam_bagian(): void
    {
        $santri = $this->calon('Lengkap');
        $this->bayar($santri, 'registrasi', '1000000', Carbon::parse('2026-09-10')->toDateString());

        $this->actingAs($this->admin)->get('/dashboard?tab=ppsb')->assertOk()
            ->assertSee('Total Pendaftar per Jenjang')
            ->assertSee('Total Closing per Jenjang')
            ->assertSee('Outstanding Closing')
            ->assertSee('Total Penerimaan')
            ->assertSee('Plan vs Aktual per Jenjang')
            ->assertSee('Ranking Sumber Informasi')
            ->assertSee('Jalur Pendaftaran')
            ->assertSee('Ikhwan')->assertSee('Akhwat')->assertSee('Pencapaian')
            // Grafik dirender di server sebagai SVG — tak ada pustaka yang perlu dimuat.
            ->assertSee('Trend Pendaftar per Bulan')
            ->assertSee('Trend Closing per Bulan')
            ->assertSee('<svg viewBox', false);
    }
}
