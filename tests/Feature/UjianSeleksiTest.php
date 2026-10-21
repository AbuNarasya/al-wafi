<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\JadwalUjian;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\KehadiranUjian;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ppsb\UjianSeleksi;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kartu peserta ujian, jadwal bertingkat, dan absensi lewat QR bertanda tangan (21 Okt 2026). */
class UjianSeleksiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        \App\Models\CompanySettings::create(['id' => 1, 'nama_perusahaan' => 'Pesantren Uji', 'periode_awal_pembukuan' => '2026-07-01']);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'J003', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
    }

    private function calon(string $nama, string $jk, string $status = 'terbayar', string $jenjang = 'J002', ?string $gelombang = '1'): Santri
    {
        static $n = 0;

        return Santri::create(['no_pendaftaran' => 'PSB-2610-'.str_pad((string) (++$n), 4, '0', STR_PAD_LEFT), 'id_wali' => $this->wali->id,
            'nama' => $nama, 'jenis_kelamin' => $jk, 'kode_jenjang' => $jenjang, 'tingkat' => 1, 'tahun_ajaran' => '2027/2028',
            'jalur' => '001', 'status' => $status, 'gelombang' => $gelombang]);
    }

    public function test_jadwal_paling_khusus_yang_berlaku(): void
    {
        $aisyah = $this->calon('Aisyah', 'P');
        $this->assertNull(UjianSeleksi::jadwal($aisyah));

        JadwalUjian::create(['tahun_ajaran' => '2027/2028', 'tanggal' => '2026-11-01']);
        JadwalUjian::create(['tahun_ajaran' => '2027/2028', 'kode_jenjang' => 'J002', 'tanggal' => '2026-11-02']);
        $this->assertSame('2026-11-02', UjianSeleksi::jadwal($aisyah)['tanggal']->toDateString());
        JadwalUjian::create(['tahun_ajaran' => '2027/2028', 'kode_gelombang' => '1', 'tanggal' => '2026-11-03']);
        $this->assertSame('2026-11-03', UjianSeleksi::jadwal($aisyah)['tanggal']->toDateString());
        JadwalUjian::create(['tahun_ajaran' => '2027/2028', 'kode_jenjang' => 'J002', 'kode_gelombang' => '1', 'tanggal' => '2026-11-04', 'jam' => '07.30']);
        $this->assertSame('2026-11-04', UjianSeleksi::jadwal($aisyah)['tanggal']->toDateString());
        $this->assertSame('2026-11-01', UjianSeleksi::jadwal($this->calon('Budi', 'L', 'terbayar', 'J003', '2'))['tanggal']->toDateString());

        $this->actingAs($this->admin)->put(route('ujian.tanggal_peserta', $aisyah->id), ['tanggal_ujian' => '2026-11-09'])->assertSessionHas('status');
        $j = UjianSeleksi::jadwal($aisyah->refresh());
        $this->assertSame(['2026-11-09', 'peserta', '07.30'], [$j['tanggal']->toDateString(), $j['asal'], $j['jam']]);
    }

    public function test_kartu_hanya_sesudah_registrasi_lunas_untuk_staf_dan_wali(): void
    {
        $calon = $this->calon('Belum Bayar', 'P', 'calon');
        $aisyah = $this->calon('Aisyah', 'P');
        JadwalUjian::create(['tahun_ajaran' => '2027/2028', 'tanggal' => '2026-10-22']);
        $wali = fn () => $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);

        $this->actingAs($this->admin)->get(route('ujian.kartu', $calon->id))->assertForbidden();
        $this->actingAs($this->admin)->get(route('ujian.kartu', $aisyah->id))->assertOk()
            ->assertSee('KARTU PESERTA')->assertSee('REGULER')->assertSee($aisyah->no_pendaftaran)->assertSee('AISYAH')
            ->assertSee('KAMIS, 22 OKTOBER 2026')->assertSee('SMP PUTRI')->assertSee('Santriwati', false)->assertSee('Untuk 2 Orang')
            ->assertSee('Tempel pas foto')->assertSee('data-qr="'.e(UjianSeleksi::urlHadir($aisyah)).'"', false);
        $this->actingAs($this->admin)->get(route('santri.show', $aisyah->id))->assertSee('Kartu peserta');

        $wali()->get(route('portal.anak', $aisyah->id))->assertSee('Kartu Peserta Ujian');
        $wali()->get(route('portal.kartu_ujian', $aisyah->id))->assertOk()->assertSee('AISYAH');
        $wali()->get(route('portal.kartu_ujian', $calon->id))->assertForbidden();
    }

    public function test_pindai_qr_mencatat_hadir_sekali_dan_butuh_tanda_tangan_serta_hak(): void
    {
        $aisyah = $this->calon('Aisyah', 'P');
        $url = UjianSeleksi::urlHadir($aisyah);

        $this->actingAs($this->admin)->get(route('ujian.hadir', $aisyah->id))->assertForbidden(); // tanpa tanda tangan
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('HADIR');
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Sudah tercatat hadir');
        $this->assertSame(1, KehadiranUjian::count());

        $panitia = User::create(['username' => 'pnt', 'nama' => 'Panitia', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        $this->actingAs($panitia)->get($url)->assertForbidden();
        HakAksesModul::create(['id_pengguna' => $panitia->id_pengguna, 'kode_modul' => 'absensi-ujian', 'lihat' => true, 'buat' => true, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        $this->actingAs($panitia)->get($url)->assertOk();

        $this->calon('Budi', 'L');
        $this->actingAs($this->admin)->get(route('ujian.daftar_hadir', ['ta' => '2027/2028']))->assertOk()
            ->assertSee('1</b> hadir dari <b>2</b> peserta', false)->assertSee('belum hadir');
    }

    public function test_layar_jadwal_dan_pengaturan_kartu(): void
    {
        $this->actingAs($this->admin)->post(route('ujian.jadwal.simpan'), ['tahun_ajaran' => '2027/2028', 'kode_jenjang' => 'J002', 'tanggal' => '2026-11-04', 'jam' => '07.30', 'tempat' => 'Aula'])
            ->assertSessionHas('status');
        $this->actingAs($this->admin)->post(route('ujian.jadwal.simpan'), ['tahun_ajaran' => '2027/2028', 'kode_jenjang' => 'J002', 'tanggal' => '2026-11-05'])
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->put(route('ujian.pengaturan'), ['kupon_makan_orang' => 3, 'perhatian_kartu_ujian' => "Bawa:\n# Pensil"])->assertSessionHas('status');
        $this->actingAs($this->admin)->get(route('ujian.jadwal', ['ta' => '2027/2028']))->assertOk()->assertSee('Aula')->assertSee('Rabu, 4 November 2026');

        $this->actingAs($this->admin)->get(route('ujian.kartu', $this->calon('Aisyah', 'P')->id))->assertSee('Untuk 3 Orang')
            ->assertSee('<ol><li>Pensil</li></ol>', false);
    }
}
