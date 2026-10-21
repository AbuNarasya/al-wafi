<?php

namespace Tests\Feature;

use App\Support\AlamatPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DOMAIN PORTAL WALI SENDIRI (keputusan user 21 Okt 2026, parents.al-wafi.sch.id) — config/portal.php.
 */
class DomainPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_tanpa_domain_portal_tak_berubah(): void
    {
        config(['portal.domain' => '']);
        $this->get('http://localhost/wali/masuk')->assertOk();
        $this->assertSame(route('portal.masuk'), AlamatPortal::rute('portal.masuk'));
    }

    public function test_domain_portal_hanya_membuka_portal(): void
    {
        config(['portal.domain' => 'parents.contoh.test']);

        $this->get('http://parents.contoh.test/')->assertRedirect('/wali/');
        $this->get('http://parents.contoh.test/wali/masuk')->assertOk();
        // Layar & login staf tak terbuka dari alamat wali.
        $this->get('http://parents.contoh.test/login')->assertNotFound();
        $this->get('http://parents.contoh.test/datawali')->assertNotFound();
        $this->post('http://parents.contoh.test/login', ['username' => 'admin', 'password' => 'x'])->assertNotFound();
    }

    public function test_alamat_lama_dialihkan_dan_link_staf_memakai_domain_portal(): void
    {
        config(['portal.domain' => 'parents.contoh.test']);

        // Link verifikasi WA lama (erp.…/wali/v/<kode>) tetap jalan.
        $kode = str_repeat('a', 32);
        $this->get("http://localhost/wali/v/{$kode}?x=1")->assertStatus(301)
            ->assertRedirect("https://parents.contoh.test/wali/v/{$kode}?x=1");
        $this->get('http://localhost/wali')->assertStatus(301)->assertRedirect('https://parents.contoh.test/wali');
        // Kiriman (POST) dari halaman lama tak dialihkan.
        $this->post('http://localhost/wali/keluar')->assertStatus(302)->assertRedirectContains('localhost');
        // Domain staf tetap jalan.
        $this->get('http://localhost/login')->assertOk();

        $this->assertSame('https://parents.contoh.test/wali/masuk', AlamatPortal::rute('portal.masuk'));
    }
}
