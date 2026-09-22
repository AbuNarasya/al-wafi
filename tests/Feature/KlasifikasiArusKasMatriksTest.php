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
