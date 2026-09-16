<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\OutstandingLainService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Akses;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OUTSTANDING TAGIHAN LAIN — daftar kerja untuk tunggakan Kesantrian selain SPP.
 *
 * Lahir dari keluhan yang tepat: penanda "Tugas Saya" menyebut ADA satu tagihan
 * lewat jatuh tempo, tanpa memberi tahu siapa dan apa. Yang dijaga di sini
 * karena itu bukan sekadar "datanya keluar", melainkan bahwa daftarnya benar
 * sebagai ALAT KERJA — cakupannya tepat, urutannya mendesak lebih dulu, dan
 * angkanya tak menyesatkan petugas untuk menagih orang yang sudah membayar.
 */
class OutstandingLainTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZOL';

    private const PIUTANG = '1.ZZOL.1';

    private const PENDAPATAN = '4.ZZOL.1';

    private const TA = '2026/2027';

    private User $admin;

    private Santri $santri;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzol_admin', 'nama' => 'Admin Uji', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3, 'urutan' => 1]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZOLU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Outstanding Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        foreach ([['lain', 'Lain-lain', 'lain'], ['du', 'Daftar Ulang', 'daftar_ulang'], ['spp', 'SPP', 'spp']] as $i => [$kode, $nama, $perilaku]) {
            TipeBiaya::firstOrCreate(['kode' => $kode], ['nama' => $nama, 'perilaku' => $perilaku, 'urutan' => $i + 1, 'bawaan' => true, 'status' => 'aktif']);
            JenisBiaya::create([
                'kode' => strtoupper($kode).'-UJI', 'nama' => $nama.' Uji', 'tipe' => $kode,
                'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
                'kode_unit' => 'ZZOLU', 'status' => 'aktif',
            ]);
        }

        $this->wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Uji', 'telepon_ayah' => '0811',
            'nama' => 'Bapak Uji', 'telepon' => '0811', 'status' => 'aktif',
        ]);
        $this->santri = Santri::create([
            'no_pendaftaran' => 'UJI-0001', 'nis' => '990001', 'nama' => 'Santri Uji',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id,
        ]);
    }

    private function tagihan(string $kodeJenis, string $perilaku, string $nominal, array $timpa = []): TagihanSantri
    {
        return TagihanSantri::create($timpa + [
            'id_santri' => $this->santri->id, 'kode_jenis' => $kodeJenis, 'perilaku' => $perilaku,
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar',
            'sudah_akrual' => true,
        ]);
    }

    private function svc(): OutstandingLainService
    {
        return new OutstandingLainService;
    }

    /**
     * Cakupannya persis yang memicu penanda tugas "Pembayaran SPP & Tagihan Lain"
     * DIKURANGI SPP, yang sudah punya layarnya sendiri. Salah satu saja terlewat,
     * keluhan yang melahirkan layar ini terulang.
     */
    public function test_memuat_lain_dan_daftar_ulang_tetapi_bukan_spp(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '140000');
        $this->tagihan('DU-UJI', 'daftar_ulang', '750000');
        $this->tagihan('SPP-UJI', 'spp', '500000', ['periode' => '2026-08']);

        $jenis = array_column($this->svc()->daftar(), 'kode_jenis');
        sort($jenis);
        $this->assertSame(['DU-UJI', 'LAIN-UJI'], $jenis, 'SPP punya layarnya sendiri');
    }

    /** Dibaca langsung dari tagihan, jadi yang lunas hilang sendiri. */
    public function test_yang_lunas_hilang_sendiri(): void
    {
        $t = $this->tagihan('LAIN-UJI', 'lain', '140000');
        $this->assertCount(1, $this->svc()->daftar());

        $t->update(['sisa' => '0', 'status' => 'lunas']);
        $this->assertSame([], $this->svc()->daftar());
    }

    /**
     * `terbayar` = nominal − sisa, BUKAN jumlah baris pembayaran.
     *
     * Auto-debet Dompet Wali menutup tagihan tanpa meninggalkan baris pembayaran
     * sama sekali. Menghitung dari baris akan melaporkan tunggakan lebih besar
     * dari kenyataan — dan petugas menagih orang yang sudah membayar.
     */
    public function test_terbayar_dihitung_dari_sisa_bukan_baris_pembayaran(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '200000', ['sisa' => '50000', 'status' => 'sebagian']);

        $this->assertSame(0, PembayaranSantri::count(), 'memang tak ada baris pembayaran — itulah keadaan yang diuji');

        $r = $this->svc()->daftar()[0];
        $this->assertSame(150000.0, (float) $r['terbayar']);
        $this->assertSame(50000.0, (float) $r['sisa']);
    }

    /**
     * Setoran yang belum diverifikasi belum mengurangi sisa — tetapi HARUS
     * terlihat, kalau tidak petugas menagih ulang orang yang menyetor kemarin.
     */
    public function test_setoran_menunggu_verifikasi_ditampilkan_terpisah(): void
    {
        $t = $this->tagihan('LAIN-UJI', 'lain', '140000');
        PembayaranSantri::create([
            'nomor' => 'BYR-'.uniqid(), 'id_tagihan' => $t->id, 'id_santri' => $t->id_santri,
            'tanggal' => '2026-09-01', 'nominal' => '140000', 'metode' => 'tunai',
            'kode_rekening' => self::PIUTANG, 'status' => 'menunggu_verifikasi',
            'dicatat_oleh' => $this->admin->id_pengguna,
        ]);

        $r = $this->svc()->daftar()[0];
        $this->assertSame(140000.0, (float) $r['sisa'], 'sisa belum berkurang sampai diverifikasi');
        $this->assertSame(140000.0, (float) $r['menunggu']);
        $this->assertSame(140000.0, (float) $this->svc()->ringkasan($this->svc()->daftar())['menunggu']);
    }

    /** Urutan kerja yang sebenarnya: paling telat lebih dulu. */
    public function test_yang_paling_telat_di_urutan_atas(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '100000', ['jatuh_tempo' => now()->subDays(3)->toDateString()]);
        $this->tagihan('DU-UJI', 'daftar_ulang', '200000', ['jatuh_tempo' => now()->subDays(30)->toDateString()]);
        // Tanpa tenggat: tetap tunggakan, hanya turun ke bawah — bukan dibuang.
        $this->tagihan('LAIN-UJI', 'lain', '300000', ['keterangan' => 'tanpa tempo']);

        $urut = array_column($this->svc()->daftar(), 'hari_lewat');
        $this->assertSame(30, $urut[0]);
        $this->assertSame(3, $urut[1]);
        $this->assertNull($urut[2]);
    }

    public function test_penyaring_lewat_jatuh_tempo(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '100000', ['jatuh_tempo' => now()->subDays(5)->toDateString()]);
        $this->tagihan('DU-UJI', 'daftar_ulang', '200000', ['jatuh_tempo' => now()->addDays(20)->toDateString()]);
        $this->tagihan('LAIN-UJI', 'lain', '300000', ['keterangan' => 'tanpa tempo']);

        $this->assertCount(1, $this->svc()->daftar(['tempo' => 'lewat']));
        $this->assertCount(1, $this->svc()->daftar(['tempo' => 'tanpa']));
        $this->assertCount(3, $this->svc()->daftar());
    }

    /** Ringkasan memecah per JENIS — satu santri bisa menunggak beberapa hal. */
    public function test_ringkasan_dipecah_per_jenis_tagihan(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '140000', ['jatuh_tempo' => now()->subDay()->toDateString()]);
        $this->tagihan('DU-UJI', 'daftar_ulang', '750000');

        $r = $this->svc()->ringkasan($this->svc()->daftar());
        $this->assertSame(2, $r['baris']);
        $this->assertSame(1, $r['santri'], 'satu santri menunggak dua hal');
        $this->assertSame(890000.0, (float) $r['sisa']);
        $this->assertSame(1, $r['lewat']);
        $this->assertSame(140000.0, (float) $r['lewat_sisa']);
        $this->assertCount(2, $r['per_jenis']);
    }

    /** Koreksi nominal tagihan berakrual WAJIB membawa jurnal penyesuaian. */
    public function test_koreksi_nominal_menerbitkan_jurnal_penyesuaian(): void
    {
        $t = $this->tagihan('LAIN-UJI', 'lain', '140000');

        $this->actingAs($this->admin)->put(route('outstanding_lain.koreksi', $t->id), [
            'nominal' => '0', 'alasan' => 'Santri tidak ikut laundry bulan ini',
        ])->assertRedirect()->assertSessionHas('status');

        $t->refresh();
        $this->assertSame(0.0, (float) $t->nominal);
        $this->assertSame('dihapus', $t->status);
        $this->assertSame(1, JournalEntry::count(), 'piutang yang sudah dibukukan wajib dibalik');
        $this->assertSame([], $this->svc()->daftar(), 'keluar dari daftar outstanding');
    }

    /**
     * Menggeser jatuh tempo BUKAN peristiwa akuntansi — tak sepeser pun bergerak,
     * jadi tak boleh ada jurnal yang terbit karenanya.
     */
    public function test_mengubah_jatuh_tempo_saja_tidak_menjurnal(): void
    {
        $t = $this->tagihan('LAIN-UJI', 'lain', '140000', ['jatuh_tempo' => now()->subDays(11)->toDateString()]);

        $this->actingAs($this->admin)->put(route('outstanding_lain.koreksi', $t->id), [
            'nominal' => '140000',
            'jatuh_tempo' => now()->addDays(7)->toDateString(),
            'alasan' => 'Diberi tenggat baru setelah dihubungi wali',
        ])->assertRedirect();

        $t->refresh();
        $this->assertSame(140000.0, (float) $t->nominal);
        $this->assertSame(now()->addDays(7)->toDateString(), $t->jatuh_tempo->toDateString());
        $this->assertSame(0, JournalEntry::count());
    }

    /** SPP tak bisa dikoreksi dari layar ini — ia punya layar & aturannya sendiri. */
    public function test_menolak_mengoreksi_tagihan_di_luar_cakupannya(): void
    {
        $spp = $this->tagihan('SPP-UJI', 'spp', '500000', ['periode' => '2026-08']);

        $this->actingAs($this->admin)->put(route('outstanding_lain.koreksi', $spp->id), [
            'nominal' => '0', 'alasan' => 'coba',
        ])->assertSessionHas('error');

        $this->assertSame(500000.0, (float) $spp->refresh()->nominal);
    }

    /** Layarnya terbuka dan benar-benar menyebut siapa & apa — inti keluhannya. */
    public function test_layar_menyebut_santri_jenis_dan_keterlambatannya(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '140000', [
            'keterangan' => 'Laundry Agustus',
            'jatuh_tempo' => now()->subDays(11)->toDateString(),
        ]);

        $this->actingAs($this->admin)->get(route('outstanding_lain.index'))
            ->assertOk()
            ->assertSee('Santri Uji')
            ->assertSee('Lain-lain Uji')
            ->assertSee('Laundry Agustus')
            ->assertSee('lewat 11 hari')
            ->assertSee('Bapak Uji');
    }

    /**
     * Penanda tugas mengantar ke DAFTARNYA, bukan ke layar pencatatan pembayaran.
     *
     * Inilah keluhan yang melahirkan layar ini: notifikasi bilang "ada 1
     * pekerjaan", lalu mengantar ke halaman yang sama sekali tak memuat daftar
     * tunggakan — petugas tetap tak tahu siapa dan apa. Judul di lonceng diambil
     * dari nama menu tujuan, jadi URL-nya sekaligus menentukan bunyinya.
     */
    public function test_penanda_tugas_mengantar_ke_daftar_tunggakan(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '140000', ['jatuh_tempo' => now()->subDays(11)->toDateString()]);

        $this->actingAs($this->admin);
        TugasSaya::lupakan();

        $this->assertSame(1, TugasSaya::untukUrl('/kesantrian/outstanding-lain'));
        $this->assertSame(0, TugasSaya::untukUrl('/kesantrian/pembayaran'),
            'jangan ditandai dua kali di dua tempat');
    }

    /**
     * CADANGANNYA yang paling penting dijaga.
     *
     * `outstanding-lain` modul baru yang belum dicentangi siapa pun. Kalau
     * penandanya dipindah tanpa lapisan cadangan, notifikasinya HILANG dari
     * layar semua orang sampai hak itu dibagikan — memperbaiki kebingungan
     * dengan cara membuat pekerjaannya tak terlihat sama sekali.
     */
    public function test_tanpa_hak_daftar_penandanya_jatuh_ke_layar_pembayaran(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '140000', ['jatuh_tempo' => now()->subDays(11)->toDateString()]);

        $staf = User::create(['username' => 'zzol_bayar', 'nama' => 'Petugas Bayar', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $staf->id_pengguna, 'kode_modul' => 'pembayaran-kesantrian',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $this->actingAs($staf);
        TugasSaya::lupakan();

        $this->assertSame(1, TugasSaya::untukUrl('/kesantrian/pembayaran'), 'penandanya tak boleh hilang');
        $this->assertSame(0, TugasSaya::untukUrl('/kesantrian/outstanding-lain'));
    }

    /** Yang tak berhak atas keduanya memang tak punya pekerjaan ini. */
    public function test_tanpa_hak_mana_pun_tak_ada_penanda(): void
    {
        $this->tagihan('LAIN-UJI', 'lain', '140000', ['jatuh_tempo' => now()->subDays(11)->toDateString()]);

        $lain = User::create(['username' => 'zzol_lain', 'nama' => 'Orang Lain', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        Akses::lupakan();

        $this->actingAs($lain);
        TugasSaya::lupakan();

        $this->assertSame(0, TugasSaya::untukUrl('/kesantrian/outstanding-lain'));
        $this->assertSame(0, TugasSaya::untukUrl('/kesantrian/pembayaran'));
    }

    /** SPP punya daftarnya sendiri — penandanya tak boleh tercampur ke sini. */
    public function test_spp_diantar_ke_daftar_outstanding_spp(): void
    {
        $this->tagihan('SPP-UJI', 'spp', '500000', [
            'periode' => '2026-08', 'jatuh_tempo' => now()->subDays(5)->toDateString(),
        ]);

        $this->actingAs($this->admin);
        TugasSaya::lupakan();

        $this->assertSame(1, TugasSaya::untukUrl('/kesantrian/outstanding-spp'));
        $this->assertSame(0, TugasSaya::untukUrl('/kesantrian/outstanding-lain'));
    }

    /** Modulnya berdiri sendiri: yang tak dicentangi tak bisa masuk. */
    public function test_hak_akses_modulnya_sendiri(): void
    {
        $staf = User::create(['username' => 'zzol_staf', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        $this->actingAs($staf)->get(route('outstanding_lain.index'))->assertForbidden();

        // Diberi `lihat` saja: boleh membaca, kolom koreksi tak muncul.
        HakAksesModul::create(['id_pengguna' => $staf->id_pengguna, 'kode_modul' => 'outstanding-lain',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $t = $this->tagihan('LAIN-UJI', 'lain', '140000');
        $this->actingAs($staf)->get(route('outstanding_lain.index'))
            ->assertOk()
            ->assertSee('Santri Uji')
            ->assertDontSee('Edit tagihan');

        $this->actingAs($staf)->put(route('outstanding_lain.koreksi', $t->id), [
            'nominal' => '0', 'alasan' => 'coba',
        ])->assertForbidden();
    }
}
