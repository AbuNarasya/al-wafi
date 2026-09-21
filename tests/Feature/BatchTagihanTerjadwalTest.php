<?php

namespace Tests\Feature;

use App\Models\BatchTagihan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Notification;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\BatchTagihanService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BATCH TAGIHAN — tahap 2: rilis terjadwal.
 *
 * Dua janji yang dijaga di sini, dan keduanya soal KESENYAPAN:
 *  • rilis yang berjalan tanpa ada yang menekan tombol WAJIB mengabarkan
 *    dirinya — kegagalan yang tak pernah sampai ke siapa pun lebih buruk
 *    daripada tak punya jadwal sama sekali;
 *  • cron BUKAN satu-satunya pemicu. Di produksi ia bisa berhenti tanpa pesan
 *    apa pun, jadi aplikasi ikut memeriksanya sendiri.
 */
class BatchTagihanTerjadwalTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZBT2';

    private const PIUTANG = '1.ZZBT2.1';

    private const PENDAPATAN = '4.ZZBT2.1';

    private const TA = '2026/2027';

    private User $penyusun;

    private User $pengotorisasi;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->penyusun = User::create([
            'username' => 'zzbt2_susun', 'nama' => 'Penyusun', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
        $this->pengotorisasi = User::create([
            'username' => 'zzbt2_otor', 'nama' => 'Pengotorisasi', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZBT2U', 'nama_unit' => 'Unit Uji']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Terjadwal Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'EKS', 'nama' => 'Ekskul Memanah', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZBT2U', 'status' => 'aktif',
            'pengakuan' => 'akrual', 'cara_tagih' => 'kepesertaan',
        ]);
    }

    private function santri(string $nis): Santri
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '08'.$nis,
            'nama' => 'Ayah', 'telepon' => '08'.$nis, 'status' => 'aktif',
        ]);

        return Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => "Santri {$nis}",
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    /** Draft yang sudah diotorisasi dengan jadwal sejam lagi. */
    private function batchTerjadwal(Santri $s): BatchTagihan
    {
        $svc = new BatchTagihanService;
        $batch = $svc->susun([
            'modul' => 'tagihan_lain',
            'judul' => 'Ekskul September',
            'parameter' => ['kode_jenis' => 'EKS', 'sumber' => 'manual', 'id_santri' => [$s->id], 'nominal' => '75000'],
        ], $this->penyusun->id_pengguna);

        $svc->otorisasi($batch->id, ['rilis_pada' => now()->addHour()->toDateTimeString()],
            $this->pengotorisasi->id_pengguna);

        return $batch->refresh();
    }

    public function test_perintah_terjadwal_menerbitkan_batch_yang_waktunya_lewat(): void
    {
        $s = $this->santri('440001');
        $batch = $this->batchTerjadwal($s);

        $this->travel(2)->hours();
        $this->artisan('tagihan:rilis-terjadwal')->assertExitCode(0);

        $this->assertSame('dirilis', $batch->refresh()->status);
        $this->assertSame(1, TagihanSantri::count());
        $this->assertNull($batch->dirilis_oleh, 'Dirilis penjadwal, jadi tak ada penggunanya.');
    }

    public function test_perintah_diam_saat_tak_ada_yang_jatuh_tempo(): void
    {
        $s = $this->santri('440002');
        $this->batchTerjadwal($s);

        // Belum waktunya.
        $this->artisan('tagihan:rilis-terjadwal')->assertExitCode(0);

        $this->assertSame(0, TagihanSantri::count());
    }

    public function test_rilis_tak_bertuan_mengabarkan_diri_ke_penyusun_dan_pengotorisasi(): void
    {
        $s = $this->santri('440003');
        $batch = $this->batchTerjadwal($s);

        $this->travel(2)->hours();
        $this->artisan('tagihan:rilis-terjadwal');

        $kabar = Notification::where('jenis', BatchTagihanService::JENIS_NOTIF)->get();
        $this->assertCount(2, $kabar);
        $this->assertEqualsCanonicalizing(
            [$this->penyusun->id_pengguna, $this->pengotorisasi->id_pengguna],
            $kabar->pluck('id_pengguna')->all(),
        );
        $this->assertSame('BatchTagihan', $kabar->first()->ref_jenis);
        $this->assertSame((string) $batch->id, $kabar->first()->ref_id);
        $this->assertStringContainsString('Dirilis otomatis', $kabar->first()->judul);
    }

    public function test_rilis_yang_ditekan_orang_tidak_mengirim_kabar(): void
    {
        $s = $this->santri('440004');
        $batch = $this->batchTerjadwal($s);

        // Petugas tak sabar menunggu jadwalnya dan menekan tombol sendiri.
        (new BatchTagihanService)->rilis($batch->id, $this->pengotorisasi->id_pengguna);

        $this->assertSame(1, TagihanSantri::count());
        $this->assertSame(0, Notification::where('jenis', BatchTagihanService::JENIS_NOTIF)->count(),
            'Yang menekan tombol sudah melihat hasilnya di layar.');
    }

    public function test_kegagalan_rilis_terjadwal_dikabarkan_dan_perintahnya_keluar_tak_nol(): void
    {
        $s = $this->santri('440005');
        $batch = $this->batchTerjadwal($s);

        // Jenis biayanya dinonaktifkan di antara otorisasi dan rilis.
        JenisBiaya::where('kode', 'EKS')->update(['status' => 'nonaktif']);

        $this->travel(2)->hours();
        $this->artisan('tagihan:rilis-terjadwal')->assertExitCode(1);

        $this->assertSame('gagal', $batch->refresh()->status);
        $this->assertSame(0, TagihanSantri::count(), 'Tak satu pun tagihan boleh lahir dari batch yang gagal.');

        $kabar = Notification::where('jenis', BatchTagihanService::JENIS_NOTIF)->first();
        $this->assertNotNull($kabar, 'Kegagalan yang tak pernah sampai ke siapa pun adalah kegagalan senyap.');
        $this->assertStringContainsString('GAGAL', $kabar->judul);
    }

    public function test_pemicu_cadangan_menerbitkan_walau_cron_tak_pernah_jalan(): void
    {
        $s = $this->santri('440006');
        $batch = $this->batchTerjadwal($s);

        $this->travel(2)->hours();

        // Cron mati; yang menyalakan adalah orang yang membuka halaman.
        (new BatchTagihanService)->pemicuCadangan();

        $this->assertSame('dirilis', $batch->refresh()->status);
        $this->assertSame(1, TagihanSantri::count());
    }

    public function test_pemicu_cadangan_tak_menyentuh_batch_yang_belum_waktunya(): void
    {
        $s = $this->santri('440007');
        $batch = $this->batchTerjadwal($s);

        (new BatchTagihanService)->pemicuCadangan();

        $this->assertSame('diotorisasi', $batch->refresh()->status);
        $this->assertSame(0, TagihanSantri::count());
    }

    public function test_batch_tanpa_jadwal_tak_pernah_tersentuh_penjadwal(): void
    {
        $s = $this->santri('440008');
        $svc = new BatchTagihanService;
        $batch = $svc->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'EKS', 'sumber' => 'manual', 'id_santri' => [$s->id], 'nominal' => '75000'],
        ], $this->penyusun->id_pengguna);
        $svc->otorisasi($batch->id, [], $this->pengotorisasi->id_pengguna);

        $this->travel(30)->days();
        $this->artisan('tagihan:rilis-terjadwal')->assertExitCode(0);

        $this->assertSame('diotorisasi', $batch->refresh()->status);
        $this->assertSame(0, TagihanSantri::count());
    }
}
