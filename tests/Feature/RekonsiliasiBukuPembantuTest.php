<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Inventory;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Ledger\PostingService;
use App\Services\Modules\JournalService;
use App\Services\Modules\PersediaanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use App\Services\Ppsb\DompetPolicy;
use App\Services\Reports\RekonsiliasiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Rekonsiliasi buku pembantu ↔ buku besar.
 *
 * Yang diuji bukan sekadar "halaman terbuka", melainkan bahwa laporan ini
 * benar-benar MENANGKAP selisih. Sebuah laporan kontrol yang selalu berkata
 * "cocok" lebih berbahaya daripada tidak ada laporan sama sekali — ia memberi
 * rasa aman tanpa dasar. Karena itu tiap pos diuji dua kali: saat seimbang, dan
 * setelah sengaja dirusak.
 */
class RekonsiliasiBukuPembantuTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZRK';

    private const PEND = '4.ZZRK.PEND';

    private const PIUT = '1.ZZRK.PIUT';

    private const KAS = '1.ZZRK.KAS';

    private const HUTANG = '2.ZZRK.HUT';

    private const PERSEDIAAN = '1.ZZRK.PRS';

    private const BEBAN = '5.ZZRK.BBN';

    private const UNIT = 'ZZRKU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Rekonsiliasi']);
        foreach ([
            [self::PEND, 'Pendapatan SPP', 'kredit'],
            [self::PIUT, 'Piutang Santri', 'debet'],
            [self::KAS, 'Kas', 'debet'],
            [self::HUTANG, 'Hutang Usaha', 'kredit'],
            [self::PERSEDIAAN, 'Persediaan', 'debet'],
            [self::BEBAN, 'Beban Pemakaian', 'debet'],
            [DompetPolicy::COA_TITIPAN['wali'], 'Titipan Dompet Wali', 'kredit'],
            [DompetPolicy::COA_TITIPAN['santri'], 'Titipan Dompet Santri', 'kredit'],
            [DompetPolicy::COA_TITIPAN['tabungan'], 'Titipan Tabungan Santri', 'kredit'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        VendorType::create(['kode_jenis_vendor' => 'JZZRK', 'nama' => 'Umum']);
        Vendor::create([
            'kode_vendor' => 'VZZRK', 'nama_vendor' => 'Vendor Uji',
            'kode_jenis_vendor' => 'JZZRK', 'status' => 'aktif',
        ]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Bagian::create(['kode_bagian' => 'BRK', 'nama_bagian' => 'Umum', 'level' => 3]);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);

        $this->admin = User::create([
            'username' => 'admrk', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '250000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'berulang' => true]);
    }

    private function santriAktif(string $nama = 'Ahmad'): Santri
    {
        $wali = (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Budi',
            'telepon_ayah' => '08'.random_int(100000, 999999),
        ]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tingkat' => 1]);

        return $santri->refresh();
    }

    /** @return array<string,array> baris hasil, dikunci `kunci` */
    private function baris(): array
    {
        return collect((new RekonsiliasiService)->ringkasan(now()->toDateString())['baris'])
            ->keyBy('kunci')->all();
    }

    // ---- Piutang santri ----

    public function test_piutang_santri_cocok_setelah_spp_terbit(): void
    {
        $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);

        $b = $this->baris()['piutang-santri'];

        $this->assertSame('250000.00', $b['saldo_pembantu']);
        $this->assertSame('250000.00', $b['saldo_buku_besar']);
        $this->assertTrue($b['cocok']);
    }

    public function test_piutang_santri_berselisih_bila_sisa_tagihan_diubah_tanpa_jurnal(): void
    {
        $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);

        // Persis kecelakaan yang hendak ditangkap laporan ini: angka di buku
        // pembantu diubah langsung, buku besarnya tak ikut bergerak.
        TagihanSantri::where('kode_jenis', 'SPP-SD')->update(['sisa' => '100000']);

        $b = $this->baris()['piutang-santri'];

        $this->assertFalse($b['cocok']);
        $this->assertSame('-150000.00', $b['selisih']);
    }

    // ---- Hutang vendor ----

    public function test_hutang_vendor_cocok_dan_mengabaikan_invoice_void(): void
    {
        $this->invoiceMentah('INV-1', '750000', 'belum_bayar');
        $this->invoiceMentah('INV-2', '400000', 'void');

        PostingService::postJournal([
            'referensi' => 'JU-INV', 'tanggal' => now()->toDateString(), 'sumber_modul' => 'JurnalUmum',
            'lines' => [
                ['kode_coa' => self::PERSEDIAAN, 'debet' => '750000', 'kredit' => '0'],
                ['kode_coa' => self::HUTANG, 'debet' => '0', 'kredit' => '750000'],
            ],
        ]);

        $b = $this->baris()['hutang-vendor'];

        // Invoice void tidak ikut dihitung — kalau ikut, pembantunya 1.150.000.
        $this->assertSame('750000.00', $b['saldo_pembantu']);
        $this->assertSame('750000.00', $b['saldo_buku_besar']);
        $this->assertTrue($b['cocok']);
    }

    /** Invoice ditulis langsung ke tabel: yang diuji rekonsiliasinya, bukan InvoiceService. */
    private function invoiceMentah(string $nomor, string $sisa, string $status): void
    {
        DB::table('invoices')->insert([
            'nomor_invoice' => $nomor, 'tanggal_invoice' => now()->toDateString(), 'tanggal_jatuh_tempo' => now()->addDays(30)->toDateString(),
            'kode_vendor' => 'VZZRK', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'keterangan' => 'Uji', 'total' => $sisa, 'sisa_hutang' => $sisa,
            'status' => $status, 'id_pengguna' => $this->admin,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- Titipan dompet ----

    /** Dompet santri baru dibuat saat pertama dipakai, jadi fixture menaruhnya sendiri. */
    private function dompetSantri(int $idSantri, string $saldo): void
    {
        DB::table('dompet_santri')->updateOrInsert(
            ['id_santri' => $idSantri],
            ['saldo' => $saldo, 'kunci_tarik' => false, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function test_titipan_dompet_berselisih_bila_saldo_diubah_tanpa_jurnal(): void
    {
        $santri = $this->santriAktif();
        $this->dompetSantri($santri->id, '300000');

        $b = $this->baris()['titipan-santri'];

        $this->assertSame('300000.00', $b['saldo_pembantu']);
        $this->assertSame('0.00', $b['saldo_buku_besar']);
        $this->assertFalse($b['cocok']);
    }

    // ---- Persediaan ----

    /**
     * Dulu test ini membuktikan bahwa mutasi manual MERUSAK kecocokan — itu
     * memang perilakunya sebelum persediaan dibenahi. Sekarang ia membuktikan
     * kebalikannya: pemakaian barang ikut berjurnal, jadi buku pembantu dan
     * buku besar tetap sejalan.
     */
    public function test_persediaan_tetap_cocok_setelah_pembelian_dan_pemakaian(): void
    {
        $item = Inventory::create([
            'kode_persediaan' => 'BRG0001', 'nama_persediaan' => 'Beras', 'satuan' => 'kg',
            'harga_perolehan' => '0', 'kode_coa' => self::PERSEDIAAN,
            'kode_coa_beban' => self::BEBAN, 'kode_coa_selisih' => self::BEBAN, 'status' => 'aktif',
        ]);

        // Pembelian 100 kg @12.000 lewat jurnal umum (pintu yang sudah ada).
        (new JournalService)->create([
            'tanggal' => now()->toDateString(),
            'keterangan' => 'Pembelian beras',
            'id_pengguna' => $this->admin,
            'lines' => [
                ['kode_coa' => self::PERSEDIAAN, 'debet' => '1200000', 'kredit' => '0',
                    'kode_persediaan' => $item->kode_persediaan, 'kuantiti' => '100'],
                ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '1200000'],
            ],
        ]);

        $this->assertTrue($this->baris()['persediaan']['cocok']);
        $this->assertSame('1200000.00', $this->baris()['persediaan']['saldo_pembantu']);

        // Pemakaian 10 kg — kini BERJURNAL (D Beban / K Persediaan).
        (new PersediaanService)->pemakaian([
            'kode_persediaan' => $item->kode_persediaan,
            'jumlah' => '10',
            'tanggal' => now()->toDateString(),
            'kode_bagian' => 'BRK',
        ], $this->admin);

        $b = $this->baris()['persediaan'];
        $this->assertTrue($b['cocok']);
        $this->assertSame('1080000.00', $b['saldo_pembantu']);
        $this->assertSame('1080000.00', $b['saldo_buku_besar']);
    }

    // ---- Halaman ----

    public function test_halaman_rekonsiliasi_terbuka_dan_menyebut_selisihnya(): void
    {
        $santri = $this->santriAktif();
        $this->dompetSantri($santri->id, '50000');

        $this->actingAs(User::find($this->admin))
            ->get(route('kontrol.rekonsiliasi'))
            ->assertOk()
            ->assertSee('Rekonsiliasi Buku Pembantu')
            ->assertSee('Titipan — Dompet Santri')
            ->assertSee('pos berselisih');
    }

    public function test_pos_tanpa_akun_ditandai_bukan_dihitung_sebagai_selisih_biasa(): void
    {
        // Tak ada satu pun barang persediaan → tak ada akun yang ditunjuk.
        $b = $this->baris()['persediaan'];

        $this->assertSame([], $b['akun']);
        $this->assertStringContainsString('Belum ada akun buku besar', $b['catatan']);
    }
}
