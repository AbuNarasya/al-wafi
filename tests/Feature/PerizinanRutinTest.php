<?php

namespace Tests\Feature;

use App\Models\AturanPerizinanRutin;
use App\Models\HakAksesModul;
use App\Models\JadwalPerizinanRutin;
use App\Models\Jenjang;
use App\Models\KartuSantri;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\PerizinanRutinService;
use App\Services\PortalWali\KataSandiWali;
use App\Support\Akses;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Perizinan Rutin (Tahap 2) & kebijakan tunggakan (Tahap 3), 4 Nov 2026: matriks → jadwal → wali mengajukan
 * per jadwal → persetujuan (massal) → pos; tunggakan menolak izin atau menahan santri di pos masuk.
 */
class PerizinanRutinTest extends TestCase
{
    use MembuatTarif, RefreshDatabase;

    private User $petugas;

    private User $pos;

    private Santri $ahmad;

    private Santri $fatimah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Carbon::setTestNow('2026-11-04 08:00:00');   // Rabu
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $buat = function (string $u, array $hak) {
            $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
            foreach ($hak as $modul => $aksi) {
                HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'menu' => true] + array_fill_keys($aksi, true));
            }

            return $user;
        };
        $this->petugas = $buat('kesantrian', ['perizinan-jadwal' => ['lihat', 'ubah'], 'perizinan-tingkat-2' => ['lihat', 'ubah'], 'perizinan' => ['lihat']]);
        $this->pos = $buat('satpam', ['perizinan' => ['lihat', 'ubah']]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'J003', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '081234567891', 'email_ibu' => 'i@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '081234567891', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'L-1', 'nis' => '1001', 'id_wali' => $w->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'status' => 'aktif']);
        $this->fatimah = Santri::create(['no_pendaftaran' => 'L-2', 'nis' => '1002', 'id_wali' => $w->id, 'nama' => 'Fatimah', 'jenis_kelamin' => 'P',
            'kode_jenjang' => 'J003', 'tingkat' => 1, 'status' => 'aktif']);
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

    private function matriks(): void
    {
        // Penjengukan: SMP Ikhwan Ahad ke-2, SMA Akhwat Ahad ke-3. Kepulangan: SMP Ikhwan Jumat ke-4 → Ahad.
        $this->actingAs($this->petugas)->put(route('perizinan_rutin.aturan', 'penjengukan'), ['sel' => [
            'J002' => ['L' => ['aktif' => '1', 'hari' => '0', 'pekan_ke' => '2', 'jam_mulai' => '07:00', 'lama_hari' => '0', 'jam_selesai' => '17:00']],
            'J003' => ['P' => ['aktif' => '1', 'hari' => '0', 'pekan_ke' => '3', 'jam_mulai' => '07:00', 'lama_hari' => '0', 'jam_selesai' => '17:00']],
        ]])->assertSessionHas('status');
        $this->actingAs($this->petugas)->put(route('perizinan_rutin.aturan', 'kepulangan'), ['sel' => [
            'J002' => ['L' => ['aktif' => '1', 'hari' => '5', 'pekan_ke' => '4', 'jam_mulai' => '15:00', 'lama_hari' => '2', 'jam_selesai' => '17:00']],
        ]])->assertSessionHas('status');
    }

    private function jadwal(string $tipe, string $tanggal): JadwalPerizinanRutin
    {
        return JadwalPerizinanRutin::where('kode_tipe', $tipe)->whereDate('mulai', $tanggal)->sole();
    }

    public function test_matriks_membangkitkan_jadwal_tanpa_dobel(): void
    {
        $this->matriks();
        $this->assertSame('Ahad ke-2, 07:00 → 17:00', AturanPerizinanRutin::where('kode_tipe', 'penjengukan')->where('kode_jenjang', 'J002')->sole()->label());
        $this->actingAs($this->petugas)->post(route('perizinan_rutin.bangkitkan'), ['dari' => '2026-11', 'bulan' => 2])->assertSessionHas('status');
        // Nov 2026: Ahad ke-2 = 8 Nov, Ahad ke-3 = 15 Nov, Jumat ke-4 = 27 Nov; Des: 13, 20, 25 Des.
        $this->assertSame(6, JadwalPerizinanRutin::count());
        $k = $this->jadwal('kepulangan', '2026-11-27');
        $this->assertSame(['2026-11-27 15:00', '2026-11-29 17:00'], [$k->mulai->format('Y-m-d H:i'), $k->selesai->format('Y-m-d H:i')]);
        $this->assertTrue($this->jadwal('penjengukan', '2026-11-08')->mencakup($this->ahmad));
        $this->assertFalse($this->jadwal('penjengukan', '2026-11-08')->mencakup($this->fatimah));

        // Ditekan lagi → tak dobel; sel bertanggal sama digabung jadi satu jadwal bercakupan dua.
        $this->actingAs($this->petugas)->post(route('perizinan_rutin.bangkitkan'), ['dari' => '2026-11', 'bulan' => 2]);
        $this->assertSame(6, JadwalPerizinanRutin::count());
        AturanPerizinanRutin::where('kode_jenjang', 'J003')->update(['pekan_ke' => 2]);
        JadwalPerizinanRutin::query()->delete();
        (new PerizinanRutinService)->bangkitkan('2026-11', 1, $this->petugas);
        $this->assertSame(2, $this->jadwal('penjengukan', '2026-11-08')->cakupan()->count());

        // Tugas Saya: bulan depan sudah berjadwal? Desember belum → muncul.
        $this->actingAs($this->petugas);
        Akses::lupakan();
        TugasSaya::lupakan();
        $this->assertSame(2, TugasSaya::untukUrl('/kesantrian/perizinan-rutin/jadwal'));
    }

    public function test_wali_mengajukan_per_jadwal_persetujuan_massal_dan_pos(): void
    {
        $this->matriks();
        (new PerizinanRutinService)->bangkitkan('2026-11', 1, $this->petugas);
        $j = $this->jadwal('penjengukan', '2026-11-08');
        DB::table('tipe_izin')->where('kode', 'penjengukan')->update(['batas_hari' => 2]);

        $this->masukWali();
        $this->get(route('portal.menu'))->assertOk()->assertSee('Perizinan Rutin')->assertSee('Perizinan Insidental');
        $this->get(route('portal.perizinan_rutin'))->assertOk()->assertSee('Penjengukan')->assertSee('Kepulangan')->assertSee('Ahmad');
        // Insidental tak lagi menawarkan jenis rutin.
        $this->get(route('portal.perizinan.ajukan'))->assertOk()->assertDontSee('Kepulangan');
        $this->post(route('portal.perizinan.kirim'), ['id_santri' => $this->ahmad->id, 'kode_tipe' => 'kepulangan', 'penjemput_jenis' => 'wali', 'penjemput_peran' => 'ibu',
            'rencana_keluar' => '2026-11-10T08:00', 'rencana_kembali' => '2026-11-11T08:00'])->assertSessionHas('error', fn ($m) => str_contains($m, 'Perizinan Rutin'));

        // Fatimah (SMA Akhwat) bukan cakupan jadwal 8 Nov → diabaikan.
        $this->get(route('portal.perizinan_rutin.ajukan', $j->id))->assertOk()->assertSee('Ahmad')->assertDontSee('Fatimah');
        $this->post(route('portal.perizinan_rutin.kirim', $j->id), ['anak' => [$this->ahmad->id, $this->fatimah->id],
            'penjemput_jenis' => 'wali', 'penjemput_peran' => 'ibu'])->assertRedirect(route('portal.perizinan_rutin'));
        $z = Perizinan::sole();
        $this->assertSame([$j->id, 'menunggu_2', '2026-11-08 07:00', '2026-11-08 17:00'],
            [$z->id_jadwal, $z->status, $z->rencana_keluar->format('Y-m-d H:i'), $z->rencana_kembali->format('Y-m-d H:i')]);
        // Tak bisa dua kali.
        $this->get(route('portal.perizinan_rutin.ajukan', $j->id))->assertRedirect(route('portal.perizinan_rutin'));

        // Tugas Saya terpisah: rutin vs insidental.
        $this->actingAs($this->petugas);
        Akses::lupakan();
        TugasSaya::lupakan();
        $this->assertSame([1, 0], [TugasSaya::untukUrl('/kesantrian/perizinan-rutin'), TugasSaya::untukUrl('/kesantrian/perizinan')]);

        $this->actingAs($this->petugas)->get(route('perizinan_rutin.show', $j->id))->assertOk()->assertSee('AHMAD')->assertSee('Setujui semua (1)');
        $this->actingAs($this->petugas)->post(route('perizinan_rutin.setujui_semua', $j->id))->assertSessionHas('status', '1 pengajuan disetujui.');
        $this->assertSame('disetujui', $z->fresh()->status);

        // Batas H-2: 6 Nov lewat → ditutup.
        Carbon::setTestNow('2026-11-07 08:00:00');
        $this->masukWali();
        $this->get(route('portal.perizinan_rutin'))->assertSee('Pengajuan sudah ditutup');

        // Pos hari H.
        Carbon::setTestNow('2026-11-08 07:30:00');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'keluar', 'cara' => 'kartu'])->assertRedirect(route('perizinan.pos'));
        $this->assertSame('keluar', $z->fresh()->status);
    }

    public function test_tiadakan_dan_geser_jadwal(): void
    {
        $this->matriks();
        (new PerizinanRutinService)->bangkitkan('2026-11', 1, $this->petugas);
        $j = $this->jadwal('kepulangan', '2026-11-27');
        $this->masukWali();
        $this->post(route('portal.perizinan_rutin.kirim', $j->id), ['anak' => [$this->ahmad->id], 'penjemput_jenis' => 'wali', 'penjemput_peran' => 'ibu'])->assertRedirect();
        $z = Perizinan::sole();

        // Geser → izin ikut bergeser.
        $this->actingAs($this->petugas)->put(route('perizinan_rutin.update', $j->id), ['mulai' => '2026-11-20T15:00', 'selesai' => '2026-11-22T17:00',
            'cakupan' => ['J002|L']])->assertRedirect(route('perizinan_rutin.jadwal'));
        $this->assertSame('2026-11-20 15:00', $z->fresh()->rencana_keluar->format('Y-m-d H:i'));

        // Tiadakan → alasan wajib, izin dibatalkan, wali dikabari.
        $this->actingAs($this->petugas)->post(route('perizinan_rutin.tiadakan', $j->id), [])->assertSessionHasErrors('alasan');
        $this->actingAs($this->petugas)->post(route('perizinan_rutin.tiadakan', $j->id), ['alasan' => 'Ujian akhir semester'])->assertSessionHas('status');
        $this->assertSame(['ditiadakan', 'dibatalkan'], [$j->fresh()->status, $z->fresh()->status]);
        $this->assertStringContainsString('Ujian akhir semester', NotifikasiWali::where('ref_jenis', 'JadwalPerizinanRutin')->value('pesan'));
        $this->masukWali();
        $this->get(route('portal.perizinan_rutin'))->assertSee('Ditiadakan')->assertSee('Ujian akhir semester');

        // Jadwal khusus + hapus (belum ada pengajuan).
        $this->actingAs($this->petugas)->post(route('perizinan_rutin.tambah'), ['kode_tipe' => 'kepulangan', 'mulai' => '2026-12-18T08:00',
            'selesai' => '2027-01-03T17:00', 'cakupan' => ['J002|L', 'J003|P'], 'catatan' => 'Libur semester'])->assertSessionHas('status');
        $k = JadwalPerizinanRutin::where('khusus', true)->sole();
        $this->assertSame(2, $k->cakupan()->count());
        $this->actingAs($this->petugas)->delete(route('perizinan_rutin.destroy', $k->id))->assertSessionHas('status');
        $this->assertNull(JadwalPerizinanRutin::find($k->id));
    }

    public function test_pengingat_sebelum_batas_sekali_saja(): void
    {
        $this->matriks();
        (new PerizinanRutinService)->bangkitkan('2026-11', 1, $this->petugas);
        DB::table('tipe_izin')->where('kode', 'penjengukan')->update(['batas_hari' => 2]);   // 8 Nov → batas 6 Nov
        Carbon::setTestNow('2026-11-05 07:00:00');
        $this->artisan('perizinan:pengingat-rutin')->assertSuccessful();
        $this->assertSame(1, NotifikasiWali::where('ref_id', $this->jadwal('penjengukan', '2026-11-08')->id.':pengingat')->count());
        $this->artisan('perizinan:pengingat-rutin');
        $this->assertSame(1, NotifikasiWali::where('ref_id', 'like', '%:pengingat')->where('judul', 'like', '%Penjengukan%')->count());
    }

    // ---- Tahap 3: tunggakan ----

    /** $jumlah tagihan lewat jatuh tempo & belum lunas untuk Ahmad. */
    private function tunggakan(int $jumlah, string $perilaku = 'spp'): void
    {
        static $urut = 0;
        if (! \App\Models\CoaDetail::find('4.ZZIR.1')) {
            \App\Models\CoaGroup::create(['kode_grup' => 'ZZIR', 'nama_grup' => 'IR']);
            \App\Models\CoaDetail::create(['kode_coa' => '4.ZZIR.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => 'ZZIR', 'jenis_saldo' => 'kredit']);
            \App\Models\BusinessUnit::create(['kode_unit' => 'ZZIRU', 'nama_unit' => 'Unit']);
        }
        $kode = 'ZZ'.strtoupper($perilaku);
        if (! \App\Models\JenisBiaya::find($kode)) {
            $this->buatBiaya(['kode' => $kode, 'nama' => 'Biaya '.$perilaku, 'tipe' => $perilaku, 'kode_jenjang' => 'J002',
                'kode_coa_pendapatan' => '4.ZZIR.1', 'kode_unit' => 'ZZIRU']);
        }
        for ($i = 1; $i <= $jumlah; $i++) {
            $urut++;
            \App\Models\TagihanSantri::create(['id_santri' => $this->ahmad->id, 'kode_jenis' => $kode, 'perilaku' => $perilaku, 'kode_jenjang' => 'J002',
                'periode' => sprintf('2026-%02d', $urut), 'nominal' => '100000', 'sisa' => '100000', 'status' => 'belum_bayar',
                'jatuh_tempo' => sprintf('2026-%02d-10', $urut)]);
        }
    }

    public function test_tunggakan_tolak_izin_dan_tahan_kembali_dengan_izin_petugas(): void
    {
        $this->tunggakan(2, 'spp');
        DB::table('tipe_izin')->where('kode', 'sakit')->update(['tunggakan_kebijakan' => 'tolak', 'tunggakan_periode' => 3, 'tunggakan_lingkup' => 'spp']);
        $isi = ['id_santri' => $this->ahmad->id, 'kode_tipe' => 'sakit', 'penjemput_jenis' => 'wali', 'penjemput_peran' => 'ibu',
            'rencana_keluar' => '2026-11-05T08:00', 'rencana_kembali' => '2026-11-06T08:00'];
        $this->masukWali();
        $this->post(route('portal.perizinan.kirim'), $isi)->assertRedirect();   // 2 < 3 → boleh
        Perizinan::query()->delete();
        $this->tunggakan(1, 'lain');
        $this->post(route('portal.perizinan.kirim'), $isi)->assertRedirect();   // lingkup SPP: tagihan lain tak dihitung
        Perizinan::query()->delete();
        DB::table('tipe_izin')->where('kode', 'sakit')->update(['tunggakan_lingkup' => 'semua']);
        $this->post(route('portal.perizinan.kirim'), $isi)->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak dapat diberikan') && str_contains($m, '3 tagihan'));

        // Tahan kembali: boleh keluar, tertahan di pos masuk.
        DB::table('tipe_izin')->where('kode', 'sakit')->update(['tunggakan_kebijakan' => 'tahan_kembali', 'persetujuan' => 'otomatis']);
        $this->post(route('portal.perizinan.kirim'), $isi)->assertRedirect();
        $z = Perizinan::sole();
        $this->assertSame('disetujui', $z->status);
        $this->assertTrue(NotifikasiWali::where('judul', 'like', '%syarat kembali%')->exists(), 'wali diberi tahu sejak disetujui');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'keluar', 'cara' => 'kartu'])->assertRedirect(route('perizinan.pos'));
        $this->actingAs($this->pos)->get(route('perizinan.pos', ['kartu' => 'KARTUA01']))->assertSee('TERTAHAN')->assertDontSee('Izinkan masuk');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'kembali', 'cara' => 'kartu'])->assertSessionHas('error', fn ($m) => str_contains($m, 'TERTAHAN'));
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'kembali', 'cara' => 'kartu', 'izin_tunggakan' => 'Sudah janji bayar'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak berhak'));
        $this->assertSame('keluar', $z->fresh()->status);

        // Pemegang hak perizinan-tunggakan mengizinkan, beralasan → tercatat di jejak.
        HakAksesModul::create(['id_pengguna' => $this->pos->id_pengguna, 'kode_modul' => 'perizinan-tunggakan', 'menu' => true, 'lihat' => true, 'ubah' => true]);
        Akses::lupakan();
        $this->actingAs($this->pos)->get(route('perizinan.pos', ['kartu' => 'KARTUA01']))->assertSee('Izinkan masuk');
        $this->actingAs($this->pos)->post(route('perizinan.catat_pos', $z->id), ['aksi' => 'kembali', 'cara' => 'kartu', 'izin_tunggakan' => 'Sudah janji bayar'])
            ->assertRedirect(route('perizinan.pos'));
        $this->assertSame('kembali', $z->fresh()->status);
        $this->assertTrue(DB::table('activity_log')->where('aksi', 'izin_perizinan_tunggakan')->exists());
    }

    public function test_tunggakan_tolak_juga_berlaku_di_portal_rutin(): void
    {
        $this->matriks();
        (new PerizinanRutinService)->bangkitkan('2026-11', 1, $this->petugas);
        $this->tunggakan(3, 'spp');
        DB::table('tipe_izin')->where('kode', 'kepulangan')->update(['tunggakan_kebijakan' => 'tolak', 'tunggakan_periode' => 3]);
        $j = $this->jadwal('kepulangan', '2026-11-27');
        $this->masukWali();
        $this->get(route('portal.perizinan_rutin'))->assertSee('Tidak dapat diajukan: tunggakan');
        $this->post(route('portal.perizinan_rutin.kirim', $j->id), ['anak' => [$this->ahmad->id], 'penjemput_jenis' => 'wali', 'penjemput_peran' => 'ibu'])
            ->assertSessionHas('error');
        $this->assertSame(0, Perizinan::count());
    }
}
