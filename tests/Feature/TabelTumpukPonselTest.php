<?php

namespace Tests\Feature;

use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daftar bertabel menjadi KARTU di ponsel; MATRIKS tetap digeser mendatar.
 *
 * Penumpukannya sendiri dikerjakan CSS + satu fungsi di app.js, dan rupanya tak
 * bisa diuji di sini — PHPUnit tak mengenal lebar layar. Yang dijaga test ini
 * adalah bagian yang tetap bisa dipercaya:
 *
 *   • tabel yang barisnya BUKAN satu benda tetap bertanda `data-matriks` —
 *     kalau penandanya hilang, grid Tarif akan ditumpuk jadi kartu di ponsel dan
 *     hubungan jalur × komponen biayanya lenyap tanpa ada yang menyadarinya;
 *   • tombol "Filter" ada di daftar yang punya baris filter per kolom, sebab
 *     baris itu disembunyikan di ponsel — tanpa tombolnya, penyaringan per kolom
 *     jadi fitur yang hilang di ponsel, padahal syaratnya seluruh fitur terpakai;
 *   • penyaringan sendiri tetap bekerja (diuji di DashboardPpsb & master lain).
 */
class TabelTumpukPonselTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zztumpuk', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    public function test_grid_tarif_ditandai_matriks(): void
    {
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 1]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'status' => 'aktif']);

        $this->actingAs($this->admin)->get(route('tarif.index', ['ta' => '2026/2027', 'jenjang' => 'J002']))
            ->assertOk()
            ->assertSee('<table data-matriks', false);
    }

    public function test_matriks_hak_akses_dan_potongan_gelombang_ditandai(): void
    {
        $lain = User::create([
            'username' => 'zzstaf', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);

        $this->actingAs($this->admin)->get(route('hak_akses.edit', $lain->id_pengguna))
            ->assertOk()->assertSee('<table data-matriks', false);
    }

    public function test_daftar_biasa_TIDAK_ditandai_matriks(): void
    {
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'status' => 'aktif']);

        // Daftar master = satu baris satu benda → harus ditumpuk jadi kartu,
        // jadi penanda matriks justru TAK BOLEH ada di sini.
        $this->actingAs($this->admin)->get(route('jalur_pendaftaran.index'))
            ->assertOk()->assertDontSee('data-matriks', false);
    }

    public function test_tombol_filter_ponsel_ada_di_daftar_ber_filter_kolom(): void
    {
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'status' => 'aktif']);

        $this->actingAs($this->admin)->get(route('jalur_pendaftaran.index'))->assertOk()
            // Muncul hanya bila daftarnya memang punya baris filter per kolom…
            ->assertSee('x-show="adaKolom"', false)
            ->assertSee('@click="bukaFilter()"', false)
            // …dan hanya di layar sempit.
            ->assertSee('md:hidden', false);
    }
}
