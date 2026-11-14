<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\KalenderLibur;
use App\Models\KunjunganKlinik;
use App\Models\Level;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kalender Libur + keterangan hari tanpa absen di menu Absensi portal (keputusan user 14 Nov 2026):
 * libur kalender (per jenjang & cakupan) / Sabtu–Minggu untuk absen sekolah → "Hari Libur"; izin ERP → "Izin: …";
 * klinik ERP → "Sakit — …"; selain itu "Tidak ada data absen & keterangan".
 */
class KalenderLiburAbsensiTest extends TestCase
{
    use RefreshDatabase;

    private Wali $wali;

    private Santri $santri;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 03:00:00'); // Sabtu 14 Nov 10.00 WIB
        Cache::flush();
        config(['services.ise.kunci_baca' => 'rd_uji', 'services.ise.histori' => 'semua', 'services.ise.url' => 'https://ise.contoh/api/all']);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SD', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->santri = Santri::create(['no_pendaftaran' => 'A-1', 'nis' => '2425010541', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'J002',
            'tingkat' => 1, 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    /** @return array<string,string> tanggal => teks keterangan hari tanpa catatan */
    private function keterangan(string $html): array
    {
        preg_match_all('#<h2[^>]*>([^<]+)</h2>\s*<div class="divide-y divide-slate-100">\s*<div class="px-4 py-2.5 text-sm" data-keterangan-hari="[a-z]+">\s*<span[^>]*>([^<]+)</span>#', $html, $m);

        return array_combine(array_map('trim', $m[1]), array_map(fn ($s) => html_entity_decode(trim($s)), $m[2]));
    }

    public function test_hari_tanpa_absen_diberi_keterangan(): void
    {
        Http::fake(['*/histori/harian*' => Http::response(['success' => true, 'data' => ['current_page' => 1, 'last_page' => 1, 'total' => 2, 'data' => [
            ['date' => '2026-11-12', 'morning_status' => 'present', 'evening_status' => 'present'],
            ['date' => '2026-11-13', 'morning_status' => 'present', 'evening_status' => 'absent'],
        ]]]), '*' => Http::response(['success' => true, 'data' => ['data' => []]])]);

        KalenderLibur::create(['nama' => 'Libur Uji SMP', 'mulai' => '2026-11-03', 'selesai' => '2026-11-03', 'kode_jenjang' => 'J002', 'cakupan' => 'sekolah']);
        KalenderLibur::create(['nama' => 'Libur Khusus SD', 'mulai' => '2026-11-04', 'selesai' => '2026-11-04', 'kode_jenjang' => 'J001', 'cakupan' => 'semua']);
        KalenderLibur::create(['nama' => 'Kepulangan Santri', 'mulai' => '2026-11-02', 'selesai' => '2026-11-02', 'kode_jenjang' => null, 'cakupan' => 'asrama']);
        Perizinan::create(['nomor' => 'IZN-1', 'id_santri' => $this->santri->id, 'kode_tipe' => 'sakit', 'status' => 'kembali', 'id_wali' => $this->wali->id,
            'rencana_keluar' => '2026-11-05 01:00:00', 'rencana_kembali' => '2026-11-06 10:00:00']);
        KunjunganKlinik::create(['nomor' => 'KLN-1', 'id_santri' => $this->santri->id, 'datang_pada' => '2026-11-09 01:00:00', 'keluhan' => 'Demam',
            'tindak_lanjut' => 'dirawat', 'status' => 'selesai', 'selesai_pada' => '2026-11-09 09:00:00']);

        $html = $this->sebagaiIbu()->get(route('portal.absensi', ['tab' => 'harian']))->assertOk()->getContent();
        $k = $this->keterangan($html);

        $this->assertSame('Tidak ada data absen & keterangan',$k['Senin, 2 Nov 2026'], 'libur cakupan asrama tak berlaku untuk absen sekolah');
        $this->assertSame('Hari Libur — Libur Uji SMP', $k['Selasa, 3 Nov 2026']);
        $this->assertSame('Tidak ada data absen & keterangan',$k['Rabu, 4 Nov 2026'], 'libur jenjang lain tak berlaku');
        $this->assertSame('Izin: Sakit (Izin Pulang)', $k['Kamis, 5 Nov 2026']);
        $this->assertSame('Izin: Sakit (Izin Pulang)', $k['Jumat, 6 Nov 2026']);
        $this->assertSame('Hari Libur', $k['Sabtu, 7 Nov 2026']);
        $this->assertSame('Hari Libur', $k['Minggu, 8 Nov 2026']);
        $this->assertSame('Sakit — Dirawat di klinik', $k['Senin, 9 Nov 2026']);
        $this->assertSame('Tidak ada data absen & keterangan',$k['Selasa, 10 Nov 2026']);
        $this->assertArrayNotHasKey('Kamis, 12 Nov 2026', $k, 'hari bercatatan tak diberi keterangan');
        $this->assertStringNotContainsString('Sabtu, 14 Nov 2026', $html, 'hari ini tanpa catatan belum ditampilkan');
        $this->assertSame(1, substr_count($html, 'Kamis, 12 Nov 2026'));
        // Blok warna hari libur (15 Nov 2026): Minggu 1, Selasa 3 (kalender), Sabtu 7, Minggu 8 Nov.
        $this->assertSame(4, substr_count($html, 'data-hari-libur'));
        $this->assertMatchesRegularExpression('/bg-rose-50 ring-rose-200"\s+data-hari-libur/', $html);

        // Absen ASRAMA: akhir pekan bukan libur, tapi kepulangan (cakupan asrama) libur.
        $k = $this->keterangan($this->sebagaiIbu()->get(route('portal.absensi', ['tab' => 'asrama']))->assertOk()->getContent());
        $this->assertSame('Hari Libur — Kepulangan Santri', $k['Senin, 2 Nov 2026']);
        $this->assertSame('Tidak ada data absen & keterangan',$k['Sabtu, 7 Nov 2026']);
        $this->assertSame('Tidak ada data absen & keterangan',$k['Selasa, 3 Nov 2026'], 'libur sekolah saja tak berlaku untuk asrama');
    }

    public function test_kalender_libur_hanya_pemegang_hak(): void
    {
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        $staf = User::create(['username' => 'stf', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $staf->id_pengguna, 'kode_modul' => 'kalender-libur', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $isi = ['nama' => 'Libur Semester Gasal', 'mulai' => '2026-12-20', 'selesai' => '2027-01-04', 'kode_jenjang' => 'J002', 'cakupan' => 'semua'];
        $this->actingAs($staf)->get(route('kalender_libur.index'))->assertOk()->assertDontSee('data-form-libur', false);
        $this->actingAs($staf)->post(route('kalender_libur.store'), $isi)->assertForbidden();

        $this->actingAs($admin)->post(route('kalender_libur.store'), ['selesai' => '2026-12-01'] + $isi)->assertSessionHasErrors('selesai');
        $this->actingAs($admin)->post(route('kalender_libur.store'), $isi)->assertRedirect();
        $l = KalenderLibur::sole();
        $this->actingAs($admin)->get(route('kalender_libur.index', ['tahun' => 2026]))->assertOk()->assertSee('Libur Semester Gasal')->assertSee('16 hari');
        $this->actingAs($admin)->put(route('kalender_libur.update', $l->id), ['nama' => 'Libur Semester'] + $isi)->assertRedirect();
        $this->assertSame('Libur Semester', $l->refresh()->nama);
        $this->actingAs($admin)->delete(route('kalender_libur.destroy', $l->id))->assertRedirect();
        $this->assertSame(0, KalenderLibur::count());
    }
}
