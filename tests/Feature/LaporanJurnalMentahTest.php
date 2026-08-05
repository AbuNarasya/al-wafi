<?php

namespace Tests\Feature;

use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman laporan JURNAL MENTAH bisa dibuka.
 *
 * Test ini lahir dari cacat nyata: controller mengoper SELURUH kembalian
 * `jurnalMentah()` — yang berbentuk ['from','to','rows'] — sebagai `rows`,
 * sehingga baris pertama yang ditemui view adalah string tanggal dan halamannya
 * SELALU gagal 500. Bukan cacat ponsel: ia sama rusaknya di desktop, dan lolos
 * bertahan karena tak ada satu pun test yang pernah membuka halaman ini —
 * jalur unduhannya yang memakai ['rows'] dengan benar justru punya test.
 *
 * Karena itu yang diperiksa bukan hanya status 200, melainkan juga bahwa isi
 * barisnya benar-benar tercetak.
 */
class LaporanJurnalMentahTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_jurnal_mentah_terbuka_dan_menampilkan_barisnya(): void
    {
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $admin = User::create([
            'username' => 'zzjm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        CoaGroup::create(['kode_grup' => 'ZZJM', 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => '5.ZZJM.1', 'nama_coa' => 'Beban Uji', 'kode_grup' => 'ZZJM', 'jenis_saldo' => 'debet']);

        $entry = JournalEntry::create([
            'tanggal' => now()->toDateString(), 'referensi' => 'JU-UJI-1', 'sumber_modul' => 'jurnal_umum',
            'keterangan' => 'Jurnal percobaan', 'status' => 'aktif', 'id_pengguna' => $admin->id_pengguna,
        ]);
        $entry->lines()->create([
            'kode_coa' => '5.ZZJM.1', 'nama_coa' => 'Beban Uji',
            'debet' => '150000', 'kredit' => '0', 'keterangan' => 'Baris percobaan',
        ]);

        $this->actingAs($admin)->get(route('reports.jurnal'))->assertOk()
            ->assertSee('JU-UJI-1')
            ->assertSee('Beban Uji')
            ->assertDontSee('Tidak ada jurnal pada periode ini');
    }
}
