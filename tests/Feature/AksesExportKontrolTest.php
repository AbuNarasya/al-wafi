<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 21 Okt 2026: /export & /kontrol dulu cukup LOGIN — kasir mana pun bisa mengunduh jurnal mentah, buku besar,
 * daftar pengguna, dan data pribadi santri. Kini: export = hak `reports`, kontrol = hak `outstanding`, dan dataset
 * Pengguna / Kesantrian butuh hak modulnya sendiri.
 */
class AksesExportKontrolTest extends TestCase
{
    use RefreshDatabase;

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    public function test_tanpa_hak_laporan_export_dan_kontrol_tertutup(): void
    {
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $kasir = $this->pengguna('kasir', ['pos']);

        foreach (['/export', '/export/jurnal-mentah', '/export/dataset/users', '/export/dataset/santri-aktif', '/kontrol/ringkasan', '/kontrol/aging-ap'] as $url) {
            $this->actingAs($kasir)->get($url)->assertForbidden();
        }
    }

    public function test_hak_laporan_tak_otomatis_membuka_data_pengguna_dan_santri(): void
    {
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $akuntan = $this->pengguna('akuntan', ['reports', 'outstanding']);

        $this->actingAs($akuntan)->get('/export')->assertOk()->assertSee('Chart of Account')->assertDontSee('Santri Aktif')->assertDontSee('Pengguna');
        $this->actingAs($akuntan)->get('/export/dataset/coa-detail')->assertOk();
        $this->actingAs($akuntan)->get('/export/dataset/users')->assertForbidden();
        $this->actingAs($akuntan)->get('/export/dataset/santri-aktif')->assertForbidden();
        $this->actingAs($akuntan)->get('/kontrol/ringkasan')->assertOk();

        $staf = $this->pengguna('kesantrian', ['reports', 'santri']);
        $this->actingAs($staf)->get('/export/dataset/santri-aktif')->assertOk();
    }
}
