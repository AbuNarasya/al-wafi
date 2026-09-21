<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\BatchTagihanService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LAPISAN HTTP Batch Tagihan: hak akses berlapis, dan isian waktu rilis.
 *
 * Bahaya yang dijaga di sini tak kelihatan dari layar: modul `batch-tagihan`
 * berdiri di atas tiga modul penerbit, jadi kalau haknya berdiri sendiri, siapa
 * pun yang diberi hak itu diam-diam memperoleh kuasa menerbitkan SPP seluruh
 * pesantren — kuasa yang tak pernah diputuskan siapa pun.
 */
class BatchTagihanAksesTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZBA';

    private const PIUTANG = '1.ZZBA.1';

    private const PENDAPATAN = '4.ZZBA.1';

    private const TA = '2026/2027';

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();
        Akses::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZBAU', 'nama_unit' => 'Unit Uji']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Akses Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'EKS', 'nama' => 'Ekskul', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZBAU', 'status' => 'aktif',
            'pengakuan' => 'akrual', 'cara_tagih' => 'kepesertaan',
        ]);
    }

    /** @param  array<string,array<string,bool>>  $hak */
    private function pengguna(string $username, array $hak): User
    {
        $user = User::create([
            'username' => $username, 'nama' => $username, 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create([
                'id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul,
                'lihat' => $aksi['lihat'] ?? true, 'buat' => $aksi['buat'] ?? false,
                'ubah' => $aksi['ubah'] ?? false, 'hapus' => $aksi['hapus'] ?? false, 'menu' => true,
            ]);
        }
        Akses::lupakan();

        return $user;
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

    public function test_hak_batch_saja_tidak_memberi_kuasa_menerbitkan_tagihan_lain(): void
    {
        $santri = $this->santri('770001');
        $user = $this->pengguna('batch_saja', [
            'batch-tagihan' => ['lihat' => true, 'buat' => true, 'ubah' => true],
            // Sengaja TANPA 'tagihan-lain'.
        ]);

        $this->actingAs($user)->post(route('batch_tagihan.store'), [
            'modul' => 'tagihan_lain', 'kode_jenis' => 'EKS', 'sumber' => 'manual',
            'id_santri' => [$santri->id], 'nominal' => '50000',
        ])->assertForbidden();

        $this->assertSame(0, \App\Models\BatchTagihan::count());
    }

    public function test_hak_modul_saja_tanpa_hak_batch_tak_bisa_membuka_layarnya(): void
    {
        $user = $this->pengguna('modul_saja', [
            'tagihan-lain' => ['lihat' => true, 'buat' => true],
        ]);

        $this->actingAs($user)->get(route('batch_tagihan.index'))->assertForbidden();
    }

    public function test_kedua_hak_lengkap_bisa_menyusun_sampai_merilis(): void
    {
        $santri = $this->santri('770002');
        $user = $this->pengguna('lengkap', [
            'batch-tagihan' => ['lihat' => true, 'buat' => true, 'ubah' => true],
            'tagihan-lain' => ['lihat' => true, 'buat' => true],
        ]);

        $this->actingAs($user)->post(route('batch_tagihan.store'), [
            'modul' => 'tagihan_lain', 'kode_jenis' => 'EKS', 'sumber' => 'manual',
            'id_santri' => [$santri->id], 'nominal' => '50000', 'periode' => '2026-09',
        ])->assertRedirect();

        $batch = \App\Models\BatchTagihan::firstOrFail();
        $this->assertSame('draft', $batch->status);
        $this->assertSame(0, TagihanSantri::count());

        $this->actingAs($user)->post(route('batch_tagihan.otorisasi', $batch->id))->assertRedirect();
        $this->assertSame('diotorisasi', $batch->refresh()->status);
        $this->assertSame(0, TagihanSantri::count(), 'Otorisasi saja belum boleh menerbitkan apa pun.');

        $this->actingAs($user)->post(route('batch_tagihan.rilis', $batch->id))->assertRedirect();
        $this->assertSame('dirilis', $batch->refresh()->status);
        $this->assertSame(1, TagihanSantri::count());
    }

    /**
     * Tanggal & jam dikirim sebagai DUA isian terpisah, lalu digabung di
     * controller — `datetime-local` bawaan peramban sulit dipakai (kolom jam &
     * menitnya tak selalu bisa diubah).
     */
    public function test_tanggal_dan_jam_terpisah_digabung_jadi_waktu_rilis(): void
    {
        $santri = $this->santri('770004');
        $user = $this->pengguna('waktu_rilis', [
            'batch-tagihan' => ['lihat' => true, 'buat' => true, 'ubah' => true],
            'tagihan-lain' => ['lihat' => true, 'buat' => true],
        ]);
        $besok = now()->addDay()->toDateString();

        $this->actingAs($user)->post(route('batch_tagihan.store'), [
            'modul' => 'tagihan_lain', 'kode_jenis' => 'EKS', 'sumber' => 'manual',
            'id_santri' => [$santri->id], 'nominal' => '50000',
            'rilis_tanggal' => $besok, 'rilis_jam' => '06:30',
        ])->assertRedirect();

        $batch = \App\Models\BatchTagihan::firstOrFail();
        $this->assertSame($besok.' 06:30:00', $batch->rilis_pada->toDateTimeString());
    }

    public function test_jam_tanpa_tanggal_ditolak_dengan_pesan_bukan_dijadwalkan_diam_diam(): void
    {
        $santri = $this->santri('770005');
        $user = $this->pengguna('jam_yatim', [
            'batch-tagihan' => ['lihat' => true, 'buat' => true, 'ubah' => true],
            'tagihan-lain' => ['lihat' => true, 'buat' => true],
        ]);

        $this->actingAs($user)->post(route('batch_tagihan.store'), [
            'modul' => 'tagihan_lain', 'kode_jenis' => 'EKS', 'sumber' => 'manual',
            'id_santri' => [$santri->id], 'nominal' => '50000',
            'rilis_tanggal' => '', 'rilis_jam' => '06:30',
        ])->assertSessionHas('error');

        $this->assertSame(0, \App\Models\BatchTagihan::count());
    }

    /** Jam bawaan form 00:00 tanpa tanggal berarti "tak dijadwalkan", bukan salah isi. */
    public function test_jam_bawaan_tanpa_tanggal_berarti_rilis_manual(): void
    {
        $santri = $this->santri('770006');
        $user = $this->pengguna('manual_saja', [
            'batch-tagihan' => ['lihat' => true, 'buat' => true, 'ubah' => true],
            'tagihan-lain' => ['lihat' => true, 'buat' => true],
        ]);

        $this->actingAs($user)->post(route('batch_tagihan.store'), [
            'modul' => 'tagihan_lain', 'kode_jenis' => 'EKS', 'sumber' => 'manual',
            'id_santri' => [$santri->id], 'nominal' => '50000',
            'rilis_tanggal' => '', 'rilis_jam' => '00:00',
        ])->assertRedirect();

        $this->assertNull(\App\Models\BatchTagihan::firstOrFail()->rilis_pada);
    }

    public function test_perilis_terjadwal_tidak_memeriksa_hak_karena_tak_ada_penggunanya(): void
    {
        $santri = $this->santri('770003');
        $user = $this->pengguna('penjadwal_uji', [
            'batch-tagihan' => ['lihat' => true, 'buat' => true, 'ubah' => true],
            'tagihan-lain' => ['lihat' => true, 'buat' => true],
        ]);

        $svc = new BatchTagihanService;
        $batch = $svc->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'EKS', 'sumber' => 'manual', 'id_santri' => [$santri->id], 'nominal' => '50000'],
        ], $user->id_pengguna);
        $svc->otorisasi($batch->id, ['rilis_pada' => now()->addHour()->toDateTimeString()], $user->id_pengguna);

        // Hak pengguna DICABUT setelah otorisasi. Rilis terjadwal tetap jalan:
        // yang sudah diotorisasi adalah perintah yang sah pada saat diberikan,
        // persis seperti transfer terjadwal di bank tak dibatalkan oleh
        // berubahnya kewenangan pemberi perintah sesudahnya.
        HakAksesModul::where('id_pengguna', $user->id_pengguna)->delete();
        Akses::lupakan();

        $this->travel(2)->hours();
        $ringkas = $svc->rilisYangJatuhTempo();

        $this->assertSame(1, $ringkas['terbit']);
        $this->assertSame(1, TagihanSantri::count());
    }
}
