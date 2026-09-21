<?php

namespace Tests\Feature;

use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Reports\SaldoDompetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SALDO DOMPET — daftar saldo seluruh wali & santri.
 *
 * Layar ini lahir sebagai rincian di balik Rekonsiliasi Buku Pembantu, jadi
 * yang paling penting dijaga adalah KEJUJURAN ANGKANYA:
 *  • pemilik tanpa baris dompet tetap terhitung (saldo nol), bukan lenyap;
 *  • total tidak ikut menyusut saat daftarnya disaring — ia angka pembanding
 *    terhadap buku besar, dan total yang berubah-ubah tak bisa dipakai;
 *  • unduhan memuat SELURUH baris, bukan sehalaman.
 */
class SaldoDompetTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzsd_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
    }

    private function wali(string $nama, string $telepon, ?string $saldo = null): Wali
    {
        $w = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => $nama, 'telepon_ayah' => $telepon,
            'nama' => $nama, 'telepon' => $telepon, 'status' => 'aktif',
        ]);

        if ($saldo !== null) {
            DompetWali::create(['id_wali' => $w->id, 'saldo' => $saldo]);
        }

        return $w;
    }

    private function santri(Wali $wali, string $nis, string $nama, ?string $dompet = null, ?string $tabungan = null): Santri
    {
        $s = Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);

        if ($dompet !== null) {
            DompetSantri::create(['id_santri' => $s->id, 'saldo' => $dompet]);
        }
        if ($tabungan !== null) {
            TabunganSantri::create(['id_santri' => $s->id, 'saldo' => $tabungan]);
        }

        return $s;
    }

    // ---- Kejujuran angka ----

    public function test_pemilik_tanpa_baris_dompet_tetap_muncul_bersaldo_nol(): void
    {
        $punya = $this->wali('Ahmad Fauzi', '081000000001', '250000');
        $belum = $this->wali('Bilal Ramadhan', '081000000002'); // tak pernah bertransaksi

        $hasil = (new SaldoDompetService)->wali();
        $nama = $hasil['baris']->pluck('nama')->all();

        $this->assertContains('Bilal Ramadhan', $nama,
            'Baris dompet baru lahir saat transaksi pertama — yang belum pernah bertransaksi tak boleh lenyap dari daftar.');
        $this->assertSame(2, $hasil['jumlah']);
        $this->assertSame(1, $hasil['bersaldo']);
        unset($punya, $belum);
    }

    public function test_total_tidak_ikut_menyusut_saat_daftarnya_disaring(): void
    {
        $this->wali('Ahmad Fauzi', '081000000003', '250000');
        $this->wali('Bilal Ramadhan', '081000000004', '100000');

        $semua = (new SaldoDompetService)->wali();
        $disaring = (new SaldoDompetService)->wali(['cari' => 'Ahmad']);

        $this->assertSame(1, $disaring['baris']->total(), 'Saringannya memang memotong daftarnya…');
        $this->assertSame($semua['total'], $disaring['total'],
            '…tetapi totalnya harus tetap, karena itulah angka pembanding terhadap buku besar.');
        $this->assertSame('350000.00', (string) $disaring['total']);
    }

    public function test_saringan_hanya_bersaldo_menyembunyikan_yang_nol(): void
    {
        $this->wali('Ahmad Fauzi', '081000000005', '250000');
        $this->wali('Bilal Ramadhan', '081000000006', '0');
        $this->wali('Hafizh Nur', '081000000007');

        $hasil = (new SaldoDompetService)->wali(['bersaldo' => true]);

        $this->assertSame(1, $hasil['baris']->total());
        $this->assertSame('Ahmad Fauzi', $hasil['baris']->first()->nama);
    }

    public function test_dompet_dan_tabungan_santri_dijumlahkan_dalam_satu_baris(): void
    {
        $w = $this->wali('Ayah Ilham', '081000000008');
        $this->santri($w, '910001', 'Ilham Saputra', '75000', '400000');

        $hasil = (new SaldoDompetService)->santri();
        $baris = $hasil['baris']->first();

        $this->assertSame('75000.00', (string) $baris->saldo);
        $this->assertSame('400000.00', (string) $baris->tabungan);
        $this->assertSame('475000.00', (string) $baris->jumlah);
        $this->assertSame('75000.00', (string) $hasil['total_dompet']);
        $this->assertSame('400000.00', (string) $hasil['total_tabungan']);
    }

    public function test_jenjang_ditampilkan_dengan_namanya_bukan_kodenya(): void
    {
        $w = $this->wali('Ayah Junaidi', '081000000009');
        $this->santri($w, '910002', 'Junaidi Akbar', '50000');

        $baris = (new SaldoDompetService)->santri()['baris']->first();

        $this->assertSame('SMP', $baris->jenjang);
        $this->assertSame('Ayah Junaidi', $baris->nama_wali);
    }

    public function test_pencarian_menjangkau_nis_dan_telepon(): void
    {
        $a = $this->wali('Ahmad Fauzi', '081222333444', '10000');
        $this->wali('Bilal Ramadhan', '081999888777', '20000');
        $this->santri($a, '910003', 'Karim Abdullah', '30000');

        $svc = new SaldoDompetService;

        $this->assertSame(1, $svc->wali(['cari' => '222333'])['baris']->total());
        $this->assertSame(1, $svc->santri(['cari' => '910003'])['baris']->total());
    }

    // ---- Unduhan ----

    public function test_unduhan_memuat_seluruh_baris_bukan_sehalaman(): void
    {
        // Lebih banyak daripada satu halaman. Ini yang menjaga bug yang hampir
        // terkirim: berkas unduhan yang diam-diam berhenti di baris ke-50.
        $jumlah = SaldoDompetService::PER_HALAMAN + 5;
        for ($i = 1; $i <= $jumlah; $i++) {
            $this->wali('Wali '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), '0812'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), '1000');
        }

        $svc = new SaldoDompetService;

        $this->assertCount(SaldoDompetService::PER_HALAMAN, $svc->wali()['baris']->items());
        $this->assertCount($jumlah, $svc->untukUnduh('wali'));
    }

    public function test_unduhan_tetap_menghormati_saringan(): void
    {
        $this->wali('Ahmad Fauzi', '081000000010', '250000');
        $this->wali('Bilal Ramadhan', '081000000011');

        $baris = (new SaldoDompetService)->untukUnduh('wali', ['bersaldo' => true]);

        $this->assertCount(1, $baris);
        $this->assertSame('Ahmad Fauzi', $baris[0]['Nama Wali']);
    }

    // ---- Lewat HTTP ----

    public function test_layar_menampilkan_kedua_daftar(): void
    {
        $w = $this->wali('Ahmad Fauzi', '081000000012', '250000');
        $this->santri($w, '910004', 'Luthfi Hakim', '75000');

        $this->actingAs($this->admin)->get(route('saldo_dompet.index'))
            ->assertOk()
            ->assertSee('Ahmad Fauzi')
            ->assertSee('Luthfi Hakim')
            ->assertSee('Dompet Wali')
            ->assertSee('Dompet &amp; Tabungan Santri', false);
    }

    public function test_unduhan_csv_bisa_diambil(): void
    {
        $this->wali('Ahmad Fauzi', '081000000013', '250000');

        $this->actingAs($this->admin)
            ->get(route('saldo_dompet.unduh', ['lingkup' => 'wali', 'format' => 'csv']))
            ->assertOk();
    }

    public function test_lingkup_yang_tak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->get(route('saldo_dompet.unduh', ['lingkup' => 'karyawan']))
            ->assertNotFound();
    }

    public function test_pemilih_kolom_menanyakan_daftar_kolom_ke_alamat_yang_sama(): void
    {
        $this->wali('Ahmad Fauzi', '081000000014', '250000');

        $this->actingAs($this->admin)
            ->get(route('saldo_dompet.unduh', ['lingkup' => 'santri']).'?kolom=daftar')
            ->assertOk()
            ->assertJsonStructure(['kolom']);
    }
}
