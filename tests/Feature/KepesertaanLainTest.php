<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PesertaTagihanLain;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TarifTagihanLain;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\KepesertaanLainService;
use App\Services\Modules\TagihanLainService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KELUARGA B — kegiatan yang ditagihkan menurut KEPESERTAAN.
 *
 * Dua hal yang paling mudah salah dan karena itu dijaga di sini:
 *
 * 1. Sel kosong di matriks tarif berarti "jenjang itu TIDAK IKUT" — berlawanan
 *    dengan matriks Tarif Biaya, di mana sel kosong berarti "belum diisi".
 *    Bentuknya mirip, artinya bertolak belakang.
 * 2. Nominal peserta biasa DIHITUNG dari tarif jenjangnya saat dibutuhkan,
 *    bukan disalin saat mendaftar — sehingga tarif yang dikoreksi langsung
 *    berlaku tanpa menyentuh satu baris peserta pun. Keringanan yang disengaja
 *    justru harus KEBAL terhadap koreksi itu.
 */
class KepesertaanLainTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZKP';

    private const PENDAPATAN = '4.ZZKP.1';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zzkp_petugas', 'nama' => 'Petugas', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZKPU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Kepesertaan Uji']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Kegiatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'UMR', 'nama' => 'Program Umroh 2026', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => 'ZZKPU', 'status' => 'aktif',
            'pengakuan' => 'kas', 'cara_tagih' => 'kepesertaan',
        ]);

        // SDTQ sengaja TIDAK diberi sel: jenjang itu tidak ikut umroh.
        TarifTagihanLain::create(['kode_jenis' => 'UMR', 'kode_jenjang' => 'SMP', 'nominal' => '28500000']);
        TarifTagihanLain::create(['kode_jenis' => 'UMR', 'kode_jenjang' => 'SMA', 'nominal' => '31000000']);
    }

    private function santri(string $nis, string $nama, string $jenjang): Santri
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}", 'telepon_ayah' => '08'.$nis,
            'nama' => "Ayah {$nama}", 'telepon' => '08'.$nis, 'status' => 'aktif',
        ]);

        return Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => $jenjang, 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    private function svc(): KepesertaanLainService
    {
        return new KepesertaanLainService;
    }

    public function test_jenjang_tanpa_sel_tarif_tidak_bisa_didaftarkan(): void
    {
        $sdtq = $this->santri('880001', 'Adib Sadewa', 'SDTQ');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/dianggap tidak ikut/');
        $this->svc()->tambah('UMR', $sdtq->id);
    }

    public function test_jenjang_tanpa_sel_tetap_bisa_ikut_bila_diberi_nominal_khusus(): void
    {
        $sdtq = $this->santri('880002', 'Bahri Salim', 'SDTQ');
        $this->svc()->tambah('UMR', $sdtq->id, '15000000');

        $baris = $this->svc()->peserta('UMR');
        $this->assertCount(1, $baris);
        $this->assertNull($baris[0]['tarif'], 'SDTQ memang tak punya tarif jenjang.');
        $this->assertSame(0, bccomp($baris[0]['nominal'], '15000000', 2));
    }

    public function test_nominal_peserta_biasa_mengikuti_tarif_jenjang_yang_berlaku_sekarang(): void
    {
        $smp = $this->santri('880003', 'Cahyo Nugroho', 'SMP');
        $this->svc()->tambah('UMR', $smp->id);

        $this->assertSame(0, bccomp($this->svc()->peserta('UMR')[0]['nominal'], '28500000', 2));

        // Tarif dikoreksi di matriks — peserta biasa ikut berubah tanpa barisnya
        // disentuh sama sekali.
        $this->svc()->simpanGrid(['UMR' => ['SMP' => '29000000']]);

        $this->assertSame(0, bccomp($this->svc()->peserta('UMR')[0]['nominal'], '29000000', 2));
        $this->assertNull(PesertaTagihanLain::first()->nominal, 'Baris pesertanya tidak boleh ikut tertulis.');
    }

    public function test_keringanan_kebal_terhadap_perubahan_tarif(): void
    {
        $sma = $this->santri('880004', 'Dimas Prakoso', 'SMA');
        $this->svc()->tambah('UMR', $sma->id, '24000000');

        $this->svc()->simpanGrid(['UMR' => ['SMA' => '33000000']]);

        $baris = $this->svc()->peserta('UMR')[0];
        $this->assertSame(0, bccomp($baris['nominal'], '24000000', 2));
        $this->assertTrue($baris['keringanan'], 'Menyimpang dari tarif jenjang harus tertandai.');
    }

    public function test_mengosongkan_sel_menyebut_peserta_yang_jadi_menggantung(): void
    {
        $smp = $this->santri('880005', 'Endra Wijaya', 'SMP');
        $this->svc()->tambah('UMR', $smp->id);

        $hasil = $this->svc()->simpanGrid(['UMR' => ['SMP' => '', 'SMA' => '31000000']]);

        $this->assertSame(1, $hasil['dihapus']);
        $this->assertSame(['Endra Wijaya (Program Umroh 2026)'], $hasil['peserta_menggantung']);
    }

    public function test_penerbitan_menagih_tiap_jenjang_sebesar_tarifnya_sendiri(): void
    {
        $smp = $this->santri('880006', 'Faiz Rahman', 'SMP');
        $sma = $this->santri('880007', 'Gilang Saputra', 'SMA');
        $ringan = $this->santri('880008', 'Hadi Kusuma', 'SMA');
        $this->svc()->tambah('UMR', $smp->id);
        $this->svc()->tambah('UMR', $sma->id);
        $this->svc()->tambah('UMR', $ringan->id, '24000000');

        $hasil = (new TagihanLainService)->terbitkanPeserta([
            'kode_jenis' => 'UMR', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $this->assertSame(3, $hasil['terbit']);
        // 28.500.000 + 31.000.000 + 24.000.000 — dijumlahkan, bukan dikalikan.
        $this->assertSame(0, bccomp($hasil['total'], '83500000', 2));

        $this->assertSame(0, bccomp(TagihanSantri::where('id_santri', $smp->id)->value('nominal'), '28500000', 2));
        $this->assertSame(0, bccomp(TagihanSantri::where('id_santri', $sma->id)->value('nominal'), '31000000', 2));
        $this->assertSame(0, bccomp(TagihanSantri::where('id_santri', $ringan->id)->value('nominal'), '24000000', 2));
    }

    public function test_peserta_berhenti_tidak_ditagih_dan_bukan_kegagalan(): void
    {
        $ikut = $this->santri('880009', 'Ilham Fadli', 'SMP');
        $stop = $this->santri('880010', 'Jamal Ridho', 'SMP');
        $this->svc()->tambah('UMR', $ikut->id);
        $p = $this->svc()->tambah('UMR', $stop->id);
        $this->svc()->ubahStatus($p->id, 'berhenti');

        $hasil = (new TagihanLainService)->terbitkanPeserta([
            'kode_jenis' => 'UMR', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $this->assertSame(1, $hasil['terbit']);
        $this->assertSame([], $hasil['gugur'], 'Berhenti itu keputusan, bukan kegagalan — tak perlu dilaporkan.');
        $this->assertSame(0, TagihanSantri::where('id_santri', $stop->id)->count());
    }

    public function test_peserta_yang_jenjangnya_kehilangan_tarif_disebut_sebabnya(): void
    {
        $smp = $this->santri('880011', 'Kevin Anwar', 'SMP');
        $sma = $this->santri('880012', 'Luthfi Hakim', 'SMA');
        $this->svc()->tambah('UMR', $smp->id);
        $this->svc()->tambah('UMR', $sma->id);

        // SMP dicabut sesudah pesertanya terdaftar.
        $this->svc()->simpanGrid(['UMR' => ['SMP' => '', 'SMA' => '31000000']]);

        $hasil = (new TagihanLainService)->terbitkanPeserta([
            'kode_jenis' => 'UMR', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $this->assertSame(1, $hasil['terbit']);
        $this->assertCount(1, $hasil['gugur']);
        $this->assertStringContainsString('Kevin Anwar', $hasil['gugur'][0]);
        $this->assertStringContainsString('belum punya tarif', $hasil['gugur'][0]);
    }

    public function test_kegiatan_tanpa_peserta_menolak_dengan_sebabnya(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/pesertanya masih kosong/');
        (new TagihanLainService)->terbitkanPeserta([
            'kode_jenis' => 'UMR', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);
    }

    public function test_jenis_bercara_tagih_lain_tidak_bisa_dipakai_di_sini(): void
    {
        JenisBiaya::create([
            'kode' => 'LDR', 'nama' => 'Laundry', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => 'ZZKPU', 'status' => 'aktif',
            'pengakuan' => 'kas', 'cara_tagih' => 'pemakaian',
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak ditagih menurut kepesertaan/');
        $this->svc()->jenis('LDR');
    }

    public function test_matriks_hanya_memuat_jenis_berkepesertaan(): void
    {
        JenisBiaya::create([
            'kode' => 'LDR2', 'nama' => 'Laundry', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => 'ZZKPU', 'status' => 'aktif',
            'pengakuan' => 'kas', 'cara_tagih' => 'pemakaian',
        ]);

        $grid = $this->svc()->grid();

        $this->assertSame(['UMR'], array_column($grid['baris'], 'kode'));
        $this->assertSame(['SDTQ', 'SMP', 'SMA'], array_column($grid['jenjang'], 'kode'));
        $this->assertNull($grid['baris'][0]['sel']['SDTQ'], 'SDTQ tidak ikut — selnya harus kosong, bukan nol.');
    }
}
