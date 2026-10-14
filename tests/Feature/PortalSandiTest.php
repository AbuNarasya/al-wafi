<?php

namespace Tests\Feature;

use App\Models\PerangkatWali;
use App\Models\Wali;
use App\Notifications\KodeMasukWali;
use App\Services\PortalWali\IngatPerangkat;
use App\Services\PortalWali\KataSandiWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Password Portal Wali (keputusan user 14 Okt 2026): kode email hanya pertama kali
 * & lupa password; sesudahnya email + password; perangkat diingat selamanya.
 */
class PortalSandiTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['portal-sandi:email:hasan@contoh.id', 'portal-sandi:ip:127.0.0.1'] as $k) {
            RateLimiter::clear($k);
        }
        $this->wali = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Hasan', 'telepon_ayah' => '081211110000',
            'email_ayah' => 'hasan@contoh.id', 'nama_ibu' => 'Ibu Hasanah', 'email_ibu' => 'hasanah@contoh.id',
            'nama' => 'Bapak Hasan', 'telepon' => '081211110000', 'status' => 'aktif']);
    }

    private function kode(string $email, bool $lupa = false): string
    {
        $this->post(route('portal.masuk'), ['email' => $email, 'lupa' => $lupa ? '1' : null])->assertRedirect(route('portal.kode'));
        $kode = '';
        Notification::assertSentOnDemand(KodeMasukWali::class, function (KodeMasukWali $n, array $s, AnonymousNotifiable $t) use ($email, &$kode) {
            if ($t->routes['mail'] === $email) {
                $kode = $n->kode;
            }

            return true;
        });

        return $kode;
    }

    private function keluar(): void
    {
        $this->post(route('portal.keluar'));
        $this->flushSession();
        Auth::guard('wali')->logout();
    }

    public function test_masuk_dengan_password_tanpa_kode(): void
    {
        (new KataSandiWali)->simpan($this->wali, 'ayah', 'rahasia123', 'rahasia123');

        $this->post(route('portal.masuk.sandi'), ['email' => ' Hasan@Contoh.id ', 'sandi' => 'rahasia123'])
            ->assertRedirect(route('portal.beranda'));
        $this->get(route('portal.beranda'))->assertOk();
        $this->assertSame('ayah', session('portal.peran'));
        Notification::assertNothingSent();
    }

    public function test_password_salah_pesannya_seragam_dan_dibatasi(): void
    {
        (new KataSandiWali)->simpan($this->wali, 'ayah', 'rahasia123', 'rahasia123');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->post(route('portal.masuk.sandi'), ['email' => 'hasan@contoh.id', 'sandi' => 'salah-salah']);
        $pesan = session('error');
        $this->post(route('portal.masuk.sandi'), ['email' => 'tak-terdaftar@contoh.id', 'sandi' => 'apa-saja'])
            ->assertSessionHas('error', $pesan);
        $this->assertGuest('wali');

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('portal.masuk.sandi'), ['email' => 'hasan@contoh.id', 'sandi' => 'salah-salah']);
        }
        // Ke-6 untuk email ini: diblokir walau password-nya kini benar.
        $this->post(route('portal.masuk.sandi'), ['email' => 'hasan@contoh.id', 'sandi' => 'rahasia123'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Terlalu banyak percobaan'));
        $this->assertGuest('wali');
    }

    public function test_pertama_kali_kode_lalu_wajib_buat_password(): void
    {
        $kode = $this->kode('hasan@contoh.id');
        $this->post(route('portal.kode'), ['kode' => $kode])->assertRedirect(route('portal.sandi.buat'));

        foreach (['portal.beranda', 'portal.tagihan', 'portal.dompet', 'portal.akun'] as $rute) {
            $this->get(route($rute))->assertRedirect(route('portal.sandi.buat'));
        }
        $this->post(route('portal.sandi.buat'), ['sandi' => 'pendek', 'sandi_ulang' => 'pendek'])->assertSessionHas('error');
        $this->post(route('portal.sandi.buat'), ['sandi' => 'rahasia123', 'sandi_ulang' => 'beda12345'])->assertSessionHas('error');
        $this->post(route('portal.sandi.buat'), ['sandi' => 'rahasia123', 'sandi_ulang' => 'rahasia123'])->assertRedirect(route('portal.beranda'));
        $this->get(route('portal.beranda'))->assertOk();

        // Masuk berikutnya: password saja.
        $this->keluar();
        $this->post(route('portal.masuk.sandi'), ['email' => 'hasan@contoh.id', 'sandi' => 'rahasia123'])->assertRedirect(route('portal.beranda'));
    }

    public function test_lupa_password_menyetel_ulang_dan_mencabut_perangkat_lain(): void
    {
        (new KataSandiWali)->simpan($this->wali, 'ayah', 'lama12345', 'lama12345');
        $lain = PerangkatWali::create(['id_wali' => $this->wali->id, 'peran' => 'ayah', 'token_hash' => str_repeat('b', 64),
            'terakhir_dipakai' => now(), 'kedaluwarsa_pada' => null]);
        $ibu = PerangkatWali::create(['id_wali' => $this->wali->id, 'peran' => 'ibu', 'token_hash' => str_repeat('c', 64),
            'terakhir_dipakai' => now(), 'kedaluwarsa_pada' => null]);

        $kode = $this->kode('hasan@contoh.id', lupa: true);
        // Sudah punya password, tapi lewat "Lupa password" → tetap wajib membuat yang baru.
        $this->post(route('portal.kode'), ['kode' => $kode])->assertRedirect(route('portal.sandi.buat'));
        $this->post(route('portal.sandi.buat'), ['sandi' => 'baru12345', 'sandi_ulang' => 'baru12345'])->assertRedirect(route('portal.beranda'));

        $this->assertNotNull($lain->fresh()->dicabut_pada, 'perangkat lain orang ini dikeluarkan');
        $this->assertNull($ibu->fresh()->dicabut_pada, 'perangkat ibu tak tersentuh');

        $this->keluar();
        $this->post(route('portal.masuk.sandi'), ['email' => 'hasan@contoh.id', 'sandi' => 'lama12345'])->assertSessionHas('error');
        $this->post(route('portal.masuk.sandi'), ['email' => 'hasan@contoh.id', 'sandi' => 'baru12345'])->assertRedirect(route('portal.beranda'));
    }

    public function test_ganti_password_dari_akun(): void
    {
        (new KataSandiWali)->simpan($this->wali, 'ayah', 'lama12345', 'lama12345');
        $sesi = $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ayah']);

        $sesi->get(route('portal.akun'))->assertOk()->assertSee('Password masuk');
        $this->post(route('portal.sandi.ganti'), ['sandi_lama' => 'keliru', 'sandi' => 'baru12345', 'sandi_ulang' => 'baru12345'])
            ->assertSessionHas('error');
        $this->post(route('portal.sandi.ganti'), ['sandi_lama' => 'lama12345', 'sandi' => 'baru12345', 'sandi_ulang' => 'baru12345'])
            ->assertRedirect(route('portal.akun'));
        $this->assertTrue((new KataSandiWali)->cocok($this->wali->fresh(), 'ayah', 'baru12345'));
    }

    public function test_perangkat_diingat_selamanya_dan_sesi_lama_tanpa_password_diarahkan_membuatnya(): void
    {
        // Perangkat yang diingat SEBELUM ada password: tetap pulih, tapi wajib membuat password dulu.
        PerangkatWali::create(['id_wali' => $this->wali->id, 'peran' => 'ayah', 'token_hash' => hash('sha256', 'TOKEN-UJI'),
            'terakhir_dipakai' => now()->subYears(2), 'kedaluwarsa_pada' => null]);

        $this->withCookie(IngatPerangkat::COOKIE, 'TOKEN-UJI')->get(route('portal.beranda'))
            ->assertRedirect(route('portal.sandi.buat'));
        $this->post(route('portal.sandi.buat'), ['sandi' => 'rahasia123', 'sandi_ulang' => 'rahasia123'])->assertRedirect(route('portal.beranda'));
        $this->get(route('portal.beranda'))->assertOk();
    }
}
