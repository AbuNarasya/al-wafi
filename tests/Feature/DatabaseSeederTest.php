<?php

namespace Tests\Feature;

use App\Models\JalurPendaftaran;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DatabaseSeeder dijalankan di ATAS SKEMA TERBARU.
 *
 * Test ini lahir dari deploy yang gagal: migration membuang kolom
 * `jalur_pendaftaran.tahun_ajaran`, tetapi seeder masih mengisinya, sehingga
 * `db:seed` di dalam container menabrak "column tahun_ajaran does not exist"
 * dan — karena start.sh memakai `set -e` — deploy berhenti. Seluruh 257 test
 * lain lulus karena TAK SATU PUN menjalankan seeder; fixture-nya membuat baris
 * sendiri. Kalau seeder tak pernah dijalankan test, ia hanya teruji di produksi.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_jalan_di_atas_skema_terbaru(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Jalur bawaan terbentuk TANPA kolom tahun ajaran (kini lintas T.A).
        $this->assertSame(3, JalurPendaftaran::whereIn('kode', ['reguler', 'tahfizh', 'beasiswa'])->count());
        $this->assertNotContains('tahun_ajaran', array_keys(JalurPendaftaran::firstOrFail()->getAttributes()));

        // Fondasi lain yang dibutuhkan supaya aplikasi bisa dipakai sama sekali.
        $this->assertNotNull(TahunAjaran::first());
        $this->assertTrue(User::where('username', 'admin')->exists());
    }

    /** Idempotent: container Render menjalankannya tiap kali menyala. */
    public function test_seeder_aman_dijalankan_dua_kali(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, JalurPendaftaran::whereIn('kode', ['reguler', 'tahfizh', 'beasiswa'])->count());
        $this->assertSame(1, User::where('username', 'admin')->count());
    }
}
