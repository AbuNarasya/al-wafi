<?php

namespace Tests\Feature;

use App\Models\BatchTagihan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\SetoranPemakaian;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TarifPemakaian;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\BatchTagihanService;
use App\Services\Modules\PemakaianLainService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenghitungKueri;
use Tests\TestCase;

/**
 * BATCH TAGIHAN — jalur pemakaian (laundry).
 *
 * Jalur inilah yang paling berbahaya dijadikan batch, dan sebabnya halus:
 * nominal laundry berasal dari TIMBANGAN, dan timbangan terus berdatangan
 * selama jeda antara otorisasi dan rilis.
 *
 * Kalau saat rilis kita menyapu ulang "semua setoran yang belum bertanda",
 * timbangan yang masuk setelah otorisasi akan ikut ditandai lunas oleh tagihan
 * yang nominalnya TIDAK menghitungnya — kilogramnya lenyap tanpa jejak, tanpa
 * galat, dan tanpa ada yang tahu sampai wali membandingkan sendiri catatannya.
 *
 * Karena itu id setorannya dijepret saat draft disusun, dan hanya itu yang
 * ditandai saat rilis.
 */
class BatchTagihanPemakaianTest extends TestCase
{
    use MenghitungKueri;
    use RefreshDatabase;

    private const GRP = 'ZZBP';

    private const PENDAPATAN = '4.ZZBP.1';

    private const PIUTANG = '1.ZZBP.1';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zzbp_petugas', 'nama' => 'Petugas Laundry', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZBPU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Batch Laundry']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Laundry', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'LDR-SMP', 'nama' => 'Laundry SMP', 'tipe' => 'lain', 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZBPU', 'status' => 'aktif', 'pengakuan' => 'akrual', 'cara_tagih' => 'pemakaian',
        ]);
        TarifPemakaian::create([
            'kode_jenis' => 'LDR-SMP', 'tarif_satuan' => '7000', 'nama_satuan' => 'kg', 'kuota_gratis' => '20',
        ]);
    }

    private function santri(string $nis, string $nama): Santri
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}", 'telepon_ayah' => '08'.$nis,
            'nama' => "Ayah {$nama}", 'telepon' => '08'.$nis, 'status' => 'aktif',
        ]);

        return Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    private function setor(Santri $s, string $kg, string $tanggal = '2026-08-05'): SetoranPemakaian
    {
        return (new PemakaianLainService)->catat([
            'kode_jenis' => 'LDR-SMP', 'id_santri' => $s->id, 'tanggal' => $tanggal, 'kuantitas' => $kg,
        ], $this->petugas->id_pengguna);
    }

    private function susun(): BatchTagihan
    {
        return (new BatchTagihanService)->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'LDR-SMP', 'sumber' => 'pemakaian', 'periode' => '2026-08'],
        ], $this->petugas->id_pengguna);
    }

    public function test_nominal_dijepret_dari_timbangan_yang_sudah_masuk(): void
    {
        $s = $this->santri('660001', 'Ahmad Fauzi');
        $this->setor($s, '20');    // pas kuota
        $this->setor($s, '10');    // lewat 10 kg → 10 × 7.000

        $batch = $this->susun();

        $this->assertSame(1, $batch->jumlah_baris);
        $this->assertSame('70000.00', $batch->total);
        $this->assertSame(0, TagihanSantri::count());
    }

    public function test_di_bawah_kuota_tidak_masuk_sebagai_tagihan_nol(): void
    {
        $lewat = $this->santri('660002', 'Bilal Ramadhan');
        $this->setor($lewat, '25');
        $hemat = $this->santri('660003', 'Hafizh Nur');
        $this->setor($hemat, '8');

        $batch = $this->susun();

        $this->assertSame(1, $batch->jumlah_baris, 'Yang di bawah kuota tak menerbitkan tagihan sama sekali.');
        $baris = $batch->baris()->where('id_santri', $hemat->id)->firstOrFail();
        $this->assertSame('bebas', $baris->keputusan);
        $this->assertNull($baris->nominal);
    }

    public function test_timbangan_yang_masuk_setelah_otorisasi_tidak_ikut_ditandai_lunas(): void
    {
        $s = $this->santri('660004', 'Ilham Saputra');
        $awal = [$this->setor($s, '20')->id, $this->setor($s, '10')->id];

        $svc = new BatchTagihanService;
        $batch = $this->susun();
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        // Santri menyetor lagi SELAMA jeda — masih dalam periode yang sama.
        $susulan = $this->setor($s, '6', '2026-08-28');

        $svc->rilis($batch->id, $this->petugas->id_pengguna);

        $t = TagihanSantri::where('id_santri', $s->id)->firstOrFail();
        $this->assertSame('70000.00', $t->nominal, 'Nominalnya tetap yang diperiksa petugas.');

        // Dua setoran yang dijepret ikut bertanda…
        foreach ($awal as $id) {
            $this->assertSame($t->id, SetoranPemakaian::find($id)->id_tagihan);
        }
        // …dan yang susulan TETAP TELANJANG, supaya terbawa ke periode berikutnya.
        $this->assertNull(
            SetoranPemakaian::find($susulan->id)->id_tagihan,
            'Timbangan yang belum dihitung tak boleh ikut ditandai lunas — itu kilogram yang hilang.',
        );
    }

    public function test_setoran_susulan_ikut_terhitung_pada_batch_berikutnya(): void
    {
        $s = $this->santri('660005', 'Junaidi Akbar');
        $this->setor($s, '20');
        $this->setor($s, '10');

        $svc = new BatchTagihanService;
        $b1 = $this->susun();
        $svc->otorisasi($b1->id, [], $this->petugas->id_pengguna);
        $this->setor($s, '25', '2026-08-28');
        $svc->rilis($b1->id, $this->petugas->id_pengguna);

        // Batch kedua untuk periode berikutnya menyapu sisa yang belum bertanda.
        $b2 = (new BatchTagihanService)->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'LDR-SMP', 'sumber' => 'pemakaian', 'periode' => '2026-09'],
        ], $this->petugas->id_pengguna);

        // 25 kg sisa − kuota 20 = 5 kg × 7.000
        $this->assertSame('35000.00', $b2->total);
    }

    /**
     * Susun & rilis tak bertambah kuerinya bersama jumlah santri. Dulu id
     * setoran dijepret dengan satu kueri per santri, dan saat rilis setorannya
     * ditandai dengan satu UPDATE per santri.
     */
    public function test_susun_dan_rilis_tak_tumbuh_bersama_jumlah_santri(): void
    {
        foreach (['770001', '770002'] as $nis) {
            $s = $this->santri($nis, "Santri {$nis}");
            $this->setor($s, '15');
            $this->setor($s, '15');
        }
        [$susunSedikit, $rilisSedikit] = $this->ukur();

        $baru = [];
        for ($i = 1; $i <= 8; $i++) {
            $s = $this->santri('77010'.$i, "Tambahan {$i}");
            $this->setor($s, '15');
            $this->setor($s, '15');
            $baru[] = $s;
        }
        [$susunBanyak, $rilisBanyak] = $this->ukur();

        $this->assertLessThanOrEqual($susunSedikit, $susunBanyak, "susun: 2 santri = {$susunSedikit}, 8 santri = {$susunBanyak}");
        $this->assertLessThanOrEqual($rilisSedikit, $rilisBanyak, "rilis: 2 santri = {$rilisSedikit}, 8 santri = {$rilisBanyak}");

        // Setiap setoran tertandai dengan tagihan SANTRINYA sendiri — tak ada
        // yang tertinggal, tak ada yang tertukar.
        $this->assertSame(0, SetoranPemakaian::whereNull('id_tagihan')->count());
        foreach (SetoranPemakaian::all() as $setoran) {
            $this->assertSame($setoran->id_santri, TagihanSantri::find($setoran->id_tagihan)?->id_santri);
        }
    }

    /** @return array{0:int,1:int} */
    private function ukur(): array
    {
        $batch = null;
        $susun = $this->hitungKueri(function () use (&$batch) {
            $batch = $this->susun();
        });
        $svc = new BatchTagihanService;
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);
        $rilis = $this->hitungKueri(fn () => $svc->rilis($batch->id, $this->petugas->id_pengguna));

        return [$susun, $rilis];
    }
}
