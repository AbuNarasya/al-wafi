<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalStep;
use App\Models\Bagian;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\User;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatLevelPengajuan;
use Tests\TestCase;

/**
 * JUMLAH LEVEL PENGAJUAN BISA DISESUAIKAN.
 *
 * Sebelum ini masternya terkunci empat baris, dan wewenangnya dipaku pada
 * ANGKANYA di selusin tempat ("hanya peringkat 4 yang boleh mengajukan").
 * Akibatnya level kelima bisa dibayangkan tapi tak pernah berfungsi: orang di
 * dalamnya tak boleh mengajukan apa pun dan tak menyetujui apa pun.
 *
 * Yang dijaga di sini:
 *  1. Peringkat tinggal urutan — yang menentukan wewenang adalah TANDA PERAN,
 *     dan angka berapa pun bisa memegangnya.
 *  2. Level nonaktif benar-benar padam wewenangnya, bukan sekadar tersembunyi.
 *  3. Masternya tak boleh dipakai untuk melumpuhkan sistemnya sendiri —
 *     menghapus level yang masih dipakai, atau mencabut pemohon yang terakhir.
 */
class LevelPengajuanFleksibelTest extends TestCase
{
    use MembuatLevelPengajuan;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian Satu', 'level' => 3]);
        $this->admin = User::create([
            'username' => 'lpf_admin', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    private function pengguna(string $username, ?int $peringkat, ?string $bagian = null): User
    {
        return User::create([
            'username' => $username, 'nama' => $username, 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => $bagian,
            'peringkat_pengajuan' => $peringkat, 'status' => 'aktif',
        ]);
    }

    /**
     * Gerbang pengajuan pembayaran: peran, bukan angka.
     *
     * Lolosnya gerbang dibuktikan lewat galat BERIKUTNYA (belum berbagian) —
     * itulah penjaga persis sesudahnya, jadi berubahnya pesan membuktikan yang
     * pertama sudah dilewati tanpa perlu membangun seluruh rantai persetujuan.
     */
    public function test_level_kelima_boleh_mengajukan_setelah_diberi_tanda(): void
    {
        $this->buatLevelPengajuan(5, 'Pelaksana');
        $orang = $this->pengguna('pelaksana', 5);
        $svc = new PengajuanPembayaranService;

        try {
            $svc->create([], $orang->id_pengguna);
            $this->fail('harus 403');
        } catch (AppException $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('tidak berwenang', $e->getMessage());
        }

        LevelPengajuan::whereKey(5)->update(['boleh_ajukan_pembayaran' => true]);

        try {
            $svc->create([], $orang->refresh()->id_pengguna);
            $this->fail('harus lolos gerbang peran, lalu tertahan bagian');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('bagian', $e->getMessage());
        }
    }

    public function test_level_nonaktif_kehilangan_seluruh_perannya(): void
    {
        $this->buatLevelPengajuan(4, 'Staff'); // bawaannya boleh mengajukan
        $staff = $this->pengguna('staff', 4, 'B1');
        $this->assertTrue($staff->berperanPengajuan('boleh_ajukan_pembayaran'));

        LevelPengajuan::whereKey(4)->update(['status' => 'nonaktif']);

        // Relasi di-muat ulang: tandanya memang masih true di barisnya, yang
        // berubah statusnya — dan itu harus cukup untuk memadamkan wewenangnya.
        $this->assertFalse($staff->fresh()->berperanPengajuan('boleh_ajukan_pembayaran'));
    }

    public function test_pengguna_boleh_berperingkat_di_luar_satu_sampai_empat(): void
    {
        $this->buatLevelPengajuan(4, 'Staff');
        // Level ke-7 tanpa peran apa pun: murni anak tangga penyetuju.
        $this->buatLevelPengajuan(7, 'Dewan Pengawas');

        $this->actingAs($this->admin)->post(route('users.store'), [
            'username' => 'pengawas', 'nama' => 'Pengawas', 'password' => 'rahasia1',
            'kode_level' => 'L1', 'status' => 'aktif', 'peringkat_pengajuan' => 7,
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, User::where('username', 'pengawas')->value('peringkat_pengajuan'));
    }

    public function test_level_terikat_bagian_mewajibkan_penggunanya_berbagian(): void
    {
        $this->buatLevelPengajuan(4, 'Staff');
        $this->buatLevelPengajuan(5, 'Koordinator', ['terikat_bagian' => true]);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'username' => 'koor', 'nama' => 'Koordinator', 'password' => 'rahasia1',
            'kode_level' => 'L1', 'status' => 'aktif', 'peringkat_pengajuan' => 5,
        ])->assertSessionHasErrors('kode_bagian');

        $this->actingAs($this->admin)->post(route('users.store'), [
            'username' => 'koor', 'nama' => 'Koordinator', 'password' => 'rahasia1',
            'kode_level' => 'L1', 'status' => 'aktif', 'peringkat_pengajuan' => 5,
            'kode_bagian' => 'B1',
        ])->assertSessionHasNoErrors();
    }

    public function test_level_yang_masih_dipakai_tak_bisa_dihapus(): void
    {
        $this->buatLevelPengajuan(4, 'Staff');
        $this->buatLevelPengajuan(3, 'Mudir Bagian');
        $this->pengguna('staff', 4, 'B1');

        // Dipakai pengguna.
        $this->actingAs($this->admin)->delete(route('level_pengajuan.destroy', 4))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('level_pengajuan', ['peringkat' => 4]);

        // Dipakai tahap rantai — penghalang kedua, terpisah dari yang pertama.
        ApprovalFlow::create(['kode_flow' => 'UJI', 'jenis_dokumen' => 'PengajuanPembayaran', 'nama_flow' => 'Uji', 'status' => 'aktif']);
        ApprovalStep::create(['kode_flow' => 'UJI', 'urutan' => 1, 'nama_tahap' => 'Mudir', 'peringkat' => 3, 'scope' => 'bagian']);
        $this->actingAs($this->admin)->delete(route('level_pengajuan.destroy', 3))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('level_pengajuan', ['peringkat' => 3]);

        // Yang tak dipakai siapa pun: terhapus.
        $this->buatLevelPengajuan(9, 'Sisipan');
        $this->actingAs($this->admin)->delete(route('level_pengajuan.destroy', 9))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('level_pengajuan', ['peringkat' => 9]);
    }

    /**
     * Pemohon TERAKHIR tak boleh dicabut. Tanpa penjaga ini seluruh modul
     * Pengajuan Pembayaran berhenti bisa dipakai siapa pun, dan pesannya justru
     * menyuruh mengatur level — nasihat yang tak menolong orang yang tak tahu
     * bahwa inilah yang terjadi.
     */
    public function test_pemohon_terakhir_tak_boleh_dicabut(): void
    {
        $this->buatLevelPengajuan(4, 'Staff');

        $isi = ['nama' => 'Staff', 'status' => 'aktif', 'keterangan' => null, 'terikat_bagian' => 1];

        $this->actingAs($this->admin)->put(route('level_pengajuan.update', 4), $isi)
            ->assertSessionHas('error');
        $this->assertTrue(LevelPengajuan::find(4)->boleh_ajukan_pembayaran);

        // Menonaktifkannya pun sama saja — level mati tak memegang peran apa pun.
        $this->actingAs($this->admin)
            ->put(route('level_pengajuan.update', 4), array_merge($isi, ['boleh_ajukan_pembayaran' => 1, 'status' => 'nonaktif']))
            ->assertSessionHas('error');

        // Begitu ada pemohon lain, pencabutannya jadi boleh.
        $this->buatLevelPengajuan(5, 'Pelaksana', ['boleh_ajukan_pembayaran' => true]);
        $this->actingAs($this->admin)->put(route('level_pengajuan.update', 4), $isi)
            ->assertSessionHas('status');
        $this->assertFalse(LevelPengajuan::find(4)->boleh_ajukan_pembayaran);
    }

    public function test_menambah_level_lewat_master(): void
    {
        $this->buatLevelPengajuan(4, 'Staff');

        $this->actingAs($this->admin)->post(route('level_pengajuan.store'), [
            'peringkat' => 6, 'nama' => 'Bendahara Yayasan', 'status' => 'aktif',
            'lingkup_semua' => 1,
        ])->assertSessionHas('status');

        $baru = LevelPengajuan::find(6);
        $this->assertSame('Bendahara Yayasan', $baru->nama);
        $this->assertTrue($baru->lingkup_semua);
        // Centang yang tak dikirim = mati; tanpa itu peran tak pernah bisa dimatikan.
        $this->assertFalse($baru->boleh_ajukan_pembayaran);

        // Peringkat kembar ditolak — ia kunci utama & dirujuk pengguna.
        $this->actingAs($this->admin)->post(route('level_pengajuan.store'), [
            'peringkat' => 6, 'nama' => 'Kembar', 'status' => 'aktif',
        ])->assertSessionHasErrors('peringkat');
    }
}
