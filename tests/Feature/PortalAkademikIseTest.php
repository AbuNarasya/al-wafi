<?php

namespace Tests\Feature;

use App\Models\Jenjang;
use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\Wali;
use App\Services\PortalWali\MenuPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menu akademik portal wali dari API Histori ISE (keputusan user 14 Nov 2026): dibaca langsung, tidak disimpan;
 * hanya anak aktif milik keluarga; saklar ISE_HISTORI mati/uji/semua; draf disembunyikan; ISE mati → pesan ramah.
 */
class PortalAkademikIseTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'rd_kunci_uji_rahasia_0123456789';

    private Wali $wali;

    private Santri $ahmad;

    private Santri $lain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.ise.kunci_baca' => self::KUNCI, 'services.ise.histori' => 'semua', 'services.ise.url' => 'https://ise.contoh/api/all']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'A-1', 'nis' => '2425010541', 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'J002',
            'tingkat' => 1, 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
        $waliLain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Pak Budi', 'telepon_ayah' => '0822', 'email_ayah' => 'budi@contoh.id',
            'nama' => 'Pak Budi', 'telepon' => '0822', 'status' => 'aktif']);
        $this->lain = Santri::create(['no_pendaftaran' => 'B-1', 'nis' => '9999999999', 'nama' => 'Budi', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'J002',
            'tingkat' => 1, 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $waliLain->id]);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    private static function halaman(array $baris, int $akhir = 1): array
    {
        return ['success' => true, 'data' => ['current_page' => 1, 'last_page' => $akhir, 'total' => count($baris), 'data' => $baris]];
    }

    public function test_saklar_mati_atau_tanpa_kunci_menu_tak_ada(): void
    {
        config(['services.ise.histori' => 'mati']);
        $this->sebagaiIbu()->get(route('portal.absensi'))->assertNotFound();
        $menu = MenuPortal::tersedia($this->wali->refresh());
        $this->assertArrayNotHasKey('absensi', $menu);
        $this->assertArrayHasKey('kehadiran', $menu);

        config(['services.ise.histori' => 'semua', 'services.ise.kunci_baca' => null]);
        $this->sebagaiIbu()->get(route('portal.akademik'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_mode_uji_hanya_untuk_akun_uji(): void
    {
        Http::fake(['*' => Http::response(self::halaman([]))]);
        config(['services.ise.histori' => 'uji']);
        $this->sebagaiIbu()->get(route('portal.kegiatan'))->assertNotFound();

        $this->wali->forceFill(['akun_uji' => true])->save();
        $this->sebagaiIbu()->get(route('portal.kegiatan'))->assertOk()->assertSee('Belum ada data.');
        $menu = MenuPortal::tersedia($this->wali->refresh());
        $this->assertArrayHasKey('kegiatan', $menu);
        $this->assertArrayNotHasKey('kehadiran', $menu);
    }

    public function test_hanya_anak_sendiri_dan_kunci_tak_bocor(): void
    {
        Http::fake(['*' => Http::response(self::halaman([
            ['id' => 1, 'date' => '2026-11-13', 'morning_status' => 'present', 'morning_scanned_at' => '2026-11-13 06:58:51',
                'evening_status' => 'absent', 'evening_scanned_at' => null, 'notes' => 'Terminal: Gedung Rahasia'],
        ]))]);

        // ?anak= milik keluarga lain diabaikan: yang dibaca tetap anak sendiri.
        $this->sebagaiIbu()->get(route('portal.absensi', ['anak' => $this->lain->id]))->assertOk()
            ->assertSee('Pagi: Hadir 06:58')->assertSee('Sore: Tidak hadir')
            ->assertDontSee('Gedung Rahasia')->assertDontSee(self::KUNCI)->assertDontSee('Budi');

        Http::assertSent(fn (PermintaanHttp $r) => $r->hasHeader('X-Api-Key', self::KUNCI)
            && str_starts_with($r->url(), 'https://ise.contoh/api/all/histori/harian?')
            && $r['nis'] === '2425010541' && (int) $r['id_erp'] === $this->ahmad->id);
        Http::assertNotSent(fn (PermintaanHttp $r) => ($r['nis'] ?? null) === '9999999999');
    }

    public function test_ise_mati_dan_santri_belum_dikenal(): void
    {
        Http::fake(['*/histori/harian*' => Http::response(['message' => 'galat'], 500), '*/histori/event*' => Http::response(['success' => false], 404)]);
        $this->sebagaiIbu()->get(route('portal.absensi'))->assertOk()->assertSee('data-ise-gagal', false)
            ->assertSee('Data dari sistem sekolah sedang tidak bisa dimuat.');
        $this->sebagaiIbu()->get(route('portal.kegiatan'))->assertOk()->assertSee('Data santri ini belum tersedia di sistem sekolah.');
    }

    public function test_draf_disembunyikan_dan_rapor_terpisah_dari_prestasi(): void
    {
        Http::fake([
            '*/histori/feedback*' => Http::response(self::halaman([
                ['id' => 1, 'title' => 'Rajin murajaah', 'content' => 'Alhamdulillah konsisten.', 'type' => 'positive', 'category' => 'behavior', 'status' => 'sent', 'created_at' => '2026-11-10 08:00:00'],
                ['id' => 2, 'title' => 'Catatan masih draf', 'content' => 'Belum boleh dibaca wali.', 'type' => 'negative', 'status' => 'draft', 'created_at' => '2026-11-11 08:00:00'],
            ])),
            '*/histori/sertifikat*' => Http::response(self::halaman([
                ['id' => 10, 'type_name' => 'Rapor PTS Semester Gasal TP 2026/2027', 'issued_at' => '2026-10-26 00:00:00', 'file_path' => 'certificate-files/x.pdf'],
                ['id' => 11, 'type_name' => 'Student Of The Month', 'issued_at' => '2026-09-14 13:44:39', 'file_path' => 'certificate-files/y.pdf'],
            ])),
        ]);

        $this->sebagaiIbu()->get(route('portal.umpan_balik'))->assertOk()
            ->assertSee('Rajin murajaah')->assertSee('Apresiasi')->assertSee('Perilaku')->assertDontSee('Catatan masih draf');

        $this->sebagaiIbu()->get(route('portal.akademik', ['tab' => 'rapor']))->assertOk()
            ->assertSee('Rapor PTS Semester Gasal')->assertDontSee('Student Of The Month')->assertDontSee('certificate-files');
        $this->sebagaiIbu()->get(route('portal.prestasi'))->assertOk()
            ->assertSee('Student Of The Month')->assertDontSee('Rapor PTS Semester Gasal');
    }

    public function test_asrama_menghormati_izin_erp_dan_menampilkan_kamar(): void
    {
        Http::fake([
            '*/histori/asrama*' => Http::response(self::halaman([
                ['id' => 1, 'dormitory_id' => 15, 'attendance_type_id' => 23, 'attendance_type_name' => 'Shalat Maghrib',
                    'attendance_date' => '2026-11-05 00:00:00', 'attendance_time' => '17:49:43', 'status' => 'absent'],
                ['id' => 2, 'dormitory_id' => 15, 'attendance_type_id' => 5, 'attendance_date' => '2026-11-01 00:00:00', 'attendance_time' => '20:16', 'status' => 'absent'],
            ])),
            '*/histori/mutasi-asrama*' => Http::response(self::halaman([
                ['id' => 538, 'dormitory_id' => 15, 'dormitory_name' => 'DAMASCUS 1C', 'start_date' => '2026-07-11 11:53:42', 'end_date' => null],
                ['id' => 215, 'dormitory_id' => 9, 'start_date' => '2025-08-19 10:42:02', 'end_date' => '2026-07-10 20:22:00'],
            ])),
        ]);
        Perizinan::create(['nomor' => 'IZN-2611-0009', 'id_santri' => $this->ahmad->id, 'kode_tipe' => 'sakit', 'status' => 'keluar',
            'rencana_keluar' => '2026-11-04 08:00:00', 'rencana_kembali' => '2026-11-06 08:00:00', 'keluar_pada' => '2026-11-04 07:00:00', 'id_wali' => $this->wali->id]);

        $html = $this->sebagaiIbu()->get(route('portal.absensi', ['tab' => 'asrama']))->assertOk()
            ->assertSee('DAMASCUS 1C')->assertSee('Asrama #9')->assertSee('Shalat Maghrib')->assertSee('Sesi #5')->getContent();
        // 5 Nov (dalam izin) → Izin; 1 Nov (di luar izin) → Tidak hadir.
        $this->assertStringContainsString('bg-sky-100 text-sky-800">'.__('Izin'), $html);
        $this->assertStringContainsString('bg-red-100 text-red-700">Tidak hadir', $html);
    }

    public function test_halaman_kehadiran_lama_dialihkan_saat_menu_ise_menyala(): void
    {
        $this->sebagaiIbu()->get(route('portal.kehadiran', ['anak' => $this->ahmad->id]))
            ->assertRedirect(route('portal.absensi', ['tab' => 'asrama', 'anak' => $this->ahmad->id]));

        config(['services.ise.histori' => 'mati']);
        $this->sebagaiIbu()->get(route('portal.kehadiran'))->assertOk();
    }

    public function test_tanpa_anak_aktif(): void
    {
        $this->ahmad->forceFill(['status' => 'calon'])->save();
        Http::fake();
        $this->sebagaiIbu()->get(route('portal.absensi'))->assertOk()->assertSee('Menu ini hanya untuk santri aktif.');
        Http::assertNothingSent();
    }
}
