<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Level;
use App\Models\ResetSandiStaf;
use App\Models\User;
use App\Notifications\PemberitahuanAkunStaf;
use App\Notifications\TautanResetSandiStaf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * RESET KATA SANDI STAF LEWAT EMAIL + WAJIB GANTI SANDI BUATAN ADMIN (keputusan user 12 Nov 2026).
 */
class ResetSandiStafTest extends TestCase
{
    use RefreshDatabase;

    private User $siti;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        RateLimiter::clear('reset-sandi-staf:ip:127.0.0.1');

        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staff', 'max_transaksi' => null]);
        $this->siti = User::create(['username' => 'siti', 'nama' => 'Siti', 'password_hash' => Hash::make('lama123'),
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif', 'email' => 'siti@contoh.id']);
        $this->admin = User::create(['username' => 'admin_rs', 'nama' => 'Admin', 'password_hash' => Hash::make('admin123'),
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
    }

    /** Minta tautan lewat layar, kembalikan alamat tautan yang "terkirim". */
    private function mintaTautan(string $akun): string
    {
        $this->post(route('sandi_staf.kirim'), ['akun' => $akun])->assertRedirect(route('sandi_staf.lupa'))
            ->assertSessionHas('terkirim', 's***@contoh.id');
        $tautan = '';
        Notification::assertSentTo($this->siti, TautanResetSandiStaf::class, function (TautanResetSandiStaf $n) use (&$tautan) {
            $tautan = $n->tautan;

            return true;
        });

        return $tautan;
    }

    public function test_halaman_login_menautkan_lupa_kata_sandi(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('sandi_staf.lupa'));
        $this->get(route('sandi_staf.lupa'))->assertOk()->assertSee('Username atau email');
    }

    public function test_reset_lewat_username_atau_email_lalu_masuk_dengan_sandi_baru(): void
    {
        foreach (['SITI', 'Siti@Contoh.id'] as $akun) {
            $tautan = $this->mintaTautan($akun);
            $this->get($tautan)->assertOk()->assertSee('siti');
        }
        // Tautan pertama gugur oleh tautan kedua — hanya yang terbaru berlaku.
        $this->assertSame(1, ResetSandiStaf::whereNull('dipakai_pada')->where('kedaluwarsa_pada', '>', now())->count());

        // Sesi lama akun itu (perangkat lain) harus gugur.
        DB::table('sessions')->insert(['id' => 'sesi-lain', 'user_id' => $this->siti->id_pengguna, 'payload' => 'x', 'last_activity' => time()]);

        $this->post($tautan, ['password_baru' => 'baru456', 'password_baru_confirmation' => 'baru456'])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('baru456', $this->siti->refresh()->password_hash));
        $this->assertFalse(DB::table('sessions')->where('id', 'sesi-lain')->exists());
        $this->assertTrue(ActivityLog::where('aksi', 'reset_sandi_email')->where('ref_id', (string) $this->siti->id_pengguna)->exists());
        Notification::assertSentTo($this->siti, PemberitahuanAkunStaf::class);

        // Sekali pakai.
        $this->get($tautan)->assertOk()->assertSee('sudah tidak berlaku');
        $this->post($tautan, ['password_baru' => 'lagi789', 'password_baru_confirmation' => 'lagi789'])->assertSessionHasErrors('password_baru');

        $this->post(route('login'), ['username' => 'siti', 'password' => 'baru456'])->assertRedirect(route('beranda'));
    }

    public function test_tautan_kedaluwarsa_ditolak(): void
    {
        $tautan = $this->mintaTautan('siti');
        $this->travel(\App\Services\Modules\ResetSandiStafService::MENIT + 1)->minutes();

        $this->get($tautan)->assertOk()->assertSee('sudah tidak berlaku');
        $this->post($tautan, ['password_baru' => 'baru456', 'password_baru_confirmation' => 'baru456'])->assertSessionHasErrors('password_baru');
        $this->assertTrue(Hash::check('lama123', $this->siti->refresh()->password_hash));
    }

    /** Pesan TERANG (pilihan user): tak terdaftar, belum beremail, nonaktif — disebut apa adanya, tanpa kirim apa pun. */
    public function test_pesan_terang_untuk_akun_tak_terdaftar_tanpa_email_dan_nonaktif(): void
    {
        $this->post(route('sandi_staf.kirim'), ['akun' => 'tidakada'])->assertSessionHasErrors(['akun' => 'Username atau email tidak terdaftar. Periksa kembali penulisannya.']);
        $this->post(route('sandi_staf.kirim'), ['akun' => 'admin_rs'])->assertSessionHasErrors('akun');
        $this->siti->update(['status' => 'nonaktif']);
        $this->post(route('sandi_staf.kirim'), ['akun' => 'siti'])->assertSessionHasErrors(['akun' => 'Akun ini nonaktif. Hubungi administrator.']);

        Notification::assertNothingSent();
    }

    public function test_permintaan_dibatasi_per_akun(): void
    {
        RateLimiter::clear('reset-sandi-staf:akun:'.$this->siti->id_pengguna);
        foreach (range(1, 3) as $_) {
            $this->post(route('sandi_staf.kirim'), ['akun' => 'siti'])->assertSessionHasNoErrors();
        }
        $this->post(route('sandi_staf.kirim'), ['akun' => 'siti'])->assertSessionHasErrors('akun');
    }

    public function test_sandi_dari_admin_wajib_diganti_sendiri_dan_tercatat(): void
    {
        $this->actingAs($this->admin)->put(route('users.update', $this->siti), [
            'username' => 'siti', 'nama' => 'Siti', 'kode_level' => 'L1', 'status' => 'aktif',
            'password' => 'sementara1', 'email' => 'SITI.baru@contoh.id',
        ])->assertRedirect(route('users.index'));

        $this->siti->refresh();
        $this->assertTrue($this->siti->harus_ganti_sandi);
        $this->assertSame('siti.baru@contoh.id', $this->siti->email);
        $this->assertTrue(ActivityLog::where('aksi', 'ganti_sandi_oleh_admin')->exists());
        $this->assertTrue(ActivityLog::where('aksi', 'ubah_email_akun')->exists());
        // Alamat lama dikabari.
        Notification::assertSentOnDemand(PemberitahuanAkunStaf::class);

        // Siti hanya bisa membuka Profil sampai ia membuat sandinya sendiri.
        $this->actingAs($this->siti)->get(route('beranda'))->assertRedirect(route('profil.index'));
        $this->get(route('profil.index'))->assertOk()->assertSee('dibuat oleh administrator');
        $this->put(route('profil.kata_sandi'), ['password_lama' => 'sementara1', 'password_baru' => 'milikku9',
            'password_baru_confirmation' => 'milikku9'])->assertRedirect(route('profil.index'));
        $this->assertFalse($this->siti->refresh()->harus_ganti_sandi);
        $this->get(route('beranda'))->assertOk();
    }

    public function test_email_unik_tanpa_membedakan_huruf_besar(): void
    {
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), [
            'username' => 'admin_rs', 'nama' => 'Admin', 'kode_level' => 'L1', 'status' => 'aktif', 'email' => 'SITI@contoh.id',
        ])->assertSessionHasErrors('email');
    }

    public function test_staf_mengubah_email_sendiri_di_profil_dengan_kata_sandi(): void
    {
        $this->actingAs($this->siti)->put(route('profil.email'), ['email' => 'siti2@contoh.id', 'password_akun_email' => 'salah'])
            ->assertSessionHasErrors('password_akun_email');
        $this->put(route('profil.email'), ['email' => 'siti2@contoh.id', 'password_akun_email' => 'lama123'])
            ->assertRedirect(route('profil.index'));

        $this->assertSame('siti2@contoh.id', $this->siti->refresh()->email);
        Notification::assertSentOnDemand(PemberitahuanAkunStaf::class, fn ($n, $s, $tujuan) => $tujuan->routes['mail'] === 'siti@contoh.id');
    }
}
