<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Notification as NotifikasiStaf;
use App\Models\NotifikasiWali;
use App\Models\PerangkatWali;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Notifications\EmailWaliDiganti;
use App\Notifications\KodeMasukWali;
use App\Services\PortalWali\UbahDataWali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Menu "Data Wali & Santri" di Portal Wali (keputusan user 14 Okt 2026): semua
 * perubahan langsung tersimpan, staf pemegang hak ubah santri diberi kabar, email
 * baru dibuktikan dengan kode ke alamat baru.
 */
class PortalDataTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    private Santri $ahmad;

    private User $kesantrian;

    private User $keuangan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->kesantrian = User::create(['username' => 'zzpd_ks', 'nama' => 'Petugas Kesantrian', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $this->kesantrian->id_pengguna, 'kode_modul' => 'santri',
            'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        $this->keuangan = User::create(['username' => 'zzpd_keu', 'nama' => 'Petugas Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $this->keuangan->id_pengguna, 'kode_modul' => 'santri',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811111111',
            'email_ibu' => 'aminah@contoh.id', 'nama_ayah' => 'Bapak Hasan', 'email_ayah' => 'hasan@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811111111', 'alamat' => 'Jl. Lama 1', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'ZZPD-1', 'nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'L',
            'tempat_lahir' => 'Bandung', 'nisn' => '0012345678', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
    }

    private function sebagai(string $peran = 'ibu'): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => $peran]);
    }

    /** @return array<string,mixed> isian lengkap form santri, ditimpa sebagian */
    private function isianSantri(array $ubah = []): array
    {
        return array_merge([
            'nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'L', 'tempat_lahir' => 'Bandung', 'tanggal_lahir' => '',
            'nisn' => '0012345678', 'asal_sekolah' => '', 'alamat_sekolah_asal' => '', 'kepala_sekolah_asal' => '',
            'cp_kepala_sekolah_asal' => '', 'wali_kelas_asal' => '', 'cp_wali_kelas_asal' => '',
        ], $ubah);
    }

    // ---- Lihat ----

    public function test_menu_dan_halaman_menampilkan_data_yang_tercatat(): void
    {
        $this->sebagai()->get(route('portal.beranda'))->assertOk()->assertSee('Data Wali &amp; Santri', false);

        $this->sebagai()->get(route('portal.data'))->assertOk()
            ->assertSee('AHMAD FAUZI')->assertSee('0012345678')->assertSee('Bandung')
            ->assertSee(route('portal.data.santri', $this->ahmad->id), false);

        $this->sebagai()->get(route('portal.data', ['tab' => 'wali']))->assertOk()
            ->assertSee('Ibu Aminah')->assertSee('Bapak Hasan')->assertSee('hasan@contoh.id')->assertSee('Jl. Lama 1');
    }

    public function test_santri_keluarga_lain_tak_terbuka(): void
    {
        $lain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Orang Lain', 'telepon_ayah' => '0822222222',
            'nama' => 'Orang Lain', 'telepon' => '0822222222', 'status' => 'aktif']);
        $asing = Santri::create(['no_pendaftaran' => 'ZZPD-2', 'nama' => 'Anak Lain', 'jenis_kelamin' => 'P',
            'kode_jenjang' => 'SMP', 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $lain->id]);

        $this->sebagai()->get(route('portal.data.santri', $asing->id))->assertNotFound();
        $this->sebagai()->put(route('portal.data.santri', $asing->id), $this->isianSantri(['nama' => 'Dibajak']))->assertNotFound();
        $this->assertSame('ANAK LAIN', $asing->fresh()->nama);
        $this->sebagai()->get(route('portal.data'))->assertDontSee('ANAK LAIN');
    }

    // ---- Ubah santri ----

    public function test_ubah_data_santri_langsung_tersimpan_tercatat_dan_dikabarkan(): void
    {
        $this->sebagai()->put(route('portal.data.santri', $this->ahmad->id), $this->isianSantri([
            'nama' => 'ahmad fauzan', 'tanggal_lahir' => '2013-05-02', 'asal_sekolah' => 'SDN 1 Bandung',
        ]))->assertRedirect(route('portal.data', ['tab' => 'santri']))->assertSessionHas('status');

        $s = $this->ahmad->fresh();
        $this->assertSame('AHMAD FAUZI', $s->nama, 'nama santri dikunci — hanya admin yang boleh mengubah');
        $this->assertSame('2013-05-02', $s->tanggal_lahir->format('Y-m-d'));
        $this->assertSame('SDN 1 Bandung', $s->asal_sekolah);

        $jejak = ActivityLog::where('modul', UbahDataWali::MODUL_JEJAK)->sole();
        $this->assertNull($jejak->id_pengguna);
        // assertEquals: kolom jsonb tak menjaga urutan kunci.
        $this->assertArrayNotHasKey('Nama lengkap', $jejak->perubahan);
        $this->assertEquals(['lama' => null, 'baru' => '02/05/2013'], $jejak->perubahan['Tanggal lahir']);
        $this->assertStringContainsString('Ibu Aminah (Ibu)', $jejak->detail);
        $this->assertCount(2, $jejak->perubahan, 'kolom yang tak berubah tak ikut dicatat');

        // Staf: pemegang hak UBAH santri saja.
        $notif = NotifikasiStaf::where('jenis', UbahDataWali::JENIS_NOTIF)->get();
        $this->assertSame([$this->kesantrian->id_pengguna], $notif->pluck('id_pengguna')->all());
        $this->assertStringContainsString('Sekolah asal: — → SDN 1 Bandung', $notif->first()->pesan);
        $this->assertSame('/santri/'.$this->ahmad->id, (new \App\Services\Modules\NotificationService)->tautan($notif->first()));

        // Keluarga: ayah (punya email) tahu; ibu yang mengubah tidak dikabari dirinya sendiri.
        $this->assertSame(['ayah'], NotifikasiWali::where('id_wali', $this->wali->id)->pluck('peran')->all());
    }

    public function test_kiriman_tanpa_perubahan_tak_mencatat_apa_pun(): void
    {
        $this->sebagai()->put(route('portal.data.santri', $this->ahmad->id), $this->isianSantri(['nama' => 'ahmad fauzi']))
            ->assertSessionHas('status', 'Tidak ada data yang berubah.');

        $this->assertSame(0, ActivityLog::where('modul', UbahDataWali::MODUL_JEJAK)->count());
        $this->assertSame(0, NotifikasiStaf::where('jenis', UbahDataWali::JENIS_NOTIF)->count());
    }

    public function test_santri_berstatus_akhir_hanya_bisa_dilihat(): void
    {
        $this->ahmad->update(['status' => 'alumni']);

        $this->sebagai()->get(route('portal.data'))->assertOk()
            ->assertDontSee(route('portal.data.santri', $this->ahmad->id), false);
        $this->sebagai()->get(route('portal.data.santri', $this->ahmad->id))->assertRedirect(route('portal.data'));
        $this->sebagai()->put(route('portal.data.santri', $this->ahmad->id), $this->isianSantri(['nama' => 'Ganti']))
            ->assertSessionHas('error');
        $this->assertSame('AHMAD FAUZI', $this->ahmad->fresh()->nama);
    }

    public function test_isian_santri_divalidasi(): void
    {
        $this->sebagai()->put(route('portal.data.santri', $this->ahmad->id), $this->isianSantri([
            'nisn' => '12AB', 'tanggal_lahir' => now()->addDay()->format('Y-m-d'),
        ]))->assertSessionHasErrors(['nisn', 'tanggal_lahir']);
    }

    // ---- Ubah keluarga ----

    /** @return array<string,mixed> */
    private function isianKeluarga(array $ubah = []): array
    {
        $isi = ['nik' => '', 'alamat' => 'Jl. Lama 1'];
        foreach (['ayah', 'ibu', 'wali'] as $p) {
            $isi += ["nama_{$p}" => (string) $this->wali->{"nama_{$p}"}, "telepon_{$p}" => (string) $this->wali->{"telepon_{$p}"},
                "pekerjaan_{$p}" => '', "pekerjaan_lain_{$p}" => '', "pendapatan_{$p}" => ''];
        }

        return array_merge($isi, $ubah);
    }

    public function test_ubah_data_keluarga_menyalin_kontak_utama(): void
    {
        $this->sebagai('ayah')->put(route('portal.data.keluarga'), $this->isianKeluarga([
            'alamat' => 'Jl. Baru 2', 'telepon_ibu' => '+62 812-3333-4444', 'nama_ibu' => 'Aminah Zahra',
            'pekerjaan_ayah' => 'Lainnya', 'pekerjaan_lain_ayah' => 'Penulis lepas', 'pendapatan_ayah' => 'juta_5_10',
        ]))->assertRedirect(route('portal.data', ['tab' => 'wali']))->assertSessionHas('status');

        $w = $this->wali->fresh();
        $this->assertSame('Jl. Baru 2', $w->alamat);
        $this->assertSame('081233334444', $w->telepon_ibu, 'nomor dinormalisasi');
        $this->assertSame('081233334444', $w->telepon, 'salinan kontak utama ikut');
        $this->assertSame('Aminah Zahra', $w->nama);
        $this->assertSame('Penulis lepas', $w->pekerjaan_ayah);

        $jejak = ActivityLog::where('modul', UbahDataWali::MODUL_JEJAK)->sole();
        $this->assertEquals(['lama' => null, 'baru' => 'Rp 5–10 juta'], $jejak->perubahan['Pendapatan Ayah']);
        $this->assertStringContainsString('Bapak Hasan (Ayah)', $jejak->detail);
        $this->assertSame('/datawali/'.$this->wali->id.'/edit', (new \App\Services\Modules\NotificationService)
            ->tautan(NotifikasiStaf::where('jenis', UbahDataWali::JENIS_NOTIF)->firstOrFail()));
    }

    public function test_nomor_hp_keluarga_lain_ditolak_tanpa_menyebut_pemiliknya(): void
    {
        Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Rahasia Sekali', 'telepon_ayah' => '0822222222',
            'nama' => 'Rahasia Sekali', 'telepon' => '0822222222', 'status' => 'aktif']);

        $res = $this->sebagai()->put(route('portal.data.keluarga'), $this->isianKeluarga(['telepon_ayah' => '+62 822-222-222']));
        $res->assertSessionHas('error');
        $this->assertStringNotContainsString('Rahasia', session('error'));
        $this->assertNull($this->wali->fresh()->telepon_ayah);
    }

    public function test_kontak_utama_wajib_bernomor_hp(): void
    {
        $this->sebagai()->put(route('portal.data.keluarga'), $this->isianKeluarga(['telepon_ibu' => '']))
            ->assertSessionHasErrors('telepon_ibu');
        $this->assertSame('0811111111', $this->wali->fresh()->telepon_ibu);
    }

    // ---- Ganti email ----

    private function kodeTerkirimKe(string $email): string
    {
        $kode = null;
        Notification::assertSentOnDemand(KodeMasukWali::class, function (KodeMasukWali $n, array $saluran, AnonymousNotifiable $tujuan) use ($email, &$kode) {
            if ($tujuan->routes['mail'] === $email) {
                $kode = $n->kode;

                return true;
            }

            return false;
        });

        return (string) $kode;
    }

    public function test_ganti_email_dibuktikan_kode_ke_alamat_baru(): void
    {
        PerangkatWali::create(['id_wali' => $this->wali->id, 'peran' => 'ayah', 'token_hash' => str_repeat('a', 64),
            'terakhir_dipakai' => now(), 'kedaluwarsa_pada' => now()->addDays(30)]);

        $this->sebagai()->post(route('portal.data.email', 'ibu'), ['email' => ' Aminah.Baru@Contoh.ID '])
            ->assertRedirect(route('portal.data.email.kode'));
        $this->assertSame('aminah@contoh.id', $this->wali->fresh()->email_ibu, 'belum berubah sebelum kode benar');

        $kode = $this->kodeTerkirimKe('aminah.baru@contoh.id');
        $sesi = ['portal.peran' => 'ibu', 'portal.email_baru' => ['ibu', 'aminah.baru@contoh.id']];

        $this->actingAs($this->wali, 'wali')->withSession($sesi)->post(route('portal.data.email.kode'), ['kode' => $kode === '000000' ? '111111' : '000000'])
            ->assertSessionHas('error');
        $this->actingAs($this->wali, 'wali')->withSession($sesi)->post(route('portal.data.email.kode'), ['kode' => $kode])
            ->assertRedirect(route('portal.data', ['tab' => 'wali']))->assertSessionHas('status');

        $this->assertSame('aminah.baru@contoh.id', $this->wali->fresh()->email_ibu);
        Notification::assertSentOnDemand(EmailWaliDiganti::class, fn ($n, $s, AnonymousNotifiable $t) => $t->routes['mail'] === 'aminah@contoh.id');
        $this->assertSame(0, PerangkatWali::whereNull('dicabut_pada')->count(), 'perangkat yang diingat gugur');
        $this->assertSame(1, NotifikasiStaf::where('jenis', UbahDataWali::JENIS_NOTIF)->count());
    }

    public function test_email_milik_keluarga_lain_ditolak_sebelum_kode_dikirim(): void
    {
        Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lain', 'telepon_ayah' => '0822222222',
            'email_ayah' => 'dipakai@contoh.id', 'nama' => 'Lain', 'telepon' => '0822222222', 'status' => 'aktif']);

        $this->sebagai()->post(route('portal.data.email', 'ibu'), ['email' => 'dipakai@contoh.id'])->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    public function test_email_peran_tanpa_nama_tak_bisa_diisi(): void
    {
        $this->sebagai()->post(route('portal.data.email', 'wali'), ['email' => 'wali@contoh.id'])->assertSessionHas('error');
        Notification::assertNothingSent();
    }
}
