<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MATRIKS KLASIFIKASI ARUS KAS.
 *
 * Kolomnya sudah lama bisa disunting satu per satu lewat form akun; layar ini
 * mengerjakan puluhan sekaligus. Yang dijaga di sini:
 *
 *  1. Hanya akun NERACA yang tampil & boleh disentuh. Menulis klasifikasi ke
 *     akun Pendapatan lewat pintu ini akan memindahkannya diam-diam di Laporan
 *     Arus Kas — dan kiriman form bisa disusun sendiri.
 *  2. Nilai yang tak berubah tidak ditulis ulang. Tanpa itu setiap simpan
 *     menyentuh seluruh baris dan jejak auditnya penuh perubahan semu.
 *  3. Rekening kas ditandai, bukan disembunyikan.
 */
class KlasifikasiArusKasMatriksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'kak_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        // Akar kelompok menentukan akun mana yang termasuk neraca.
        foreach (['1' => 'Aset', '2' => 'Liabilitas', '3' => 'Ekuitas', '4' => 'Pendapatan'] as $kode => $nama) {
            CoaGroup::create(['kode_grup' => $kode, 'nama_grup' => $nama]);
        }

        CoaDetail::create(['kode_coa' => '1.1.01.001', 'nama_coa' => 'Kas Ditangan', 'kode_grup' => '1', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => '1.1.02.001', 'nama_coa' => 'Piutang SPP', 'kode_grup' => '1', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => '1.2.01.001', 'nama_coa' => 'Kendaraan', 'kode_grup' => '1', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => '2.2.01.001', 'nama_coa' => 'Hutang Bank', 'kode_grup' => '2', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '4.1.01.001', 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => '4', 'jenis_saldo' => 'kredit']);

        BankAccount::create(['kode_coa' => '1.1.01.001', 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
    }

    public function test_layar_hanya_memuat_akun_neraca_dan_menandai_rekening_kas(): void
    {
        $r = $this->actingAs($this->admin)->get(route('coa.klasifikasi.index'))->assertOk();

        $r->assertSee('Piutang SPP')->assertSee('Kendaraan')->assertSee('Hutang Bank');
        // Pendapatan selalu operasi — tak ada yang perlu diputuskan, jadi tak ditampilkan.
        $r->assertDontSee('Pendapatan SPP');
        // Rekening kas TAMPIL, dengan penanda; menghilangkannya hanya membuat
        // orang mencarinya dan mengira ada yang rusak.
        $r->assertSee('Kas Ditangan')->assertSee('rekening kas', false);
    }

    public function test_menyimpan_banyak_akun_sekaligus(): void
    {
        $this->actingAs($this->admin)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => [
                '1.1.02.001' => 'operasi',
                '1.2.01.001' => 'investasi',
                '2.2.01.001' => 'pendanaan',
            ],
        ])->assertSessionHas('status');

        $this->assertSame('operasi', CoaDetail::find('1.1.02.001')->klasifikasi_arus_kas);
        $this->assertSame('investasi', CoaDetail::find('1.2.01.001')->klasifikasi_arus_kas);
        $this->assertSame('pendanaan', CoaDetail::find('2.2.01.001')->klasifikasi_arus_kas);
    }

    public function test_akun_di_luar_neraca_tak_bisa_disentuh_lewat_pintu_ini(): void
    {
        // Akun Pendapatan lahir bertanda `operasi` dari model; kiriman yang
        // menyusupkannya tak boleh mengubahnya menjadi apa pun.
        $this->assertSame('operasi', CoaDetail::find('4.1.01.001')->klasifikasi_arus_kas);

        $this->actingAs($this->admin)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => ['4.1.01.001' => 'pendanaan'],
        ])->assertSessionHas('status');

        $this->assertSame('operasi', CoaDetail::find('4.1.01.001')->klasifikasi_arus_kas);
    }

    public function test_nilai_yang_tak_berubah_tidak_ditulis_ulang(): void
    {
        CoaDetail::whereKey('1.1.02.001')->update(['klasifikasi_arus_kas' => 'operasi']);

        $this->actingAs($this->admin)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => ['1.1.02.001' => 'operasi', '1.2.01.001' => ''],
        ])->assertSessionHas('status', 'Tidak ada perubahan.');
    }

    public function test_pilihan_di_luar_daftar_ditolak(): void
    {
        $this->actingAs($this->admin)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => ['1.1.02.001' => 'entah'],
        ])->assertSessionHasErrors('klasifikasi.1.1.02.001');

        $this->assertNull(CoaDetail::find('1.1.02.001')->klasifikasi_arus_kas);
    }

    /**
     * Jumlah kueri dikunci — inilah yang sempat membuat layar ini TIDAK BISA
     * DIBUKA di produksi.
     *
     * Versi pertama memanggil `CoaDetail::akarKelompok()` dua kali per akun, dan
     * helper itu menelusuri pohon grup dengan satu `find()` per tingkat: 109 akun
     * menjadi 461 kueri. Di laptop (Postgres di mesin yang sama) itu tak terasa
     * sama sekali; dari Hostinger ke Neon Singapura tiap kueri berbiaya ±40 ms,
     * jadi halamannya butuh belasan detik dan KEHABISAN WAKTU — bukan melambat,
     * melainkan gagal dimuat.
     *
     * Ambangnya sengaja longgar (25): yang dijaga bukan angka persisnya,
     * melainkan bahwa jumlahnya tidak tumbuh mengikuti banyaknya akun.
     */
    public function test_layar_tak_menembak_kueri_per_akun(): void
    {
        // Grup bertingkat, supaya penelusuran ke akar benar-benar berjalan.
        CoaGroup::create(['kode_grup' => '1.1', 'nama_grup' => 'Aset Lancar', 'kode_induk' => '1']);
        CoaGroup::create(['kode_grup' => '1.1.1', 'nama_grup' => 'Kas & Setara', 'kode_induk' => '1.1']);
        for ($i = 1; $i <= 60; $i++) {
            CoaDetail::create([
                'kode_coa' => sprintf('1.1.90.%03d', $i), 'nama_coa' => "Akun Uji {$i}",
                'kode_grup' => '1.1.1', 'jenis_saldo' => 'debet',
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('coa.klasifikasi.index'))->assertOk()->assertSee('Akun Uji 60');
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $jumlah, "Layar ini menembakkan {$jumlah} kueri untuk 65 akun — "
            .'pertanda akar kelompok ditelusuri per akun lagi, bukan lewat petanya.');
    }

    /**
     * Rekening kas: dropdown-nya dikunci di layar, DAN kirimannya ditolak.
     * Penguncian yang hanya ada di layar bukan penguncian — kiriman form bisa
     * disusun sendiri.
     */
    public function test_rekening_kas_dikunci_dan_kirimannya_tak_menembus(): void
    {
        $this->actingAs($this->admin)->get(route('coa.klasifikasi.index'))->assertOk()
            ->assertSee('rekening kas — tak perlu diisi', false);

        $this->actingAs($this->admin)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => ['1.1.01.001' => 'operasi'],
        ])->assertSessionHas('status', 'Tidak ada perubahan.');

        $this->assertNull(CoaDetail::find('1.1.01.001')->klasifikasi_arus_kas);
    }

    /**
     * Sisa nilai dari sebelum akun itu terdaftar sebagai rekening kas HARUS bisa
     * dikosongkan — mengunci yang terlanjur terisi membuatnya mustahil dibereskan
     * dari layar mana pun.
     */
    public function test_rekening_kas_yang_terlanjur_terisi_masih_bisa_dikosongkan(): void
    {
        CoaDetail::whereKey('1.1.01.001')->update(['klasifikasi_arus_kas' => 'operasi']);

        $this->actingAs($this->admin)->get(route('coa.klasifikasi.index'))->assertOk()
            ->assertSee('Rekening kas seharusnya tak berklasifikasi', false);

        $this->actingAs($this->admin)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => ['1.1.01.001' => ''],
        ])->assertSessionHas('status');

        $this->assertNull(CoaDetail::find('1.1.01.001')->klasifikasi_arus_kas);
    }

    public function test_menyimpan_menuntut_hak_ubah_coa(): void
    {
        $user = User::create([
            'username' => 'kak_lihat', 'nama' => 'Pelihat', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        HakAksesModul::create([
            'id_pengguna' => $user->id_pengguna, 'kode_modul' => 'coa-detail',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);
        Akses::lupakan();

        // Boleh melihat…
        $this->actingAs($user)->get(route('coa.klasifikasi.index'))->assertOk();
        // …tetapi tidak menyimpan.
        $this->actingAs($user)->put(route('coa.klasifikasi.simpan'), [
            'klasifikasi' => ['1.1.02.001' => 'operasi'],
        ])->assertForbidden();

        $this->assertNull(CoaDetail::find('1.1.02.001')->klasifikasi_arus_kas);
    }
}
