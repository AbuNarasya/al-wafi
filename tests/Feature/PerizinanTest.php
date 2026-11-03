<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\KartuSantri;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\KataSandiWali;
use App\Support\Akses;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perizinan Insidental (4 Nov 2026): wali mengajukan di portal → persetujuan 1/2 tingkat (pengaturan) →
 * pos keluar/masuk (tap kartu / manual); staf atas nama wali; kabar ke wali di tiap langkah.
 */
class PerizinanTest extends TestCase
{
    use RefreshDatabase;

    private User $musyrif;

    private User $kesantrian;

    private User $pos;

    private Santri $ahmad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Carbon::setTestNow('2026-11-04 08:00:00');
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $buatStaf = function (string $u, array $hak) {
            $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
            foreach ($hak as $modul => $aksi) {
                HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'menu' => true] + array_fill_keys($aksi, true));
            }

            return $user;
        };
        $this->musyrif = $buatStaf('musyrif', ['perizinan-tingkat-1' => ['lihat', 'ubah']]);
        $this->kesantrian = $buatStaf('kesantrian', ['perizinan-tingkat-2' => ['lihat', 'ubah'], 'perizinan' => ['lihat', 'buat']]);
        $this->pos = $buatStaf('satpam', ['perizinan' => ['lihat', 'ubah']]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '081234567891', 'email_ibu' => 'i@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '081234567891', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'L-1', 'nis' => '1001', 'id_wali' => $w->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'status' => 'aktif']);
        KartuSantri::create(['uid' => 'KARTUA01', 'id_santri' => $this->ahmad->id, 'status' => 'aktif']);
        (new KataSandiWali)->simpan($w, 'ibu', 'sandiUji12345', 'sandiUji12345');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function masukWali(): void
    {
        $this->post(route('portal.masuk.sandi'), ['email' => 'i@contoh.id', 'sandi' => 'sandiUji12345']);
    }

    private function isian(array $timpa = []): array
    {
        return $timpa + ['id_santri' => $this->ahmad->id, 'kode_tipe' => 'keluarga', 'keperluan' => 'Pernikahan kakak',
            'rencana_keluar' => '2026-11-06T14:00', 'rencana_kembali' => '2026-11-08T17:00',
            'penjemput_nama' => 'Bapak Hasan', 'penjemput_hubungan' => 'ayah', 'penjemput_hp' => '081299990000'];
    }

    private function tugas(User $u): int
    {
        $this->actingAs($u);
        Akses::lupakan();
        TugasSaya::lupakan();

        return TugasSaya::untukUrl('/kesantrian/perizinan');
    }

    public function test_wali_mengajukan_aturan_tipe_dan_satu_tingkat(): void
    {
        $this->masukWali();
        $this->get(route('portal.menu'))->assertOk()->assertSee(route('portal.perizinan'), false);
        $this->get(route('portal.perizinan.ajukan'))->assertOk()->assertSee("Keluar Lingkungan Ma&#039;had", false)->assertSee('Keperluan Keluarga (Izin Pulang)');

        $kirim = fn (array $d) => $this->post(route('portal.perizinan.kirim'), $this->isian($d));
        $kirim(['keperluan' => ''])->assertSessionHas('error', 'Sebutkan keperluannya.');
        $kirim(['kode_tipe' => 'keluar_lingkungan', 'keperluan' => 'Beli buku'])->assertSessionHas('error', fn ($m) => str_contains($m, 'hari yang sama'));
        $kirim(['rencana_kembali' => '2026-11-06T10:00'])->assertSessionHas('error', fn ($m) => str_contains($m, 'sesudah'));
        DB::table('tipe_izin')->where('kode', 'keluarga')->update(['batas_jam' => 72]);
        $kirim([])->assertSessionHas('error', fn ($m) => str_contains($m, '72 jam'));
        DB::table('tipe_izin')->where('kode', 'keluarga')->update(['batas_jam' => 24]);
        $kirim([])->assertRedirect();
        $z = Perizinan::sole();
        $this->assertSame(['menunggu_2', 'Pernikahan kakak', 'ibu'], [$z->status, $z->keperluan, $z->diajukan_peran]);
        $kirim(['rencana_keluar' => '2026-11-07T08:00'])->assertSessionHas('error', fn ($m) => str_contains($m, 'rentang waktu'));
        // Sakit: keperluan tak wajib.
        $kirim(['kode_tipe' => 'sakit', 'keperluan' => '', 'rencana_keluar' => '2026-11-10T08:00', 'rencana_kembali' => '2026-11-12T08:00'])->assertRedirect();
        $this->assertSame(2, Perizinan::count());

        // Satu tingkat (bawaan: tingkat 2 saja) — musyrif tingkat 1 tak memutus, kesantrian iya.
        $this->assertSame(0, $this->tugas($this->musyrif));
        $this->assertSame(2, $this->tugas($this->kesantrian));
        $this->actingAs($this->musyrif)->post(route('perizinan.setujui', $z->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak berhak'));
        $this->assertSame('menunggu_2', $z->fresh()->status);
        $this->actingAs($this->kesantrian)->post(route('perizinan.setujui', $z->id), ['catatan' => 'Hati-hati'])->assertSessionHas('status');
        $this->assertSame('disetujui', $z->fresh()->status);
        $this->assertStringContainsString('disetujui', NotifikasiWali::where('jenis', 'perizinan')->latest('id')->value('judul'));
        $this->assertSame('pengajuan', NotifikasiWali::where('jenis', 'perizinan')->value('kategori'));

        // Wali melihat statusnya; masih bisa membatalkan sebelum keluar.
        $this->masukWali();
        $this->get(route('portal.perizinan'))->assertOk()->assertSee('Disetujui');
        $this->get(route('portal.perizinan.lihat', $z->id))->assertOk()->assertSee('Bapak Hasan')->assertSee('Batalkan pengajuan');
        $this->post(route('portal.perizinan.batal', $z->id))->assertSessionHas('status');
        $this->assertSame('dibatalkan', $z->fresh()->status);
    }

    public function test_dua_tingkat_tolak_pos_keluar_kembali_dan_terlambat(): void
    {
        DB::table('pengaturan_perizinan')->where('id', 1)->update(['tingkat1_aktif' => true, 'tingkat2_aktif' => true]);
        $this->masukWali();
        $this->post(route('portal.perizinan.kirim'), $this->isian())->assertRedirect();
        $z = Perizinan::sole();
        $this->assertSame('menunggu_1', $z->status);
        $this->assertSame(1, $this->tugas($this->musyrif));
        $this->assertSame(0, $this->tugas($this->kesantrian));
        $this->actingAs($this->kesantrian)->post(route('perizinan.setujui', $z->id))->assertSessionHas('error');
        $this->actingAs($this->musyrif)->post(route('perizinan.setujui', $z->id))->assertSessionHas('status');
        $this->assertSame('menunggu_2', $z->fresh()->status);
        $this->assertSame(0, NotifikasiWali::where('jenis', 'perizinan')->count(), 'wali dikabari saat keputusan AKHIR');
        $this->actingAs($this->kesantrian)->post(route('perizinan.tolak', $z->id), [])->assertSessionHasErrors('alasan');
        $this->actingAs($this->kesantrian)->post(route('perizinan.setujui', $z->id))->assertSessionHas('status');
        $this->assertSame('disetujui', $z->fresh()->status);

        // Pos: kartu tanpa izin → tak boleh keluar; dengan izin → keluar, lalu kembali.
        $this->actingAs($this->pos)->get(route('perizinan.pos', ['kartu' => 'KARTUA01']))->assertOk()->assertSee('Catat KELUAR');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'kembali', 'cara' => 'kartu'])->assertSessionHas('error');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'keluar', 'cara' => 'kartu'])->assertRedirect(route('perizinan.pos'));
        $z->refresh();
        $this->assertSame(['keluar', 'kartu'], [$z->status, $z->keluar_cara]);
        Carbon::setTestNow('2026-11-08 19:00:00');   // lewat jadwal kembali 17:00
        $this->assertTrue($z->fresh()->terlambat());
        $this->actingAs($this->kesantrian)->get(route('perizinan.index'))->assertSee('1 santri terlambat kembali');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'kembali', 'cara' => 'manual']);
        $z->refresh();
        $this->assertSame(['kembali', true], [$z->status, $z->terlambat()]);
        $this->assertSame(['Izin AHMAD disetujui', 'AHMAD sudah keluar pondok', 'AHMAD sudah kembali di pondok'],
            NotifikasiWali::where('jenis', 'perizinan')->where('peran', 'ibu')->orderBy('id')->pluck('judul')->all());
        $this->actingAs($this->pos)->get(route('perizinan.pos', ['kartu' => 'KARTUA01']))->assertSee('tidak boleh keluar');

        // Tolak di tingkat 1.
        Carbon::setTestNow('2026-11-09 08:00:00');
        $this->masukWali();
        $this->post(route('portal.perizinan.kirim'), $this->isian(['rencana_keluar' => '2026-11-12T08:00', 'rencana_kembali' => '2026-11-13T08:00']));
        $z2 = Perizinan::latest('id')->first();
        $this->actingAs($this->musyrif)->post(route('perizinan.tolak', $z2->id), ['alasan' => 'Ujian tengah semester'])->assertSessionHas('status');
        $this->assertSame('ditolak', $z2->fresh()->status);
        $this->assertStringContainsString('Ujian tengah semester', NotifikasiWali::where('jenis', 'perizinan')->latest('id')->value('pesan'));
    }

    public function test_staf_atas_nama_wali_tanpa_batas_waktu_dan_pengaturan(): void
    {
        DB::table('tipe_izin')->where('kode', 'sakit')->update(['batas_jam' => 48]);
        $this->actingAs($this->kesantrian)->get(route('perizinan.buat'))->assertOk();
        $this->actingAs($this->kesantrian)->post(route('perizinan.simpan'), $this->isian(['kode_tipe' => 'sakit', 'keperluan' => 'Demam tinggi',
            'rencana_keluar' => '2026-11-04T09:00', 'rencana_kembali' => '2026-11-07T09:00']))->assertSessionHas('status');
        $z = Perizinan::sole();
        $this->assertSame([$this->kesantrian->id_pengguna, 'menunggu_2'], [(int) $z->diajukan_oleh, $z->status]);
        $this->assertStringContainsString('dicatat pesantren', NotifikasiWali::where('jenis', 'perizinan')->value('judul'));
        $this->actingAs($this->kesantrian)->get(route('perizinan.show', $z->id))->assertOk()->assertSee('Staf atas nama wali');

        // Pengaturan: hak sendiri; minimal satu tingkat.
        $this->actingAs($this->kesantrian)->get(route('perizinan.pengaturan'))->assertForbidden();
        $admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->fresh();
        $isi = ['label_tingkat1' => 'Musyrif', 'label_tingkat2' => 'Kesantrian', 'tingkat1_aktif' => '0', 'tingkat2_aktif' => '0'];
        $this->actingAs($admin)->put(route('perizinan.pengaturan.simpan'), $isi)->assertSessionHas('error');
        $this->actingAs($admin)->put(route('perizinan.pengaturan.simpan'), ['tingkat1_aktif' => '1', 'tingkat2_aktif' => '1',
            'tipe' => ['sakit' => ['aktif' => '1', 'batas_jam' => '0'], 'keluarga' => ['batas_jam' => '24']]] + $isi)->assertSessionHas('status');
        $this->assertSame([true, false, 24], [(bool) DB::table('tipe_izin')->where('kode', 'sakit')->value('aktif'),
            (bool) DB::table('tipe_izin')->where('kode', 'keluarga')->value('aktif'), (int) DB::table('tipe_izin')->where('kode', 'keluarga')->value('batas_jam')]);
        $this->assertTrue((bool) DB::table('pengaturan_perizinan')->value('tingkat1_aktif'));
    }
}
