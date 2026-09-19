<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Modules\DanaService;
use App\Services\Reports\ReportsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LAPORAN PERUBAHAN ASET NETO (ISAK 35).
 *
 * Laporan paling khas entitas nirlaba dan satu-satunya yang tak punya padanan
 * di format perusahaan: ia memisahkan aset neto yang boleh dipakai bebas dari
 * yang terikat, lalu memperlihatkan perpindahan di antara keduanya.
 *
 * Yang paling mudah salah adalah PELEPASAN PEMBATASAN — dan justru itu yang
 * membuat laporannya masuk akal, karena beban seluruhnya ditanggung kolom
 * tanpa pembatasan.
 */
class PerubahanAsetNetoTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.ZZAN.KAS';

    private const ASET_NETO = '3.ZZAN.NET';

    private const PEND_UMUM = '4.ZZAN.UMU';

    private const PEND_WAKAF = '4.ZZAN.WKF';

    private const BEBAN = '5.ZZAN.BBN';

    private const BAGIAN = 'BZZAN';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['1' => 'Aset', '3' => 'Ekuitas', '4' => 'Pendapatan', '5' => 'Beban'] as $k => $n) {
            CoaGroup::create(['kode_grup' => $k, 'nama_grup' => $n, 'level' => 1]);
        }
        foreach (['G1' => '1', 'G3' => '3', 'G4' => '4', 'G5' => '5'] as $g => $induk) {
            CoaGroup::create(['kode_grup' => $g, 'nama_grup' => "Grup {$g}", 'kode_induk' => $induk, 'level' => 3]);
        }

        foreach ([
            [self::KAS, 'Kas', 'debet', 'G1'],
            [self::ASET_NETO, 'Aset Neto', 'kredit', 'G3'],
            [self::PEND_UMUM, 'Pendapatan Umum', 'kredit', 'G4'],
            [self::PEND_WAKAF, 'Penerimaan Wakaf', 'kredit', 'G4'],
            [self::BEBAN, 'Beban Operasional', 'debet', 'G5'],
        ] as [$k, $n, $s, $g]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => $g, 'jenis_saldo' => $s]);
        }

        // Akun penerimaan wakaf ditandai terikat — inilah yang membelah kolomnya.
        CoaDetail::find(self::PEND_WAKAF)->update(['sifat_pembatasan' => 'dengan_pembatasan']);

        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Umum', 'level' => 3]);
        Level::create(['kode_level' => 'LAN', 'nama_level' => 'Admin', 'max_transaksi' => null]);
    }

    private function jurnal(string $ref, string $tanggal, array $lines): void
    {
        PostingService::postJournal([
            'referensi' => $ref, 'tanggal' => $tanggal, 'sumber_modul' => 'JurnalUmum', 'lines' => $lines,
        ]);
    }

    public function test_akun_pendapatan_baru_lahir_tanpa_pembatasan(): void
    {
        // Migrasi hanya menyentuh akun yang ada saat itu; aturan yang sama
        // harus berlaku untuk akun yang dibuat sesudahnya.
        $baru = CoaDetail::create(['kode_coa' => '4.ZZAN.BARU', 'nama_coa' => 'Pendapatan Baru', 'kode_grup' => 'G4', 'jenis_saldo' => 'kredit']);
        $aset = CoaDetail::create(['kode_coa' => '1.ZZAN.BARU', 'nama_coa' => 'Aset Baru', 'kode_grup' => 'G1', 'jenis_saldo' => 'debet']);

        $this->assertSame('tanpa_pembatasan', $baru->sifat_pembatasan);
        $this->assertNull($aset->sifat_pembatasan, 'aset bukan aset neto — pembatasan tak berlaku padanya');
    }

    public function test_pendapatan_terbelah_menurut_sifat_pembatasan_akunnya(): void
    {
        $this->jurnal('JU-A1', '2026-07-05', [
            ['kode_coa' => self::KAS, 'debet' => '60000000', 'kredit' => '0'],
            ['kode_coa' => self::PEND_UMUM, 'debet' => '0', 'kredit' => '60000000'],
        ]);
        $this->jurnal('JU-A2', '2026-07-06', [
            ['kode_coa' => self::KAS, 'debet' => '40000000', 'kredit' => '0'],
            ['kode_coa' => self::PEND_WAKAF, 'debet' => '0', 'kredit' => '40000000'],
        ]);

        $d = (new ReportsService)->perubahanAsetNeto('2026-07-01', '2026-07-31');

        $this->assertSame('60000000.00', $d['pendapatan']['tanpa']);
        $this->assertSame('40000000.00', $d['pendapatan']['dengan']);
        $this->assertSame('100000000.00', $d['pendapatan']['jumlah']);
        $this->assertTrue($d['ada_pembatasan']);
    }

    public function test_beban_seluruhnya_ditanggung_kolom_tanpa_pembatasan(): void
    {
        $this->jurnal('JU-A3', '2026-07-10', [
            ['kode_coa' => self::BEBAN, 'debet' => '15000000', 'kredit' => '0', 'kode_bagian' => self::BAGIAN],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '15000000'],
        ]);

        $d = (new ReportsService)->perubahanAsetNeto('2026-07-01', '2026-07-31');

        $this->assertSame('15000000.00', $d['beban']);
        $this->assertSame('-15000000.00', $d['kenaikan']['tanpa']);
        $this->assertSame('0.00', $d['kenaikan']['dengan']);
    }

    public function test_belanja_dana_terikat_menjadi_pelepasan_pembatasan(): void
    {
        $svc = new DanaService;
        $svc->simpan([
            'kode_dana' => 'WKF-AN', 'nama_dana' => 'Wakaf Pembangunan',
            'jenis' => 'terikat_temporer', 'peruntukan' => 'Pembangunan.', 'status' => 'aktif',
        ]);
        $svc->aturAkun('WKF-AN', [self::BEBAN]);

        // Terima wakaf 40jt, belanjakan 25jt sesuai peruntukan.
        $this->jurnal('JU-A4', '2026-07-05', [
            ['kode_coa' => self::KAS, 'debet' => '40000000', 'kredit' => '0', 'kode_dana' => 'WKF-AN'],
            ['kode_coa' => self::PEND_WAKAF, 'debet' => '0', 'kredit' => '40000000', 'kode_dana' => 'WKF-AN'],
        ]);
        $this->jurnal('JU-A5', '2026-07-20', [
            ['kode_coa' => self::BEBAN, 'debet' => '25000000', 'kredit' => '0', 'kode_bagian' => self::BAGIAN, 'kode_dana' => 'WKF-AN'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '25000000', 'kode_dana' => 'WKF-AN'],
        ]);

        $d = (new ReportsService)->perubahanAsetNeto('2026-07-01', '2026-07-31');

        // Pembatasan gugur sebesar yang terpakai sesuai peruntukannya.
        $this->assertSame('25000000.00', $d['pelepasan']);

        // Kolom tanpa pembatasan: menanggung beban 25jt, menerima pelepasan 25jt
        // → bersih nol. Tanpa baris pelepasan, ia akan tampak rugi 25jt padahal
        // belanjanya dibiayai dana terikat.
        $this->assertSame('0.00', $d['kenaikan']['tanpa']);

        // Kolom dengan pembatasan: terima 40jt, lepas 25jt → sisa terikat 15jt.
        $this->assertSame('15000000.00', $d['kenaikan']['dengan']);

        // Jumlahnya tetap = pendapatan − beban, persis seperti laba rugi.
        $this->assertSame('15000000.00', $d['kenaikan']['jumlah']);
    }

    public function test_belanja_dana_tidak_terikat_bukan_pelepasan_pembatasan(): void
    {
        $svc = new DanaService;
        $svc->simpan(['kode_dana' => 'UMM-AN', 'nama_dana' => 'Dana Umum', 'jenis' => 'tidak_terikat', 'status' => 'aktif']);

        $this->jurnal('JU-A6', '2026-07-20', [
            ['kode_coa' => self::BEBAN, 'debet' => '5000000', 'kredit' => '0', 'kode_bagian' => self::BAGIAN, 'kode_dana' => 'UMM-AN'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '5000000', 'kode_dana' => 'UMM-AN'],
        ]);

        $this->assertSame('0.00', (new ReportsService)->perubahanAsetNeto('2026-07-01', '2026-07-31')['pelepasan']);
    }

    public function test_aset_neto_akhir_sama_dengan_awal_ditambah_kenaikannya(): void
    {
        // Aset neto awal dari periode sebelumnya.
        $this->jurnal('JU-A7', '2026-06-01', [
            ['kode_coa' => self::KAS, 'debet' => '20000000', 'kredit' => '0'],
            ['kode_coa' => self::ASET_NETO, 'debet' => '0', 'kredit' => '20000000'],
        ]);
        $this->jurnal('JU-A8', '2026-07-05', [
            ['kode_coa' => self::KAS, 'debet' => '30000000', 'kredit' => '0'],
            ['kode_coa' => self::PEND_UMUM, 'debet' => '0', 'kredit' => '30000000'],
        ]);

        $d = (new ReportsService)->perubahanAsetNeto('2026-07-01', '2026-07-31');

        $this->assertSame('20000000.00', $d['awal']['tanpa']);
        $this->assertSame('50000000.00', $d['akhir']['tanpa']);
        $this->assertSame(
            $d['akhir']['jumlah'],
            Money::of(Money::add($d['awal']['jumlah'], $d['kenaikan']['jumlah'])),
        );
    }

    public function test_halaman_terbuka_dan_mengajak_menandai_bila_belum_ada_pembatasan(): void
    {
        CoaDetail::find(self::PEND_WAKAF)->update(['sifat_pembatasan' => 'tanpa_pembatasan']);

        $admin = User::create([
            'username' => 'admn', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LAN', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.perubahan_aset_neto', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('Laporan Perubahan Aset Neto')
            ->assertSee('Pelepasan pembatasan')
            ->assertSee('Belum ada satu pun akun yang ditandai');
    }
}
