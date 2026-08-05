<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\StatusAnggaranPengajuanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DASHBOARD tab "Anggaran & Pengajuan".
 *
 * Nilai layar ini bertumpu pada satu angka yang tak ada di layar lain: KOMITMEN
 * — pengajuan yang sudah diajukan tapi belum berjurnal. Kalau komitmen tidak
 * ikut dihitung, sisa anggaran tampak aman padahal sudah habis dijanjikan, dan
 * orang terus mengajukan sampai tembus. Karena itu yang diuji paling ketat di
 * sini adalah komitmen dan penandaan akun yang tembus/belum dianggarkan.
 *
 * Yang kedua: visibilitas. Rekap tak boleh membocorkan pengajuan bagian lain
 * kepada staf yang di daftar biasa pun tak berhak melihatnya.
 */
class DashboardAnggaranPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZDA';

    private const AKUN = '5.ZZDA.1';

    private const AKUN2 = '5.ZZDA.2';

    private const UNIT = 'ZZDAU';

    private StatusAnggaranPengajuanService $svc;

    private User $admin;

    private User $staffA;

    private User $staffB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new StatusAnggaranPengajuanService;

        CompanySettings::create([
            'id' => 1, 'nama_perusahaan' => 'Al Wafi', 'periode_awal_pembukuan' => '2026-01-01',
            'bulan_awal_anggaran' => 1,
        ]);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'YYS', 'nama_bagian' => 'Yayasan', 'level' => 1]);
        Bagian::create(['kode_bagian' => 'BAG-A', 'nama_bagian' => 'Bagian A', 'kode_induk' => 'YYS', 'level' => 3]);
        Bagian::create(['kode_bagian' => 'BAG-B', 'nama_bagian' => 'Bagian B', 'kode_induk' => 'YYS', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        // Grup HARUS berakar di kelompok "5": realisasi anggaran hanya melihat
        // akun yang root grupnya Beban (BagianPolicy::KELOMPOK_ANGGARAN).
        CoaGroup::create(['kode_grup' => '5', 'nama_grup' => 'Beban']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Beban Uji', 'kode_induk' => '5']);
        CoaGroup::create(['kode_grup' => 'ZZDAK', 'nama_grup' => 'Kas Uji']);
        CoaDetail::create(['kode_coa' => '1.ZZDA.KAS', 'nama_coa' => 'Kas Uji', 'kode_grup' => 'ZZDAK', 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => '1.ZZDA.KAS', 'nama_rekening' => 'Kas Uji', 'jenis' => 'kas', 'status' => 'aktif']);
        CoaDetail::create(['kode_coa' => self::AKUN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::AKUN2, 'nama_coa' => 'Beban Transport', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        // Anggaran BAG-A untuk AKUN = 1.000.000 (Juli 2026). AKUN2 sengaja tak dianggarkan.
        Budget::create(['tahun' => 2026, 'bulan' => 7, 'kode_coa' => self::AKUN,
            'kode_bagian' => 'BAG-A', 'kode_unit' => self::UNIT, 'nominal' => '1000000']);

        $this->admin = $this->pengguna('admin', 'Admin', null, null, admin: true);
        $this->staffA = $this->pengguna('staffa', 'Staff A', 'BAG-A', 4);
        $this->staffB = $this->pengguna('staffb', 'Staff B', 'BAG-B', 4);
    }

    private function pengguna(string $u, string $n, ?string $bagian, ?int $peringkat, bool $admin = false): User
    {
        $user = User::create(['username' => $u, 'nama' => $n, 'password_hash' => 'x', 'kode_level' => 'L1',
            'kode_bagian' => $bagian, 'peringkat_pengajuan' => $peringkat, 'is_admin' => $admin, 'status' => 'aktif']);
        foreach (['dashboard-anggaran', 'pengajuan-pembayaran'] as $modul) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul,
                'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        }

        return $user;
    }

    /** Pengajuan langsung ke tabel: yang diuji rekapnya, bukan alur pembuatannya. */
    private function buatPengajuan(string $status, string $nominal, string $jenis = 'pembayaran',
        string $bagian = 'BAG-A', ?User $pemohon = null, string $tanggal = '2026-07-10', ?string $akun = null): PengajuanPembayaran
    {
        $p = PengajuanPembayaran::create([
            'nomor' => 'PB-'.str_pad((string) (PengajuanPembayaran::count() + 1), 4, '0', STR_PAD_LEFT),
            'tanggal' => $tanggal, 'jenis' => $jenis, 'kode_bagian' => $bagian,
            'nominal' => $nominal, 'sisa_hutang' => '0', 'keterangan' => 'uji',
            'status' => $status, 'id_pengguna' => ($pemohon ?? $this->staffA)->id_pengguna,
        ]);
        $p->details()->create([
            'kode_coa' => $akun ?? self::AKUN, 'nama_coa' => 'Beban', 'kode_unit' => self::UNIT,
            'nominal' => $nominal, 'keterangan' => null,
        ]);

        return $p;
    }

    // ---- Anggaran ----

    public function test_komitmen_ikut_mengurangi_sisa_anggaran(): void
    {
        $this->buatPengajuan('diajukan', '400000');

        $a = $this->svc->anggaran($this->admin->id_pengguna, 2026);

        $this->assertSame('1000000.00', $a['anggaran']);
        $this->assertSame('400000.00', $a['komitmen'], 'pengajuan berjalan harus dihitung sebagai komitmen');
        $this->assertSame('400000.00', $a['terpakai']);
        $this->assertSame('600000.00', $a['sisa']);
        $this->assertSame(40.0, $a['persen']);
    }

    /** Yang sudah diposting/lunas bukan komitmen lagi — ia sudah jadi realisasi jurnal. */
    public function test_pengajuan_selesai_tidak_dihitung_komitmen(): void
    {
        $this->buatPengajuan('diposting', '400000');
        $this->buatPengajuan('ditolak', '900000');

        $a = $this->svc->anggaran($this->admin->id_pengguna, 2026);

        $this->assertSame('0.00', $a['komitmen']);
    }

    /** Dua pengajuan @60% tak boleh lolos senyap menjadi 120%. */
    public function test_akun_tembus_muncul_di_daftar_perhatian(): void
    {
        $this->buatPengajuan('diajukan', '600000');
        $this->buatPengajuan('diajukan', '600000');

        $a = $this->svc->anggaran($this->admin->id_pengguna, 2026);

        $this->assertSame('1200000.00', $a['terpakai']);
        $this->assertSame('-200000.00', $a['sisa']);
        $this->assertSame(1, $a['jumlah_perhatian']);
        $this->assertTrue($a['perhatian'][0]['tembus']);
        $this->assertSame(self::AKUN, $a['perhatian'][0]['kode_coa']);
    }

    public function test_tanpa_komitmen_maupun_realisasi_tidak_ada_yang_perlu_diperhatikan(): void
    {
        $a = $this->svc->anggaran($this->admin->id_pengguna, 2026);

        $this->assertSame([], $a['perhatian']);
        $this->assertTrue($a['ada_anggaran']);
    }

    /** Komitmen bagian lain tak boleh ikut terhitung saat disaring per bagian. */
    public function test_penyaringan_bagian_membatasi_komitmen(): void
    {
        $this->buatPengajuan('diajukan', '400000', bagian: 'BAG-A');
        $this->buatPengajuan('diajukan', '700000', bagian: 'BAG-B', pemohon: $this->staffB);

        $semua = $this->svc->anggaran($this->admin->id_pengguna, 2026);
        $this->assertSame('1100000.00', $semua['komitmen']);

        $hanyaA = $this->svc->anggaran($this->admin->id_pengguna, 2026, 'BAG-A');
        $this->assertSame('400000.00', $hanyaA['komitmen']);
    }

    // ---- Pengajuan ----

    public function test_matriks_status_menghitung_cacah_dan_nominal(): void
    {
        $this->buatPengajuan('diajukan', '100000');
        $this->buatPengajuan('diajukan', '200000');
        $this->buatPengajuan('lunas', '500000');
        $this->buatPengajuan('diajukan', '300000', jenis: 'uang_muka');

        $m = $this->svc->pengajuan($this->admin)['matriks'];

        $this->assertSame(2, $m['pembayaran']['diajukan']['jumlah']);
        $this->assertSame('300000.00', $m['pembayaran']['diajukan']['total']);
        $this->assertSame(1, $m['pembayaran']['lunas']['jumlah']);
        $this->assertSame(2, $m['pembayaran']['_berjalan']['jumlah'], 'lunas bukan lagi dokumen berjalan');
        $this->assertSame(1, $m['uang_muka']['diajukan']['jumlah']);
        $this->assertSame(0, $m['penyelesaian_uang_muka']['diajukan']['jumlah']);
    }

    /** Staf bagian lain tak boleh melihat angka yang di daftar pun bukan haknya. */
    public function test_rekap_mengikuti_visibilitas_daftar_pengajuan(): void
    {
        $this->buatPengajuan('diajukan', '100000', bagian: 'BAG-A', pemohon: $this->staffA);
        $this->buatPengajuan('diajukan', '900000', bagian: 'BAG-B', pemohon: $this->staffB);

        $this->assertSame(2, $this->svc->pengajuan($this->admin)['matriks']['pembayaran']['diajukan']['jumlah']);

        $milikA = $this->svc->pengajuan($this->staffA)['matriks']['pembayaran']['diajukan'];
        $this->assertSame(1, $milikA['jumlah']);
        $this->assertSame('100000.00', $milikA['total']);
    }

    public function test_dokumen_tertahan_diurut_dari_yang_paling_tua(): void
    {
        Carbon::setTestNow('2026-07-20');
        $this->buatPengajuan('diajukan', '100000', tanggal: '2026-07-18');
        $this->buatPengajuan('diajukan', '200000', tanggal: '2026-07-01');

        $tertahan = $this->svc->pengajuan($this->admin)['tertahan'];

        $this->assertCount(2, $tertahan);
        $this->assertSame(19, $tertahan[0]['umur_hari'], 'yang tertua di urutan pertama');
        $this->assertSame(2, $tertahan[1]['umur_hari']);

        Carbon::setTestNow();
    }

    public function test_uang_muka_outstanding_dihitung_dari_sisanya(): void
    {
        OperationalAdvance::create([
            'nomor_ref' => 'UM-001', 'tanggal' => '2026-07-01', 'kode_unit' => self::UNIT,
            'kode_rekening' => '1.ZZDA.KAS', 'kode_coa_uang_muka' => self::AKUN, 'nama_coa_uang_muka' => 'UM',
            'penerima' => 'Fulan', 'keterangan' => 'uji', 'nominal' => '1000000', 'nominal_diselesaikan' => '400000',
            'status' => 'aktif', 'id_pengguna' => $this->staffA->id_pengguna,
        ]);
        OperationalAdvance::create([
            'nomor_ref' => 'UM-002', 'tanggal' => '2026-07-02', 'kode_unit' => self::UNIT,
            'kode_rekening' => '1.ZZDA.KAS', 'kode_coa_uang_muka' => self::AKUN, 'nama_coa_uang_muka' => 'UM',
            'penerima' => 'Fulanah', 'keterangan' => 'uji', 'nominal' => '500000', 'nominal_diselesaikan' => '500000',
            'status' => 'aktif', 'id_pengguna' => $this->staffA->id_pengguna,
        ]);

        $um = $this->svc->pengajuan($this->admin)['uang_muka'];

        $this->assertSame(1, $um['jumlah'], 'yang sudah lunas diselesaikan tidak outstanding');
        $this->assertSame('600000.00', $um['total']);
        $this->assertSame('UM-001', $um['terlama']['nomor_ref']);
    }

    // ---- Layar ----

    public function test_tab_muncul_dan_terbuka_bagi_yang_berhak(): void
    {
        $this->buatPengajuan('diajukan', '400000');

        $this->actingAs($this->admin)->get(route('dashboard', ['tab' => 'anggaran']))
            ->assertOk()
            ->assertSee('Anggaran &amp; Pengajuan', false)
            ->assertSee('Status Anggaran')
            ->assertSee('Komitmen')
            ->assertSee('Uang muka outstanding');
    }

    public function test_tanpa_hak_tab_anggaran_tidak_tampil(): void
    {
        HakAksesModul::where('id_pengguna', $this->staffA->id_pengguna)
            ->where('kode_modul', 'dashboard-anggaran')->delete();
        HakAksesModul::create(['id_pengguna' => $this->staffA->id_pengguna, 'kode_modul' => 'dashboard',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);

        // Tab yang tak berhak → jatuh ke tab pertama yang boleh, bukan 403.
        $this->actingAs($this->staffA)->get(route('dashboard', ['tab' => 'anggaran']))
            ->assertOk()
            ->assertDontSee('Status Anggaran');
    }

    /**
     * Yang tak berhak atas realisasi anggaran tetap melihat status pengajuannya —
     * panelnya saja yang absen, bukan seluruh tabnya.
     */
    public function test_tanpa_wewenang_anggaran_panel_pengajuan_tetap_tampil(): void
    {
        $tanpaBagian = $this->pengguna('lepas', 'Tanpa Bagian', null, null);

        $this->actingAs($tanpaBagian)->get(route('dashboard', ['tab' => 'anggaran']))
            ->assertOk()
            ->assertSee('Status Pengajuan')
            ->assertSee('hanya untuk admin', false);
    }
}
