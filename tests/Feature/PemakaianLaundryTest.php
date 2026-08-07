<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
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
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\PemakaianLainService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LAUNDRY — tagihan yang lahir dari PEMAKAIAN, dengan kuota gratis.
 *
 * Tiga aturan yang paling mudah salah dan karena itu dijaga di sini:
 *
 * 1. Pemakaian di bawah kuota TIDAK menerbitkan tagihan sama sekali — bukan
 *    tagihan bernilai nol, yang tetap harus dibaca dan dilaporkan seseorang.
 * 2. Setoran yang belum tertagih TIDAK hangus. Ia tetap tak bertanda, jadi ikut
 *    terhitung pada penerbitan berikutnya.
 * 3. Setoran yang sudah tertagih tak pernah tersapu dua kali.
 */
class PemakaianLaundryTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZLD';

    private const PENDAPATAN = '4.ZZLD.1';

    private const PIUTANG = '1.ZZLD.1';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zzld_petugas', 'nama' => 'Petugas Laundry', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZLDU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Laundry Uji']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Laundry', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'LDR-SMP', 'nama' => 'Laundry SMP', 'tipe' => 'lain', 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZLDU', 'status' => 'aktif', 'pengakuan' => 'akrual',
            'cara_tagih' => 'pemakaian', 'tarif_satuan' => '7000', 'nama_satuan' => 'kg', 'kuota_gratis' => '20',
        ]);
    }

    private function santri(string $nis, string $nama, string $jenjang = 'SMP'): Santri
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

    private function svc(): PemakaianLainService
    {
        return new PemakaianLainService;
    }

    private function setor(Santri $s, string $kg, string $tanggal = '2026-08-05'): SetoranPemakaian
    {
        return $this->svc()->catat([
            'kode_jenis' => 'LDR-SMP', 'id_santri' => $s->id,
            'tanggal' => $tanggal, 'kuantitas' => $kg,
        ], $this->petugas->id_pengguna);
    }

    public function test_kelebihan_di_atas_kuota_yang_ditagih_bukan_seluruh_pemakaian(): void
    {
        $s = $this->santri('550001', 'Ahmad Fauzi');
        $this->setor($s, '20');    // pas kuota
        $this->setor($s, '12.5');  // lewat 12,5 kg

        $rekap = $this->svc()->rekap('LDR-SMP');

        $this->assertSame(0, bccomp($rekap[0]['kuantitas'], '32.50', 2));
        $this->assertSame(0, bccomp($rekap[0]['kena_tagih'], '12.50', 2));
        // 12,5 kg × Rp 7.000
        $this->assertSame(0, bccomp($rekap[0]['nominal'], '87500', 2));
    }

    public function test_di_bawah_kuota_tidak_menerbitkan_tagihan_sama_sekali(): void
    {
        $bawah = $this->santri('550002', 'Bilal Ramadhan');
        $lewat = $this->santri('550003', 'Hafizh Nur');
        $this->setor($bawah, '18.4');
        $this->setor($lewat, '32.5');

        $hasil = $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-08', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $this->assertSame(1, $hasil['terbit'], 'Hanya yang melewati kuota.');
        $this->assertSame(['Bilal Ramadhan'], $hasil['di_bawah_kuota']);
        $this->assertSame(0, TagihanSantri::where('id_santri', $bawah->id)->count(),
            'Bukan tagihan Rp 0 — memang tak ada tagihan.');
        $this->assertSame(0, bccomp(TagihanSantri::where('id_santri', $lewat->id)->value('nominal'), '87500', 2));
    }

    public function test_setoran_di_bawah_kuota_tidak_hangus_dan_ikut_periode_berikutnya(): void
    {
        $s = $this->santri('550004', 'Ikhsan Maulana');
        $this->setor($s, '15', '2026-08-10');

        $this->assertSame(1, count($this->svc()->rekap('LDR-SMP')));
        try {
            $this->svc()->terbitkan([
                'kode_jenis' => 'LDR-SMP', 'periode' => '2026-08', 'tanggal' => '2026-09-01',
            ], $this->petugas->id_pengguna);
            $this->fail('Tak ada yang melewati kuota — seharusnya menolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('melewati kuota', $e->getMessage());
        }

        // Setoran Agustus tetap tak bertanda, jadi September menghitungnya lagi.
        $this->setor($s, '10', '2026-09-03');
        $hasil = $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-09', 'tanggal' => '2026-10-01',
        ], $this->petugas->id_pengguna);

        $this->assertSame(1, $hasil['terbit']);
        // 25 kg − 20 = 5 kg × 7.000
        $this->assertSame(0, bccomp($hasil['total'], '35000', 2));
    }

    public function test_setoran_yang_sudah_tertagih_tidak_tersapu_dua_kali(): void
    {
        $s = $this->santri('550005', 'Naufal Hakim');
        $this->setor($s, '32.5', '2026-08-05');

        $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-08', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $this->assertNotNull(SetoranPemakaian::first()->id_tagihan, 'Setorannya harus bertanda.');
        $this->assertSame([], $this->svc()->rekap('LDR-SMP'), 'Sudah tertagih ⇒ keluar dari rekap berjalan.');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Belum ada setoran yang bisa ditagih/');
        $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-09', 'tanggal' => '2026-10-01',
        ], $this->petugas->id_pengguna);
    }

    public function test_setoran_telat_dicatat_ikut_penerbitan_berikutnya(): void
    {
        $s = $this->santri('550006', 'Rizky Pratama');
        $this->setor($s, '32.5', '2026-08-05');
        $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-08', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        // Timbangan 28 Agustus yang baru sempat diketik bulan berikutnya.
        $this->setor($s, '25', '2026-08-28');

        $hasil = $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-09', 'tanggal' => '2026-10-01',
        ], $this->petugas->id_pengguna);

        // 25 kg − 20 kuota = 5 kg × 7.000. Tak menguap, tak tertagih dua kali.
        $this->assertSame(0, bccomp($hasil['total'], '35000', 2));
    }

    public function test_santri_jenjang_lain_ditolak(): void
    {
        $sma = $this->santri('550007', 'Salman Alfarisi', 'SMA');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/bukan santri jenjang layanan ini/');
        $this->setor($sma, '10');
    }

    public function test_setoran_yang_sudah_tertagih_tak_bisa_dihapus(): void
    {
        $s = $this->santri('550008', 'Umar Abdullah');
        $this->setor($s, '32.5');
        $this->svc()->terbitkan([
            'kode_jenis' => 'LDR-SMP', 'periode' => '2026-08', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah ikut tertagih/');
        $this->svc()->hapusSetoran(SetoranPemakaian::first()->id);
    }

    public function test_layanan_tanpa_tarif_satuan_menolak_dengan_sebabnya(): void
    {
        JenisBiaya::create([
            'kode' => 'LDR-X', 'nama' => 'Laundry Belum Lengkap', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => 'ZZLDU', 'status' => 'aktif',
            'pengakuan' => 'kas', 'cara_tagih' => 'pemakaian',
        ]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/belum punya tarif per satuan/');
        $this->svc()->jenis('LDR-X');
    }
}
