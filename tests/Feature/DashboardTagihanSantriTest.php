<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetWali;
use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\KesantrianDashboardService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * DASHBOARD TAGIHAN SANTRI AKTIF.
 *
 * Yang dijaga paling keras di sini ada dua, karena dua-duanya membuat dashboard
 * melaporkan keadaan yang LEBIH BAIK daripada kenyataan — kesalahan yang paling
 * berbahaya pada layar ringkasan:
 *
 *  1. Terbayar dihitung `nominal - sisa`, bukan dari jumlah baris pembayaran.
 *     Saldo prabayar & auto-debet mengurangi sisa tanpa meninggalkan baris
 *     pembayaran, jadi menjumlahkan pembayaran melaporkan tunggakan lebih besar.
 *  2. Tunggakan tahun ajaran lama tidak boleh menghilang dari layar.
 *
 * Ditambah satu batas yang tak boleh bocor: santri non-aktif tak ikut terhitung.
 */
class DashboardTagihanSantriTest extends TestCase
{
    use MembuatTarif, RefreshDatabase;

    private User $admin;

    /** Jenis biaya bawaan — `tagihan_santri.kode_jenis` ber-kunci asing ke sini. */
    private string $kodeJenis;

    private KesantrianDashboardService $svc;

    /** Nomor pendaftaran & telepon wali wajib unik — dinaikkan tiap baris dibuat. */
    private int $urut = 0;

    /** Wali bawaan: `santri.id_wali` NOT NULL, jadi tiap santri harus punya satu. */
    private Wali $waliUmum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new KesantrianDashboardService;

        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'jumlah_tingkat' => 6, 'urutan' => 1]);
        // `santri.tahun_ajaran` ber-kunci asing ke master; dua tahun disiapkan
        // supaya pemisahan tunggakan tahun lama bisa diuji.
        TahunAjaran::create(['kode' => '2025/2026', 'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30', 'status' => 'nonaktif']);
        TahunAjaran::create(['kode' => '2026/2027', 'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2027-06-30', 'status' => 'aktif']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->waliUmum = $this->wali('Wali Umum');

        // Akun & unit dibutuhkan jenis biaya (kunci asing), meski dashboard ini
        // sendiri tak menyentuh jurnal sama sekali.
        CoaGroup::create(['kode_grup' => 'ZZDT', 'nama_grup' => 'Uji Dashboard']);
        CoaDetail::create(['kode_coa' => '4.ZZDT.1', 'nama_coa' => 'Pendapatan SPP',
            'kode_grup' => 'ZZDT', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZDT.1', 'nama_coa' => 'Piutang Santri',
            'kode_grup' => 'ZZDT', 'jenis_saldo' => 'debet']);
        BusinessUnit::create(['kode_unit' => 'ZZDTU', 'nama_unit' => 'Unit Uji']);

        $this->kodeJenis = $this->buatBiaya([
            'kode' => 'SPP-SDTQ', 'nama' => 'SPP SDTQ', 'tipe' => 'spp',
            'kode_jenjang' => 'SDTQ', 'berulang' => true,
            'kode_coa_pendapatan' => '4.ZZDT.1', 'kode_coa_piutang' => '1.ZZDT.1',
            'kode_unit' => 'ZZDTU',
        ])->kode;
        $this->admin = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
    }

    private function wali(string $nama): Wali
    {
        return Wali::create([
            'nama' => $nama, 'telepon' => '0812'.str_pad((string) ++$this->urut, 8, '0', STR_PAD_LEFT),
            'kontak_utama' => 'ayah', 'nama_ayah' => $nama,
        ]);
    }

    private function santri(string $nama, string $status = 'aktif', ?int $idWali = null): Santri
    {
        return Santri::create([
            'no_pendaftaran' => 'REG-'.str_pad((string) ++$this->urut, 4, '0', STR_PAD_LEFT),
            'nama' => $nama, 'jenis_kelamin' => 'L', 'status' => $status, 'kode_jenjang' => 'SDTQ',
            'tahun_ajaran' => '2026/2027', 'id_wali' => $idWali ?? $this->waliUmum->id,
        ]);
    }

    /** @param array<string,mixed> $ganti */
    private function tagihan(Santri $s, string $nominal, string $sisa, array $ganti = []): TagihanSantri
    {
        // `periode` dibedakan tiap baris: indeks unik parsial
        // `tagihan_santri_sekali_per_ta` menolak dua tagihan SPP dengan periode
        // sama untuk satu santri dalam satu tahun ajaran — penjaga tagihan
        // ganda, dan fixture pun tunduk padanya.
        return TagihanSantri::create([
            'id_santri' => $s->id, 'kode_jenis' => $this->kodeJenis,
            'perilaku' => 'spp', 'nominal' => $nominal, 'sisa' => $sisa,
            'status' => (float) $sisa <= 0 ? 'lunas' : 'belum_bayar',
            'kode_jenjang' => 'SDTQ', 'tahun_ajaran' => '2026/2027',
            'periode' => sprintf('2026-%02d', (++$this->urut % 12) + 1),
            ...$ganti,
        ]);
    }

    /**
     * Terbayar = nominal − sisa. Tagihan yang lunas lewat saldo prabayar TIDAK
     * punya baris pembayaran sama sekali; menghitung dari baris pembayaran akan
     * melaporkannya sebagai tunggakan.
     */
    public function test_terbayar_dihitung_dari_sisa_bukan_baris_pembayaran(): void
    {
        $s = $this->santri('Ahmad');
        $this->tagihan($s, '300000', '0');   // lunas tanpa baris pembayaran
        $this->tagihan($s, '200000', '50000');

        $r = $this->svc->ringkasan('2026/2027');

        $this->assertSame('500000.00', $r['tagihan']);
        $this->assertSame('450000.00', $r['terbayar']);
        $this->assertSame('50000.00', $r['sisa']);
        $this->assertSame(90.0, $r['persen']);
    }

    /** Santri non-aktif tak boleh ikut — mereka ditagih dengan cara lain. */
    public function test_hanya_santri_aktif_yang_dihitung(): void
    {
        $this->tagihan($this->santri('Aktif'), '100000', '100000');
        $this->tagihan($this->santri('Alumni', 'alumni'), '900000', '900000');
        $this->tagihan($this->santri('Keluar', 'keluar'), '900000', '900000');

        $r = $this->svc->ringkasan('2026/2027');

        $this->assertSame('100000.00', $r['tagihan']);
        $this->assertSame(1, $r['menunggak']);
        $this->assertSame(1, $r['santri_aktif']);
    }

    /** Tanpa tagihan sama sekali, tertagih 100% — bukan galat bagi-nol. */
    public function test_tanpa_tagihan_tidak_membagi_nol(): void
    {
        $this->santri('Belum Ditagih');

        $r = $this->svc->ringkasan('2026/2027');

        $this->assertSame(100.0, $r['persen']);
        $this->assertSame(0.0, (float) $r['sisa']);
    }

    /**
     * Jumlah seluruh kelompok umur WAJIB sama dengan total tunggakan. Tagihan
     * tanpa jatuh tempo masuk "belum jatuh tempo", bukan dibuang — kalau dibuang,
     * aging diam-diam melaporkan angka lebih kecil daripada blok ringkasan.
     */
    public function test_aging_menjumlah_utuh_termasuk_yang_tanpa_jatuh_tempo(): void
    {
        $s = $this->santri('Budi');
        $this->tagihan($s, '100000', '100000', ['jatuh_tempo' => null]);
        $this->tagihan($s, '100000', '100000', ['jatuh_tempo' => now()->subDays(10)->toDateString()]);
        $this->tagihan($s, '100000', '100000', ['jatuh_tempo' => now()->subDays(200)->toDateString()]);

        $aging = $this->svc->aging('2026/2027');
        $total = array_sum(array_map(fn ($a) => (float) $a['sisa'], $aging));

        $this->assertSame(300000.0, $total);
        $this->assertSame(100000.0, (float) collect($aging)->firstWhere('kunci', 'belum')['sisa']);
        $this->assertSame(100000.0, (float) collect($aging)->firstWhere('kunci', 'd30')['sisa']);
        $this->assertSame(100000.0, (float) collect($aging)->firstWhere('kunci', 'lebih')['sisa']);
    }

    /** Tunggakan tahun lama dipisah dari angka berjalan, tapi tak hilang. */
    public function test_tunggakan_tahun_lama_dipisah_bukan_dibuang(): void
    {
        $s = $this->santri('Citra');
        $this->tagihan($s, '100000', '100000');
        $this->tagihan($s, '700000', '700000', ['tahun_ajaran' => '2025/2026']);

        // Angka berjalan tak memuat tahun lama.
        $this->assertSame('100000.00', $this->svc->ringkasan('2026/2027')['sisa']);

        // Tapi tahun lama tetap terlaporkan sendiri.
        $lama = $this->svc->tunggakanTahunLama('2026/2027');
        $this->assertCount(1, $lama);
        $this->assertSame('2025/2026', $lama[0]['tahun_ajaran']);
        $this->assertSame('700000.00', $lama[0]['sisa']);

        // Dan "semua tahun" menjumlahkan keduanya.
        $this->assertSame('800000.00', $this->svc->ringkasan(null)['sisa']);
    }

    /**
     * Saldo dompet dijumlahkan PER WALI, bukan per santri: kakak-adik berbagi
     * satu dompet, dan menghitungnya dua kali membuat saldo tampak cukup padahal
     * tidak.
     */
    public function test_dompet_cukup_dihitung_per_wali(): void
    {
        $wali = $this->wali('Bapak Ali');
        DompetWali::create(['id_wali' => $wali->id, 'saldo' => '150000']);

        $kakak = $this->santri('Kakak', 'aktif', $wali->id);
        $adik = $this->santri('Adik', 'aktif', $wali->id);
        $this->tagihan($kakak, '100000', '100000');
        $this->tagihan($adik, '100000', '100000');

        // Gabungan tunggakan 200.000 > saldo 150.000 → tidak boleh muncul.
        $this->assertSame(0, $this->svc->dompetCukup('2026/2027')['jumlah_wali']);

        // Begitu salah satunya lunas, sisanya 100.000 ≤ saldo → muncul.
        TagihanSantri::where('id_santri', $adik->id)->update(['sisa' => 0, 'status' => 'lunas']);
        $hasil = $this->svc->dompetCukup('2026/2027');
        $this->assertSame(1, $hasil['jumlah_wali']);
        $this->assertSame('100000.00', $hasil['tertutupi']);
    }

    /** Alur HTTP: tab tampil, hak aksesnya terpisah dari dashboard keuangan. */
    public function test_tab_muncul_dan_hak_aksesnya_terpisah(): void
    {
        $s = $this->santri('Dedi');
        $this->tagihan($s, '250000', '250000');

        $this->actingAs($this->admin)->get('/dashboard?tab=kesantrian')
            ->assertOk()
            ->assertSee('Tagihan Santri Aktif')
            ->assertSee('Umur Tunggakan');

        // Pengguna yang hanya berhak atas dashboard KEUANGAN tak melihat tabnya.
        $keu = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $keu->id_pengguna, 'kode_modul' => 'dashboard',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $this->actingAs($keu)->get('/dashboard')->assertOk()->assertDontSee('Tagihan Santri Aktif');
    }
}
