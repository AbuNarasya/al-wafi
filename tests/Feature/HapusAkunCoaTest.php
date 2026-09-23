<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * MENGHAPUS AKUN CHART OF ACCOUNT.
 *
 * Rute & method-nya sudah lama ada, tetapi tombolnya tak pernah dipasang — dan
 * penjaganya hanya menangkap galat KUNCI ASING. Hanya lima tabel yang punya
 * kunci asing ke `coa_detail`; sekitar tiga puluh tabel lain menyimpan
 * `kode_coa` tanpa penjaga apa pun.
 *
 * Yang paling berbahaya adalah `jenis_biaya`: menghapus akun pendapatan yang
 * dipakainya BERHASIL tanpa satu pun peringatan, lalu penagihan berikutnya
 * patah dengan galat yang tak menyebut sebabnya. Itulah yang dijaga di sini.
 */
class HapusAkunCoaTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'hca_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        CoaGroup::create(['kode_grup' => 'ZZHC', 'nama_grup' => 'Uji Hapus']);
        BusinessUnit::create(['kode_unit' => 'ZZHCU', 'nama_unit' => 'Unit Uji']);

        foreach ([
            ['4.ZZHC.PEND', 'Pendapatan Registrasi', 'kredit'],
            ['1.ZZHC.KAS', 'Kas Uji', 'debet'],
            ['1.ZZHC.BEBAS', 'Akun Tak Dipakai', 'debet'],
        ] as [$kode, $nama, $saldo]) {
            CoaDetail::create(['kode_coa' => $kode, 'nama_coa' => $nama, 'kode_grup' => 'ZZHC', 'jenis_saldo' => $saldo]);
        }
    }

    public function test_akun_yang_tak_dipakai_siapa_pun_terhapus(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('coa_detail.destroy', '1.ZZHC.BEBAS'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('coa_detail', ['kode_coa' => '1.ZZHC.BEBAS']);
    }

    /**
     * INTI-nya. `jenis_biaya` TIDAK punya kunci asing ke `coa_detail`, jadi
     * penghapusan ini dulu berhasil tanpa peringatan apa pun.
     */
    public function test_akun_yang_dipakai_jenis_biaya_ditolak_meski_tanpa_kunci_asing(): void
    {
        $this->buatBiaya([
            'kode' => 'REGHC', 'nama' => 'Registrasi', 'tipe' => 'registrasi',
            'kode_coa_pendapatan' => '4.ZZHC.PEND', 'kode_unit' => 'ZZHCU',
        ]);

        $r = $this->actingAs($this->admin)->delete(route('coa_detail.destroy', '4.ZZHC.PEND'));
        $r->assertSessionHas('error');

        // Pesannya menyebut SIAPA pemakainya — "tidak bisa dihapus" saja tak
        // menolong siapa pun mencari tahu apa yang harus dibereskan lebih dulu.
        $this->assertStringContainsString('jenis biaya', session('error'));
        $this->assertDatabaseHas('coa_detail', ['kode_coa' => '4.ZZHC.PEND']);
    }

    public function test_akun_yang_terdaftar_sebagai_rekening_kas_ditolak(): void
    {
        BankAccount::create(['kode_coa' => '1.ZZHC.KAS', 'nama_rekening' => 'Kas Uji',
            'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        $this->actingAs($this->admin)->delete(route('coa_detail.destroy', '1.ZZHC.KAS'))
            ->assertSessionHas('error');

        $this->assertStringContainsString('rekening kas', session('error'));
        $this->assertDatabaseHas('coa_detail', ['kode_coa' => '1.ZZHC.KAS']);
    }

    public function test_menghapus_menuntut_hak_hapus_coa(): void
    {
        $user = User::create([
            'username' => 'hca_ubah', 'nama' => 'Penyunting', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        HakAksesModul::create([
            'id_pengguna' => $user->id_pengguna, 'kode_modul' => 'coa-detail',
            'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true,
        ]);
        Akses::lupakan();

        // Tombolnya pun tak dirender bagi yang tak berhak. Yang diperiksa pesan
        // konfirmasinya, BUKAN URL-nya: alamat hapus (`/coa-detail/<kode>`)
        // kebetulan awalan dari alamat ubah (`/coa-detail/<kode>/edit`), jadi
        // memeriksa URL akan selalu menemukan tautan Ubah dan tak pernah benar.
        $this->actingAs($user)->get(route('coa.index'))->assertOk()
            ->assertDontSee('Hapus akun 1.ZZHC.BEBAS', false);

        $this->actingAs($user)->delete(route('coa_detail.destroy', '1.ZZHC.BEBAS'))
            ->assertForbidden();
        $this->assertDatabaseHas('coa_detail', ['kode_coa' => '1.ZZHC.BEBAS']);
    }

    public function test_tombol_hapus_muncul_bagi_yang_berhak(): void
    {
        $this->actingAs($this->admin)->get(route('coa.index'))->assertOk()
            ->assertSee('Hapus akun 1.ZZHC.BEBAS', false)
            ->assertSee('Hapus grup ZZHC', false);
    }

    // ---- Grup COA ----
    //
    // Penjaganya sudah lengkap sejak dulu (hanya sub-grup & akun detail yang
    // merujuk grup, keduanya berkunci asing dan keduanya diperiksa); yang tak
    // pernah ada adalah tombolnya di halaman bertab yang dipakai orang.

    public function test_grup_yang_masih_menaungi_akun_ditolak(): void
    {
        $this->actingAs($this->admin)->delete(route('coa_groups.destroy', 'ZZHC'))
            ->assertSessionHas('error');

        $this->assertStringContainsString('akun detail', session('error'));
        $this->assertDatabaseHas('coa_groups', ['kode_grup' => 'ZZHC']);
    }

    public function test_grup_yang_masih_menaungi_sub_grup_ditolak(): void
    {
        CoaGroup::create(['kode_grup' => 'ZZHD', 'nama_grup' => 'Induk Uji', 'level' => 1]);
        CoaGroup::create(['kode_grup' => 'ZZHD.1', 'nama_grup' => 'Anak Uji', 'kode_induk' => 'ZZHD', 'level' => 2]);

        $this->actingAs($this->admin)->delete(route('coa_groups.destroy', 'ZZHD'))
            ->assertSessionHas('error');

        $this->assertStringContainsString('sub-grup', session('error'));
        $this->assertDatabaseHas('coa_groups', ['kode_grup' => 'ZZHD']);
    }

    public function test_grup_kosong_terhapus(): void
    {
        CoaGroup::create(['kode_grup' => 'ZZHE', 'nama_grup' => 'Grup Kosong', 'level' => 1]);

        $this->actingAs($this->admin)->delete(route('coa_groups.destroy', 'ZZHE'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('coa_groups', ['kode_grup' => 'ZZHE']);
    }
}
