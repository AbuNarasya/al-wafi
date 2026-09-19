<?php

namespace Tests\Feature;

use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Reports\ReportsService;
use App\Support\Money;
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

    /**
     * Dulu test ini menegaskan bahwa arus kas NOL selama tak ada dokumen Kas
     * Masuk/Keluar — dan memang begitulah perilakunya, karena laporannya
     * dibangun dari dokumen. Justru di situ cacatnya: seluruh penerimaan santri
     * memposting jurnalnya sendiri tanpa lewat Kas Masuk, jadi tak pernah
     * terlihat. Sekarang laporannya dibangun dari jurnal, dan test ini
     * membuktikan kebalikannya.
     */
    public function test_arus_kas_membaca_jurnal_bukan_dokumen_kas(): void
    {
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        $ak = (new ReportsService)->arusKas('2026-07-01', '2026-07-31');

        // Tiga jurnal umum di setUp: setoran modal 1jt, pendapatan 500rb,
        // beban 200rb. Seluruhnya menyentuh kas, seluruhnya harus terlihat.
        $this->assertSame('1300000.00', $ak['arus_bersih']);
        $this->assertSame('0.00', $ak['saldo_kas_awal']);
        $this->assertSame('1300000.00', $ak['saldo_kas_akhir']);
        $this->assertTrue($ak['selaras'], 'saldo awal + arus bersih harus sama dengan saldo akhir');
    }

    public function test_arus_kas_mengelompokkan_menurut_klasifikasi_akunnya(): void
    {
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        $per = collect((new ReportsService)->arusKas('2026-07-01', '2026-07-31')['kelompok'])->keyBy('kunci');

        // Pendapatan & beban berklasifikasi `operasi` (diisi migrasi):
        // 500.000 − 200.000 = 300.000. Modal → pendanaan 1.000.000.
        $this->assertSame('300000.00', $per['operasi']['total']);
        $this->assertSame('1000000.00', $per['pendanaan']['total']);
        $this->assertSame('0.00', $per['investasi']['total']);
    }

    public function test_pindah_buku_antar_rekening_kas_tidak_terhitung_sebagai_arus(): void
    {
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        CoaDetail::create(['kode_coa' => '1.1.02', 'nama_coa' => 'Bank', 'kode_grup' => '11', 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => '1.1.02', 'nama_rekening' => 'Bank Uji', 'jenis_rekening' => 'bank', 'status' => 'aktif']);

        $sebelum = (new ReportsService)->arusKas('2026-07-01', '2026-07-31')['arus_bersih'];

        // Kas → Bank: kedua barisnya akun kas, jadi tak menyisakan baris lawan
        // sama sekali dan arus bersihnya tak boleh bergerak sedikit pun.
        PostingService::postJournal(['referensi' => 'JU-PB', 'tanggal' => '2026-07-20', 'sumber_modul' => 'PindahBuku', 'lines' => [
            ['kode_coa' => '1.1.02', 'debet' => '400000', 'kredit' => '0'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '400000'],
        ]]);

        $ak = (new ReportsService)->arusKas('2026-07-01', '2026-07-31');
        $this->assertSame($sebelum, $ak['arus_bersih']);
        $this->assertTrue($ak['selaras']);
    }

    public function test_jembatan_laba_ke_kas_bertemu_dengan_arus_operasi(): void
    {
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        $ak = (new ReportsService)->arusKas('2026-07-01', '2026-07-31');
        $baris = collect($ak['jembatan']['baris']);

        // Baris pertama = laba bersih, baris terakhir = arus kas operasi.
        $this->assertSame('300000.00', $baris->first()['nilai']);
        $this->assertSame('300000.00', $baris->last()['nilai']);

        // Jumlah seluruh baris penyesuaian harus tepat menutup jaraknya.
        $jumlah = $baris->slice(0, 5)->reduce(fn ($s, $b) => Money::add($s, $b['nilai']), '0');
        $this->assertSame($baris->last()['nilai'], Money::of($jumlah));
    }

    public function test_arus_kas_memisahkan_akun_yang_belum_diklasifikasikan(): void
    {
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        // Akun aset lain tanpa klasifikasi — migrasi sengaja membiarkan akun
        // neraca kosong daripada menebak kamarnya.
        CoaDetail::create(['kode_coa' => '1.1.50', 'nama_coa' => 'Uang Muka', 'kode_grup' => '11', 'jenis_saldo' => 'debet']);
        PostingService::postJournal(['referensi' => 'JU-UM', 'tanggal' => '2026-07-25', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => '1.1.50', 'debet' => '250000', 'kredit' => '0'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '250000'],
        ]]);

        $per = collect((new ReportsService)->arusKas('2026-07-01', '2026-07-31')['kelompok'])->keyBy('kunci');

        $this->assertSame('-250000.00', $per['belum']['total']);
        $this->assertSame('1.1.50', $per['belum']['baris'][0]['kode_coa']);
    }

    public function test_halaman_arus_kas_terbuka(): void
    {
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        Level::create(['kode_level' => 'LAK', 'nama_level' => 'LAK', 'max_transaksi' => null]);
        $admin = User::create([
            'username' => 'zzak', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LAK', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.arus_kas', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('Aktivitas Operasi')
            ->assertSee('Jembatan Laba')
            ->assertSee('Selaras dengan Neraca');
    }

    // ---- Neraca Saldo ----

    public function test_neraca_saldo_seimbang_dan_angkanya_benar(): void
    {
        $ns = (new ReportsService)->neracaSaldo('2026-07-01', '2026-07-31');

        $this->assertTrue($ns['seimbang_awal']);
        $this->assertTrue($ns['seimbang_mutasi']);
        $this->assertTrue($ns['seimbang_akhir']);

        // Seluruh jurnal jatuh DI DALAM periode, jadi saldo awal nol.
        $this->assertSame('0.00', $ns['total']['awal_debet']);
        $this->assertSame('0.00', $ns['total']['awal_kredit']);

        // Mutasi = jumlah seluruh sisi debet: 1jt + 500rb + 200rb + 200rb(kredit kas
        // punya pasangan debet beban) → debet 1.700.000, kredit 1.700.000.
        $this->assertSame('1700000.00', $ns['total']['mutasi_debet']);
        $this->assertSame('1700000.00', $ns['total']['mutasi_kredit']);

        // Saldo akhir: kas 1.300.000 (D); modal 1jt + pendapatan 500rb (K) = 1.500.000;
        // beban 200.000 (D) → debet 1.500.000 = kredit 1.500.000.
        $this->assertSame('1500000.00', $ns['total']['akhir_debet']);
        $this->assertSame('1500000.00', $ns['total']['akhir_kredit']);
    }

    public function test_neraca_saldo_menempatkan_akun_pada_sisi_yang_benar(): void
    {
        $ns = (new ReportsService)->neracaSaldo('2026-07-01', '2026-07-31');
        $baris = collect($ns['rows'])->keyBy('kode_coa');

        // Kas: debet-normal, bersaldo → muncul di kolom Debet, kolom Kredit nol.
        $this->assertSame('1300000.00', $baris[self::KAS]['akhir_debet']);
        $this->assertSame('0.00', $baris[self::KAS]['akhir_kredit']);

        // Modal: kredit-normal → sebaliknya.
        $this->assertSame('0.00', $baris[self::MODAL]['akhir_debet']);
        $this->assertSame('1000000.00', $baris[self::MODAL]['akhir_kredit']);

        // Kas bermutasi di kedua sisi; keduanya harus tampil utuh, tidak dipampatkan
        // jadi satu selisih — justru itu bedanya neraca saldo dari buku besar ringkas.
        $this->assertSame('1500000.00', $baris[self::KAS]['mutasi_debet']);
        $this->assertSame('200000.00', $baris[self::KAS]['mutasi_kredit']);
    }

    public function test_neraca_saldo_membawa_saldo_awal_dari_periode_sebelumnya(): void
    {
        // Periode Agustus: seluruh jurnal Juli jadi saldo AWAL, mutasinya nol.
        $ns = (new ReportsService)->neracaSaldo('2026-08-01', '2026-08-31');

        $this->assertSame('1500000.00', $ns['total']['awal_debet']);
        $this->assertSame('1500000.00', $ns['total']['awal_kredit']);
        $this->assertSame('0.00', $ns['total']['mutasi_debet']);
        $this->assertSame('0.00', $ns['total']['mutasi_kredit']);
        $this->assertTrue($ns['seimbang_akhir']);
    }

    public function test_neraca_saldo_menyembunyikan_akun_yang_tak_bergerak(): void
    {
        CoaDetail::create(['kode_coa' => '1.1.99', 'nama_coa' => 'Kas Tak Terpakai', 'kode_grup' => '11', 'jenis_saldo' => 'debet']);

        $ns = (new ReportsService)->neracaSaldo('2026-07-01', '2026-07-31');
        $kode = array_column($ns['rows'], 'kode_coa');

        $this->assertNotContains('1.1.99', $kode);
        $this->assertContains(self::KAS, $kode);
    }

    public function test_halaman_neraca_saldo_terbuka_dan_mencetak_barisnya(): void
    {
        Level::create(['kode_level' => 'LNS', 'nama_level' => 'LNS', 'max_transaksi' => null]);
        $admin = User::create([
            'username' => 'zzns', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LNS', 'is_admin' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.neraca_saldo', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('Seimbang')
            ->assertSee('Beban Operasional')
            ->assertDontSee('Tidak ada akun bersaldo atau bermutasi pada periode ini');
    }
}
