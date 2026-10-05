<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Modules\DashboardService;
use App\Services\Modules\PeriodCloseService;
use App\Services\Reports\ReportsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MEMBUKA KEMBALI TUTUP BUKU TAHUNAN.
 *
 * Jurnal tutup buku tahunan bertanggal 31 Desember. Dulu pembaliknya dibuat
 * tanpa tanggal — jadi bertanggal HARI INI. Membuka tahun 2025 pada Oktober
 * 2026 berarti: pendapatan & beban 2025 tetap nol (jurnal penutupnya masih
 * berlaku di 2025), sementara seluruh laba rugi 2025 muncul lagi di 2026.
 * Laporan dua tahun sekaligus salah, tanpa satu pun pesan galat.
 */
class BukaTutupBukuTahunanTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.ZZBT.KAS';

    private const PEND = '4.ZZBT.SPP';

    private const BEBAN = '5.ZZBT.GAJ';

    private const LABA_DITAHAN = '3.ZZBT.LDT';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['1', 'Aset'], ['3', 'Ekuitas'], ['4', 'Pendapatan'], ['5', 'Beban']] as [$k, $n]) {
            CoaGroup::create(['kode_grup' => $k, 'nama_grup' => $n, 'level' => 1]);
        }
        foreach ([['G1', '1'], ['G3', '3'], ['G4', '4'], ['G5', '5']] as [$k, $induk]) {
            CoaGroup::create(['kode_grup' => $k, 'nama_grup' => "Grup {$k}", 'kode_induk' => $induk, 'level' => 3]);
        }
        foreach ([
            [self::KAS, 'Kas', 'debet', 'G1'],
            [self::LABA_DITAHAN, 'Laba Ditahan', 'kredit', 'G3'],
            [self::PEND, 'Pendapatan SPP', 'kredit', 'G4'],
            [self::BEBAN, 'Beban Gaji', 'debet', 'G5'],
        ] as [$k, $n, $s, $g]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => $g, 'jenis_saldo' => $s]);
        }
        Bagian::create(['kode_bagian' => 'BBT', 'nama_bagian' => 'Umum', 'level' => 3]);
        Level::create(['kode_level' => 'LBT', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'admbt', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LBT', 'is_admin' => true, 'status' => 'aktif', 'tim_keuangan' => true,
        ]);

        // Satu tahun buku 2025: pendapatan 10 juta, beban 4 juta → laba 6 juta.
        PostingService::postJournal(['referensi' => 'JU-BT-1', 'tanggal' => '2025-06-15', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '10000000', 'kredit' => '0'],
            ['kode_coa' => self::PEND, 'debet' => '0', 'kredit' => '10000000'],
        ]]);
        PostingService::postJournal(['referensi' => 'JU-BT-2', 'tanggal' => '2025-07-15', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::BEBAN, 'debet' => '4000000', 'kredit' => '0', 'kode_bagian' => 'BBT'],
            ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '4000000'],
        ]]);
    }

    private function tutupTahunDanDesember(): void
    {
        $svc = new PeriodCloseService;
        $svc->tutupTahun(2025, self::LABA_DITAHAN, $this->admin->id_pengguna);
        $svc->tutupBulan(2025, 12, $this->admin->id_pengguna, $this->admin->nama, 'tutup akhir tahun');
    }

    public function test_pembalik_bertanggal_31_desember_bukan_hari_ini(): void
    {
        $this->tutupTahunDanDesember();

        // Tahun 2025 dibuka kembali hampir setahun kemudian.
        Carbon::setTestNow('2026-10-05 10:00:00');
        (new PeriodCloseService)->bukaTahun(2025, $this->admin->id_pengguna, lewatPersetujuan: true);
        Carbon::setTestNow();

        $penutup = JournalEntry::where('sumber_modul', 'TutupBuku')->whereNull('reversal_of')->firstOrFail();
        $pembalik = JournalEntry::where('reversal_of', $penutup->id)->firstOrFail();
        $this->assertSame('2025-12-31', Carbon::parse($pembalik->tanggal)->toDateString());
        $this->assertSame('void', $penutup->refresh()->status);

        $laporan = new ReportsService;
        // Laba rugi 2025 kembali utuh …
        $this->assertSame('6000000.00', $laporan->labaRugi('2025-01-01', '2025-12-31')['laba_rugi_bersih']);
        // … dan tidak ada sepeser pun yang menyeberang ke 2026.
        $this->assertSame('0.00', $laporan->labaRugi('2026-01-01', '2026-12-31')['laba_rugi_bersih']);
    }

    public function test_desember_yang_terkunci_ikut_dibuka_dan_tercatat(): void
    {
        $this->tutupTahunDanDesember();
        $this->assertSame('closed', AccountingPeriod::where('tahun', 2025)->where('bulan', 12)->value('status'));

        (new PeriodCloseService)->bukaTahun(2025, $this->admin->id_pengguna, lewatPersetujuan: true);

        // Satu persetujuan buka tahun = Desember ikut terbuka, supaya pembaliknya
        // bisa bertanggal 31 Desember. Bulan lain tak disentuh.
        $this->assertSame('open', AccountingPeriod::where('tahun', 2025)->where('bulan', 12)->value('status'));
        $this->assertTrue(
            ActivityLog::where('aksi', 'buka_bulan')->where('detail', 'like', '%"bulan":12%')->exists(),
            'Pembukaan Desember harus meninggalkan jejak sendiri, bukan diam-diam.'
        );
    }

    /**
     * Jurnal tutup buku memindahkan saldo pendapatan & beban ke Laba Ditahan —
     * ia bukan transaksi. Dulu Laba Rugi ikut menghitungnya, sehingga laba
     * setahun penuh terbaca NOL begitu tahunnya ditutup.
     */
    public function test_laba_rugi_tahun_yang_sudah_ditutup_tetap_utuh(): void
    {
        $this->tutupTahunDanDesember();
        $laporan = new ReportsService;

        $lr = $laporan->labaRugi('2025-01-01', '2025-12-31');
        $this->assertSame('10000000.00', $lr['total_pendapatan']);
        $this->assertSame('4000000.00', $lr['total_beban']);
        $this->assertSame('6000000.00', $lr['laba_rugi_bersih']);

        // Laba Rugi Desember saja pun tak boleh negatif karena jurnal penutup.
        $this->assertSame('0.00', $laporan->labaRugi('2025-12-01', '2025-12-31')['laba_rugi_bersih']);

        // Perubahan Aset Neto (ISAK 35) membaca pendapatan & beban yang sama.
        $this->assertSame('6000000.00', $laporan->perubahanAsetNeto('2025-01-01', '2025-12-31')['kenaikan']['jumlah']);

        // Kartu Laba Rugi per Unit di dashboard.
        $dash = new DashboardService;
        $laba = collect($dash->labaRugiUnit($dash->lines(), [])['total'])->reduce(
            fn ($t, $r) => Money::add($t, $r['laba']), '0');
        $this->assertSame('6000000.00', Money::of($laba));
    }

    /**
     * Penjaga sisi sebaliknya: NERACA justru harus memuat jurnal penutup. Di
     * sanalah laba pindah ke Laba Ditahan; mengecualikannya membuat laba
     * terhitung dua kali (laba berjalan + laba ditahan).
     */
    public function test_neraca_sesudah_tutup_buku_memindahkan_laba_tanpa_dobel(): void
    {
        $this->tutupTahunDanDesember();

        $neraca = (new ReportsService)->neraca('2025-12-31');
        $this->assertSame('6000000.00', $neraca['total_aset']);
        $this->assertSame('0.00', $neraca['ekuitas']['laba_berjalan'], 'Laba sudah pindah ke Laba Ditahan.');
        $this->assertSame('6000000.00', $neraca['total_ekuitas']);
        $this->assertTrue($neraca['balanced']);
    }

    public function test_tahun_yang_desembernya_terbuka_tetap_bisa_dibuka(): void
    {
        (new PeriodCloseService)->tutupTahun(2025, self::LABA_DITAHAN, $this->admin->id_pengguna);

        (new PeriodCloseService)->bukaTahun(2025, $this->admin->id_pengguna, lewatPersetujuan: true);

        $this->assertSame('6000000.00', (new ReportsService)->labaRugi('2025-01-01', '2025-12-31')['laba_rugi_bersih']);
        $this->assertFalse(ActivityLog::where('aksi', 'buka_bulan')->exists(), 'Desember yang sudah terbuka tak perlu "dibuka" lagi.');
    }
}
