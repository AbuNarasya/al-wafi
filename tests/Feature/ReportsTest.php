<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Services\Ledger\PostingService;
use App\Services\Reports\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pelaporan keuangan: neraca (balanced), laba-rugi, buku besar. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.1.01';
    private const MODAL = '3.1.01';
    private const PEND = '4.1.01';
    private const BEBAN = '5.1.01';

    protected function setUp(): void
    {
        parent::setUp();
        // Hierarki COA: root kelompok 1..5 → grup level-3 → akun detail.
        foreach (['1' => 'Aset', '2' => 'Liabilitas', '3' => 'Ekuitas', '4' => 'Pendapatan', '5' => 'Beban'] as $k => $n) {
            CoaGroup::create(['kode_grup' => $k, 'nama_grup' => $n, 'level' => 1]);
        }
        foreach (['11' => '1', '31' => '3', '41' => '4', '51' => '5'] as $g => $induk) {
            CoaGroup::create(['kode_grup' => $g, 'nama_grup' => "Grup {$g}", 'kode_induk' => $induk, 'level' => 3]);
        }
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => '11', 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::MODAL, 'nama_coa' => 'Modal', 'kode_grup' => '31', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan Jasa', 'kode_grup' => '41', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban Operasional', 'kode_grup' => '51', 'jenis_saldo' => 'debet']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Umum', 'level' => 3]);

        // 3 jurnal.
        PostingService::postJournal(['referensi' => 'JU-1', 'tanggal' => '2026-07-01', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '1000000', 'kredit' => '0'],
            ['kode_coa' => self::MODAL, 'debet' => '0', 'kredit' => '1000000'],
        ]]);
        PostingService::postJournal(['referensi' => 'JU-2', 'tanggal' => '2026-07-05', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '500000', 'kredit' => '0'],
            ['kode_coa' => self::PEND, 'debet' => '0', 'kredit' => '500000'],
        ]]);
        PostingService::postJournal(['referensi' => 'JU-3', 'tanggal' => '2026-07-10', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::BEBAN, 'debet' => '200000', 'kredit' => '0', 'kode_bagian' => 'B1'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '200000'],
        ]]);
    }

    public function test_neraca_balanced(): void
    {
        $n = (new ReportsService)->neraca('2026-07-31');
        $this->assertTrue($n['balanced']);
        $this->assertSame('1300000.00', $n['total_aset']); // kas 1jt + 500rb - 200rb
        $this->assertSame('300000.00', $n['ekuitas']['laba_berjalan']); // 500rb - 200rb
        $this->assertSame('1300000.00', $n['total_ekuitas']); // modal 1jt + laba 300rb
    }

    public function test_laba_rugi(): void
    {
        $lr = (new ReportsService)->labaRugi('2026-07-01', '2026-07-31');
        $this->assertSame('500000.00', $lr['total_pendapatan']);
        $this->assertSame('200000.00', $lr['total_beban']);
        $this->assertSame('300000.00', $lr['laba_rugi_bersih']);
    }

    public function test_buku_besar_kas(): void
    {
        $bb = (new ReportsService)->bukuBesar(self::KAS, '2026-07-01', '2026-07-31');
        $this->assertCount(3, $bb['mutasi']); // 3 baris menyentuh kas
        $this->assertSame('1300000.00', $bb['saldo_akhir']);
    }

    public function test_arus_kas_kosong_bila_tanpa_cash_voucher(): void
    {
        // Jurnal di atas via JurnalUmum, bukan CashIn/CashOut → arus kas nol.
        $ak = (new ReportsService)->arusKas('2026-07-01', '2026-07-31');
        $this->assertSame('0.00', $ak['total_masuk']);
        $this->assertSame('0.00', $ak['total_keluar']);
    }
}
