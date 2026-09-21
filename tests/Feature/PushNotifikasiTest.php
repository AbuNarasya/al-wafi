<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\Notification;
use App\Models\PushLangganan;
use App\Models\User;
use App\Services\Modules\NotificationService;
use App\Services\Modules\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUSH NOTIFICATION — langganan perangkat & penyaringan antrean.
 *
 * Yang TIDAK diuji di sini adalah pengiriman sungguhan ke layanan push: itu
 * panggilan HTTPS ke server Google/Mozilla/Apple, dan test yang menembak ke
 * luar jaringan akan gagal secara acak di mesin yang berbeda. Yang dijaga
 * adalah seluruh keputusan DI SISI KITA — siapa disimpan, siapa disaring,
 * siapa ditandai — karena di situlah kesalahannya senyap.
 */
class PushNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->staf = User::create([
            'username' => 'zzpn_staf', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    private function langganan(array $ubah = []): array
    {
        return array_merge([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/CONTOH-'.uniqid(),
            'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)],
        ], $ubah);
    }

    private function notifikasi(string $jenis, array $ubah = []): Notification
    {
        return Notification::create(array_merge([
            'id_pengguna' => $this->staf->id_pengguna,
            'judul' => 'Ada yang menunggu', 'pesan' => 'Periksa sekarang.',
            'jenis' => $jenis, 'ref_jenis' => 'Uji', 'ref_id' => '1',
            'dibaca' => false, 'created_at' => now(),
        ], $ubah));
    }

    // ---- Langganan ----

    public function test_perangkat_yang_sama_mendaftar_ulang_memperbarui_bukan_menggandakan(): void
    {
        $svc = new PushService;
        $data = $this->langganan();

        $svc->simpanLangganan($this->staf->id_pengguna, $data, 'Mozilla/5.0 (Linux; Android 13) Chrome/120');
        $svc->simpanLangganan($this->staf->id_pengguna, $data, 'Mozilla/5.0 (Linux; Android 13) Chrome/120');

        $this->assertSame(1, PushLangganan::count(),
            'Tanpa indeks unik pada endpoint, satu orang akan menerima notifikasi yang sama berkali-kali.');
    }

    public function test_satu_orang_boleh_punya_beberapa_perangkat(): void
    {
        $svc = new PushService;
        $svc->simpanLangganan($this->staf->id_pengguna, $this->langganan(), 'Android');
        $svc->simpanLangganan($this->staf->id_pengguna, $this->langganan(), 'Windows');

        $this->assertSame(2, PushLangganan::count(), 'Ponsel dan laptop harus bisa berdampingan.');
    }

    public function test_jenis_perangkat_dikenali_dari_user_agent(): void
    {
        $svc = new PushService;

        $this->assertSame('Android · Chrome', $svc->ringkasPerangkat('Mozilla/5.0 (Linux; Android 13) AppleWebKit Chrome/120 Safari/537'));
        $this->assertSame('iPhone · Safari', $svc->ringkasPerangkat('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit Version/17 Safari/605'));
        // Edge & Opera menyebut "Chrome" juga — urutan pemeriksaannya penting.
        $this->assertSame('Windows · Edge', $svc->ringkasPerangkat('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537 Edg/120'));
        $this->assertSame('Perangkat tak dikenal', $svc->ringkasPerangkat(null));
    }

    public function test_data_langganan_yang_tak_lengkap_ditolak(): void
    {
        $this->expectException(\App\Exceptions\AppException::class);

        (new PushService)->simpanLangganan($this->staf->id_pengguna, ['endpoint' => 'https://a.b/c'], null);
    }

    // ---- Penyaringan antrean ----

    public function test_hanya_jenis_tugas_yang_masuk_antrean_dorongan(): void
    {
        $tugas = $this->notifikasi(NotificationService::JENIS_TUGAS[0]);
        $kabar = $this->notifikasi('pembayaran_santri_disetujui');

        (new PushService)->kirimYangBelum();

        $this->assertNotNull($tugas->refresh()->didorong_pada);
        $this->assertNull($kabar->refresh()->didorong_pada,
            'Kabar biasa tak boleh ikut terdorong — mendorong semuanya akan membuat izinnya dicabut orang.');
    }

    public function test_notifikasi_lama_dikeluarkan_dari_antrean_tanpa_dikirim(): void
    {
        $lama = $this->notifikasi(NotificationService::JENIS_TUGAS[0]);
        // `created_at` sengaja tak fillable di model, jadi umurnya dimundurkan
        // lewat query builder — bukan lewat create().
        Notification::where('id', $lama->id)->update(['created_at' => now()->subDays(3)]);

        $hasil = (new PushService)->kirimYangBelum();

        // Ditandai supaya ia keluar dari antrean…
        $this->assertNotNull($lama->refresh()->didorong_pada);
        // …tetapi tak pernah benar-benar dikirim. Tanpa penjaga ini, sapuan
        // pertama akan meledakkan seluruh tugas lama sekaligus ke ponsel staf.
        $this->assertSame(0, $hasil['terkirim']);
    }

    public function test_notifikasi_yang_sudah_didorong_tak_disapu_lagi(): void
    {
        $this->notifikasi(NotificationService::JENIS_TUGAS[0], ['didorong_pada' => now()]);

        $this->assertSame(0, (new PushService)->kirimYangBelum()['disapu']);
    }

    public function test_tanpa_kunci_vapid_antrean_tetap_dibereskan_dan_tak_ada_yang_meledak(): void
    {
        config(['push.vapid.public' => '', 'push.vapid.private' => '']);
        $n = $this->notifikasi(NotificationService::JENIS_TUGAS[0]);

        $hasil = (new PushService)->kirimYangBelum();

        $this->assertSame(0, $hasil['terkirim']);
        $this->assertNotNull($n->refresh()->didorong_pada,
            'Antrean tak boleh menumpuk selamanya menunggu lingkungan yang mungkin tak pernah disiapkan.');
    }

    public function test_fitur_mati_saat_kuncinya_kosong(): void
    {
        config(['push.vapid.public' => '', 'push.vapid.private' => '']);

        $this->assertFalse((new PushService)->aktif());
        $this->assertNull((new PushService)->kunciPublik());
    }

    // ---- Lewat HTTP ----

    public function test_langganan_bisa_didaftarkan_dan_dimatikan_lewat_profil(): void
    {
        config(['push.vapid.public' => 'kunci-uji', 'push.vapid.private' => 'rahasia-uji']);
        $data = $this->langganan();

        $this->actingAs($this->staf)->postJson(route('profil.push.langganan'), $data)
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, PushLangganan::count());

        $this->actingAs($this->staf)->deleteJson(route('profil.push.berhenti'), ['endpoint' => $data['endpoint']])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, PushLangganan::count());
    }

    public function test_tak_bisa_mematikan_perangkat_milik_orang_lain(): void
    {
        config(['push.vapid.public' => 'kunci-uji', 'push.vapid.private' => 'rahasia-uji']);
        $lain = User::create([
            'username' => 'zzpn_lain', 'nama' => 'Orang Lain', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        $data = $this->langganan();
        (new PushService)->simpanLangganan($lain->id_pengguna, $data, null);

        $this->actingAs($this->staf)
            ->deleteJson(route('profil.push.berhenti'), ['endpoint' => $data['endpoint']])
            ->assertOk();

        $this->assertSame(1, PushLangganan::count(),
            'Endpoint milik orang lain tak boleh bisa dihapus hanya dengan menebaknya.');
    }

    public function test_pendaftaran_ditolak_bila_server_belum_disiapkan(): void
    {
        config(['push.vapid.public' => '', 'push.vapid.private' => '']);

        $this->actingAs($this->staf)
            ->postJson(route('profil.push.langganan'), $this->langganan())
            ->assertStatus(503)->assertJson(['ok' => false]);
    }

    /**
     * Dialihkan ke halaman masuk, BUKAN 401 — aplikasi ini sengaja hanya
     * menjawab JSON untuk rute `api/*` (lihat `shouldRenderJsonWhen` di
     * bootstrap/app.php). Yang dijaga di sini bukan kode statusnya melainkan
     * tak adanya baris yang lahir.
     */
    public function test_tamu_tak_bisa_mendaftarkan_perangkat(): void
    {
        $this->postJson(route('profil.push.langganan'), $this->langganan())
            ->assertRedirect(route('login'));

        $this->assertSame(0, PushLangganan::count());
    }
}
