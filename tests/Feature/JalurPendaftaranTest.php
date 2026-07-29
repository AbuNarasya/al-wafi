<?php

namespace Tests\Feature;

use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Master Jalur Pendaftaran.
 *
 * Test ini lahir dari kelalaian nyata: saat kolom `tahun_ajaran` dibuang
 * (jalur berlaku lintas T.A), servicenya masih merujuk kolom itu sehingga
 * tombol Simpan melempar 500. Seluruh test lain lulus karena tak ada satu pun
 * yang benar-benar MENYIMPAN jalur lewat jalur normalnya — hanya membuat baris
 * langsung lewat model. Karena itu di sini alurnya diuji lewat HTTP.
 */
class JalurPendaftaranTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzjp_adm', 'nama' => 'Admin JP', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    public function test_simpan_jalur_tanpa_tahun_ajaran(): void
    {
        // "Tahun Ajaran" masih ada sebagai menu di sidebar, jadi yang diperiksa
        // isian formnya, bukan sekadar teksnya.
        $this->actingAs($this->admin)->get(route('jalur_pendaftaran.create'))->assertOk()
            ->assertSee('Nama Jalur')
            ->assertDontSee('name="tahun_ajaran"', false);

        $this->actingAs($this->admin)->post(route('jalur_pendaftaran.store'), [
            'kode' => '001', 'nama' => 'Reguler', 'status' => 'aktif',
        ])->assertRedirect(route('jalur_pendaftaran.index'));

        $jalur = JalurPendaftaran::findOrFail('001');
        $this->assertSame('Reguler', $jalur->nama);
        $this->assertSame('aktif', $jalur->status);
    }

    public function test_ubah_jalur(): void
    {
        JalurPendaftaran::create(['kode' => 'REG', 'nama' => 'Reguler']);

        $this->actingAs($this->admin)->put(route('jalur_pendaftaran.update', 'REG'), [
            'nama' => 'Reguler Umum', 'keterangan' => 'Jalur utama', 'status' => 'nonaktif',
        ])->assertRedirect();

        $jalur = JalurPendaftaran::findOrFail('REG');
        $this->assertSame('Reguler Umum', $jalur->nama);
        $this->assertSame('Jalur utama', $jalur->keterangan);
        $this->assertSame('nonaktif', $jalur->status);
    }

    public function test_kode_ganda_ditolak(): void
    {
        JalurPendaftaran::create(['kode' => 'REG', 'nama' => 'Reguler']);

        $this->actingAs($this->admin)
            ->post(route('jalur_pendaftaran.store'), ['kode' => 'REG', 'nama' => 'Reguler Lagi', 'status' => 'aktif'])
            ->assertSessionHasErrors('kode');

        $this->assertSame(1, JalurPendaftaran::count());
    }

    /**
     * Kelalaian kedua dari perubahan yang sama: form pendaftaran calon santri
     * masih MENGELOMPOKKAN jalur per `tahun_ajaran` (kolom yang sudah dibuang),
     * sehingga kelompoknya selalu kosong dan dropdown Jalur cuma berisi
     * "belum ada jalur untuk T.A ini" — pendaftaran calon jadi buntu.
     */
    public function test_dropdown_jalur_di_form_calon_santri_tak_bergantung_tahun_ajaran(): void
    {
        \App\Models\TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif', 'default_pendaftaran' => true]);
        \App\Models\TahunAjaran::create(['kode' => '2028/2029', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '002', 'nama' => 'Pindahan', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '003', 'nama' => 'Jalur Lama', 'status' => 'nonaktif']);

        // Diperiksa sebagai <option> sungguhan, bukan sekadar "namanya muncul
        // di halaman": versi lamanya pun memuat nama itu di dalam blob JSON
        // Alpine, padahal dropdownnya kosong.
        $this->actingAs($this->admin)->get(route('santri.create'))->assertOk()
            ->assertSee('<option value="001" >Reguler</option>', false)
            ->assertSee('<option value="002" >Pindahan</option>', false)
            ->assertDontSee('Jalur Lama')            // nonaktif tetap disembunyikan
            ->assertDontSee('belum ada jalur');
    }

    public function test_daftar_menampilkan_jalur_tanpa_kolom_tahun_ajaran(): void
    {
        JalurPendaftaran::create(['kode' => 'PDH', 'nama' => 'Pindahan', 'keterangan' => 'Dari sekolah lain']);

        $this->actingAs($this->admin)->get(route('jalur_pendaftaran.index'))->assertOk()
            ->assertSee('Pindahan')
            ->assertSee('Dari sekolah lain')
            ->assertDontSee('T.A</th>', false);
    }
}
