<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UNIT PENAMPUNG NERACA — hutang & kas dibukukan ke SATU unit, modul mana pun
 * asalnya; beban & aset tetap mengikuti unit yang mengajukan.
 *
 * Asasnya: unit bisnis adalah PUSAT LABA. `ReportsService::neraca()` bahkan tak
 * menerima parameter unit — neraca hanya bermakna di tingkat yayasan. Dimensi
 * unit pada baris hutang selama ini dipelihara tanpa ada laporan yang
 * membacanya, dan justru dimensi itulah yang membuat pembayaran SEBAGIAN
 * mustahil tanpa memprorata porsi tiap unit.
 *
 * Yang dijaga di sini bukan sekadar "kolomnya terisi benar", melainkan tiga
 * batas yang mudah tergeser tanpa terlihat: aset tetap TIDAK ikut terseret,
 * pengaturan kosong TIDAK mengubah apa pun, dan laba rugi per unit tetap utuh.
 */
class UnitPenampungNeracaTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZUP';

    private const KAS = '1.ZZUP.1';

    private const ASET = '1.ZZUP.9';

    private const HUTANG = '2.ZZUP.1';

    private const BEBAN = '5.ZZUP.1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzup_adm', 'nama' => 'Admin UP', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        // Akun beban wajib berbagian (validateBagian) — bukan bagian dari yang
        // diuji di sini, tapi tanpanya postJournal menolak sebelum sampai ke
        // penentuan unit.
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian Uji', 'level' => 3]);

        BusinessUnit::create(['kode_unit' => 'UA', 'nama_unit' => 'Unit A', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'UB', 'nama_unit' => 'Unit B', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'YYS', 'nama_unit' => 'Yayasan', 'status' => 'aktif']);

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji Penampung']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Bank Operasional', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::ASET, 'nama_coa' => 'Kendaraan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Usaha', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Bank Operasional', 'jenis_rekening' => 'bank', 'status' => 'aktif']);
    }

    private function setPenampung(?string $kodeUnit): void
    {
        CompanySettings::updateOrCreate(['id' => 1], [
            'nama_perusahaan' => 'Uji', 'jenis_perusahaan' => 'Yayasan', 'mata_uang' => 'IDR',
            'periode_awal_pembukuan' => '2026-01-01', 'bulan_awal_anggaran' => 1,
            'kode_unit_neraca' => $kodeUnit,
        ]);
        PostingService::lupakanKonteksNeraca();
    }

    /** @param list<array<string,mixed>> $lines */
    private function posting(array $lines, ?string $unitDokumen = 'UA', string $ref = 'UJI/1'): void
    {
        PostingService::postJournal([
            'referensi' => $ref, 'tanggal' => '2026-08-05', 'kode_unit' => $unitDokumen,
            'sumber_modul' => 'Uji', 'id_pengguna' => $this->admin->id_pengguna,
            'keterangan' => 'uji penampung', 'lines' => $lines,
        ]);
    }

    private function unitDari(string $kodeCoa): ?string
    {
        return JournalLine::where('kode_coa', $kodeCoa)->value('kode_unit');
    }

    /** Beban di unit peminta, hutangnya di penampung. Inilah bentuk barunya. */
    public function test_hutang_pindah_ke_penampung_beban_tetap_di_unit_peminta(): void
    {
        $this->setPenampung('YYS');

        $this->posting([
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '100000', 'kredit' => '0', 'kode_unit' => 'UA'],
            ['kode_coa' => self::HUTANG, 'debet' => '0', 'kredit' => '100000', 'kode_unit' => 'UA'],
        ]);

        $this->assertSame('UA', $this->unitDari(self::BEBAN), 'beban wajib tetap di unit peminta');
        $this->assertSame('YYS', $this->unitDari(self::HUTANG), 'hutang wajib pindah ke penampung');
    }

    /** Kas dikenali dari tabel rekening, bukan dari awalan kode akunnya. */
    public function test_kas_pindah_ke_penampung(): void
    {
        $this->setPenampung('YYS');

        $this->posting([
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '100000', 'kredit' => '0'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '100000'],
        ], 'UB');

        $this->assertSame('UB', $this->unitDari(self::BEBAN));
        $this->assertSame('YYS', $this->unitDari(self::KAS));
    }

    /**
     * ASET TETAP TIDAK IKUT. Ia neraca, tapi awalannya sama dengan kas ("1") —
     * kalau aturannya memakai awalan kode akun, kendaraan milik Unit A akan
     * diam-diam berpindah ke yayasan dan daftar aset per unit jadi kosong.
     */
    public function test_aset_tetap_tidak_ikut_pindah(): void
    {
        $this->setPenampung('YYS');

        $this->posting([
            ['kode_coa' => self::ASET, 'debet' => '5000000', 'kredit' => '0', 'kode_unit' => 'UA'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '5000000', 'kode_unit' => 'UA'],
        ]);

        $this->assertSame('UA', $this->unitDari(self::ASET), 'aset tetap wajib tetap di unitnya');
        $this->assertSame('YYS', $this->unitDari(self::KAS));
    }

    /** Pengaturan kosong = perilaku lama, persis. Migrasinya tak boleh mengubah apa pun sendiri. */
    public function test_tanpa_pengaturan_semuanya_seperti_dulu(): void
    {
        $this->setPenampung(null);

        $this->posting([
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '100000', 'kredit' => '0'],
            ['kode_coa' => self::HUTANG, 'debet' => '0', 'kredit' => '100000'],
        ], 'UA');

        $this->assertSame('UA', $this->unitDari(self::BEBAN));
        $this->assertSame('UA', $this->unitDari(self::HUTANG), 'tanpa penampung, hutang tetap ikut unit dokumen');
    }

    /**
     * Dua unit dalam satu dokumen — bentuk yang dulu memaksa prorata.
     * Bebannya tetap terpisah per unit (laba rugi per unit utuh), hutangnya
     * berkumpul di satu unit sehingga pelunasan sebagian tak perlu dibagi.
     */
    public function test_dokumen_dua_unit_bebannya_terpisah_hutangnya_menyatu(): void
    {
        $this->setPenampung('YYS');

        $this->posting([
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '60000', 'kredit' => '0', 'kode_unit' => 'UA'],
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '40000', 'kredit' => '0', 'kode_unit' => 'UB'],
            ['kode_coa' => self::HUTANG, 'debet' => '0', 'kredit' => '100000', 'kode_unit' => 'UA'],
        ]);

        $beban = JournalLine::where('kode_coa', self::BEBAN)->pluck('kode_unit')->sort()->values()->all();
        $this->assertSame(['UA', 'UB'], $beban, 'laba rugi per unit tak boleh ikut berubah');

        $hutang = JournalLine::where('kode_coa', self::HUTANG)->pluck('kode_unit')->unique()->values()->all();
        $this->assertSame(['YYS'], $hutang);
    }

    /**
     * Cache konteks dibuang saat pengaturannya berubah. Tanpa ini, jurnal yang
     * diposting sesudah admin menyimpan pengaturan masih memakai unit lama —
     * dan tak ada yang menyadarinya sampai neraca per unit terlihat aneh.
     */
    public function test_pengaturan_yang_berubah_langsung_berlaku(): void
    {
        $this->setPenampung(null);
        $this->posting([
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '1000', 'kredit' => '0'],
            ['kode_coa' => self::HUTANG, 'debet' => '0', 'kredit' => '1000'],
        ], 'UA', 'UJI/LAMA');
        $this->assertSame('UA', $this->unitDari(self::HUTANG));

        $this->setPenampung('YYS');
        $this->posting([
            ['kode_coa' => self::BEBAN, 'kode_bagian' => 'B1', 'debet' => '2000', 'kredit' => '0'],
            ['kode_coa' => self::HUTANG, 'debet' => '0', 'kredit' => '2000'],
        ], 'UA', 'UJI/BARU');

        $unitBaru = JournalLine::where('kode_coa', self::HUTANG)
            ->whereHas('entry', fn ($q) => $q->where('referensi', 'UJI/BARU'))->value('kode_unit');
        $this->assertSame('YYS', $unitBaru);
    }
}
