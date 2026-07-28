<?php

namespace Tests\Feature;

use App\Models\Accrue;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\BusinessUnit;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use App\Services\Modules\AccrueService;
use App\Services\Modules\BookTransferService;
use App\Services\Modules\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Accrue, Jurnal Umum, Pindah Buku → jurnal double-entry. */
class TransactionModulesTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTM';
    private const KAS1 = 'ZZTM.KAS1';
    private const KAS2 = 'ZZTM.KAS2';
    private const BEBAN = 'ZZTM.BEBAN';
    private const HUTANG = 'ZZTM.HUTANG';
    private const UNIT = 'ZZUNIT';

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'TM Test']);
        CoaDetail::create(['kode_coa' => self::KAS1, 'nama_coa' => 'Kas A', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::KAS2, 'nama_coa' => 'Kas B', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::KAS1, 'nama_rekening' => 'Kas A', 'jenis_rekening' => 'bank']);
        BankAccount::create(['kode_coa' => self::KAS2, 'nama_rekening' => 'Kas B', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Test']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->uid = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1'])->id_pengguna;
    }

    public function test_accrue_create_jurnal_balance(): void
    {
        $rec = (new AccrueService)->create([
            'tanggal' => '2026-07-15', 'periode' => '2026-07',
            'kode_coa_debet' => self::BEBAN, 'kode_coa_kredit' => self::HUTANG,
            'nominal' => '100000', 'keterangan' => 'Accrue listrik', 'kode_unit' => self::UNIT,
        ], $this->uid);

        $this->assertSame('ACC-2607-0001', $rec->nomor_referensi);
        $this->assertSame('aktif', $rec->status);

        $entry = JournalEntry::with('lines')->where('sumber_modul', 'Accrue')->where('id_sumber', (string) $rec->id_accrue)->first();
        $this->assertNotNull($entry);
        $this->assertSame(100000.0, (float) $entry->lines->firstWhere('kode_coa', self::BEBAN)->debet);
        $this->assertSame(100000.0, (float) $entry->lines->firstWhere('kode_coa', self::HUTANG)->kredit);
    }

    public function test_jurnal_umum_create_dan_void(): void
    {
        $svc = new JournalService;
        $entry = $svc->create([
            'tanggal' => '2026-07-20', 'keterangan' => 'Jurnal manual', 'id_pengguna' => $this->uid,
            'lines' => [
                ['kode_coa' => self::BEBAN, 'debet' => '80000', 'kredit' => '0'],
                ['kode_coa' => self::KAS1, 'debet' => '0', 'kredit' => '80000'],
            ],
        ]);
        $this->assertSame('JU-2607-0001', $entry->referensi);

        $svc->void($entry->id, ['id_pengguna' => $this->uid]);
        $this->assertSame('void', JournalEntry::find($entry->id)->status);
        $this->assertNotNull(JournalEntry::where('reversal_of', $entry->id)->first());
    }

    public function test_pindah_buku_debit_tujuan_kredit_asal(): void
    {
        $hasil = (new BookTransferService)->create([
            'tanggal' => '2026-07-22', 'kode_rekening_asal' => self::KAS1,
            'kode_rekening_tujuan' => self::KAS2, 'nominal' => '50000',
            'keterangan' => 'Transfer kas', 'kode_unit' => self::UNIT,
        ], $this->uid);

        $this->assertSame('PB-2607-0001', $hasil['referensi']);
        $this->assertSame(self::KAS2, $hasil['kode_rekening_tujuan']);
        $this->assertSame(self::KAS1, $hasil['kode_rekening_asal']);

        $entry = JournalEntry::with('lines')->where('sumber_modul', 'PindahBuku')->first();
        $this->assertSame(50000.0, (float) $entry->lines->firstWhere('kode_coa', self::KAS2)->debet);
        $this->assertSame(50000.0, (float) $entry->lines->firstWhere('kode_coa', self::KAS1)->kredit);
    }
}
