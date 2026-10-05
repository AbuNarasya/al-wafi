<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Dana;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Services\Modules\DanaService;
use App\Services\Reports\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DANA TERIKAT — wakaf, donasi berperuntukan, beasiswa, bantuan.
 *
 * Inti modul ini bukan mencatat penerimaannya (itu sudah bisa sejak dulu lewat
 * Kas Masuk), melainkan MENOLAK belanja di luar peruntukan. Uang wakaf
 * pembangunan tidak boleh terpakai untuk gaji — dan sebelum ini aplikasi tak
 * punya satu pun cara mengatakannya.
 */
class DanaTerikatTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.ZZDN.KAS';

    private const PEND_WAKAF = '4.ZZDN.WKF';

    private const BEBAN_BANGUN = '5.ZZDN.BGN';

    private const BEBAN_GAJI = '5.ZZDN.GAJ';

    private const BAGIAN = 'BZZDN';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => '1', 'nama_grup' => 'Aset', 'level' => 1]);
        CoaGroup::create(['kode_grup' => '4', 'nama_grup' => 'Pendapatan', 'level' => 1]);
        CoaGroup::create(['kode_grup' => '5', 'nama_grup' => 'Beban', 'level' => 1]);
        CoaGroup::create(['kode_grup' => 'G1', 'nama_grup' => 'Kas', 'kode_induk' => '1', 'level' => 3]);
        CoaGroup::create(['kode_grup' => 'G4', 'nama_grup' => 'Pendapatan', 'kode_induk' => '4', 'level' => 3]);
        CoaGroup::create(['kode_grup' => 'G5', 'nama_grup' => 'Beban', 'kode_induk' => '5', 'level' => 3]);

        foreach ([
            [self::KAS, 'Kas', 'debet', 'G1'],
            [self::PEND_WAKAF, 'Penerimaan Wakaf', 'kredit', 'G4'],
            [self::BEBAN_BANGUN, 'Beban Pembangunan', 'debet', 'G5'],
            [self::BEBAN_GAJI, 'Beban Gaji', 'debet', 'G5'],
        ] as [$k, $n, $s, $g]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => $g, 'jenis_saldo' => $s]);
        }

        Bagian::create(['kode_bagian' => self::BAGIAN, 'nama_bagian' => 'Umum', 'level' => 3]);
        Level::create(['kode_level' => 'LDN', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'admdn', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LDN', 'is_admin' => true, 'status' => 'aktif',
        ])->id_pengguna;
    }

    private function danaWakaf(array $akunDiizinkan = [self::BEBAN_BANGUN]): Dana
    {
        $svc = new DanaService;
        $dana = $svc->simpan([
            'kode_dana' => 'WKF001', 'nama_dana' => 'Wakaf Pembangunan Masjid',
            'jenis' => 'terikat_temporer', 'donatur' => 'H. Abdullah',
            'peruntukan' => 'Khusus pembangunan masjid pesantren.',
            'target_nominal' => '500000000', 'status' => 'aktif',
        ]);
        $svc->aturAkun($dana->kode_dana, $akunDiizinkan);

        return $dana->refresh();
    }

    private function terima(string $kodeDana, string $nominal, string $ref = 'JU-D1'): void
    {
        PostingService::postJournal([
            'referensi' => $ref, 'tanggal' => '2026-07-01', 'sumber_modul' => 'JurnalUmum',
            'kode_dana' => $kodeDana,
            'lines' => [
                ['kode_coa' => self::KAS, 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => self::PEND_WAKAF, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);
    }

    private function belanja(string $kodeDana, string $akun, string $nominal, string $ref = 'JU-D2'): void
    {
        PostingService::postJournal([
            'referensi' => $ref, 'tanggal' => '2026-07-10', 'sumber_modul' => 'JurnalUmum',
            'kode_dana' => $kodeDana,
            'lines' => [
                ['kode_coa' => $akun, 'debet' => $nominal, 'kredit' => '0', 'kode_bagian' => self::BAGIAN],
                ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);
    }

    // ---- Penjaga peruntukan ----

    public function test_belanja_di_luar_peruntukan_ditolak(): void
    {
        $this->danaWakaf();

        // Inti seluruh modul: wakaf pembangunan dipakai untuk gaji.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/di luar peruntukan dana/');
        $this->belanja('WKF001', self::BEBAN_GAJI, '10000000');
    }

    public function test_pesan_penolakan_menyebutkan_peruntukannya(): void
    {
        $this->danaWakaf();

        try {
            $this->belanja('WKF001', self::BEBAN_GAJI, '10000000');
            $this->fail('seharusnya ditolak');
        } catch (AppException $e) {
            // Pesan yang hanya berkata "ditolak" memaksa orang menebak; yang
            // menyebutkan peruntukannya mengajari sekaligus menolak.
            $this->assertStringContainsString('pembangunan masjid', $e->getMessage());
            $this->assertStringContainsString('Beban Gaji', $e->getMessage());
        }
    }

    public function test_belanja_sesuai_peruntukan_diterima(): void
    {
        $this->danaWakaf();
        $this->belanja('WKF001', self::BEBAN_BANGUN, '10000000');

        $this->assertSame(1, JournalLine::where('kode_dana', 'WKF001')->where('kode_coa', self::BEBAN_BANGUN)->count());
    }

    public function test_dana_tanpa_daftar_akun_tidak_membatasi_apa_pun(): void
    {
        // Daftar kosong berarti "tanpa pembatasan akun", BUKAN "larang semua" —
        // kalau sebaliknya, dana yang baru dibuat langsung melumpuhkan belanja.
        $this->danaWakaf(akunDiizinkan: []);
        $this->belanja('WKF001', self::BEBAN_GAJI, '10000000');

        $this->assertSame(1, JournalLine::where('kode_dana', 'WKF001')->where('kode_coa', self::BEBAN_GAJI)->count());
    }

    public function test_baris_bukan_beban_tak_ikut_dibatasi(): void
    {
        // Kas & pendapatan dana terikat menumpang akun yang sama dengan dana
        // lain. Membatasinya hanya akan menolak penerimaan yang justru sah.
        $this->danaWakaf();
        $this->terima('WKF001', '100000000');

        $this->assertSame(1, JournalLine::where('kode_dana', 'WKF001')->where('kode_coa', self::KAS)->count());
    }

    public function test_dana_nonaktif_ditolak(): void
    {
        $dana = $this->danaWakaf();
        $dana->update(['status' => 'nonaktif']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/berstatus nonaktif/');
        $this->belanja('WKF001', self::BEBAN_BANGUN, '1000000');
    }

    public function test_dana_di_kepala_dokumen_menurun_ke_semua_barisnya(): void
    {
        $this->danaWakaf();
        $this->terima('WKF001', '50000000');

        // Dua baris, keduanya bertanda dana yang sama tanpa disebut per baris.
        $this->assertSame(2, JournalLine::where('kode_dana', 'WKF001')->count());
    }

    // ---- Master ----

    public function test_dana_terikat_wajib_menyebut_peruntukan(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/wajib menyebutkan peruntukannya/');
        (new DanaService)->simpan([
            'kode_dana' => 'WKF002', 'nama_dana' => 'Wakaf Tanpa Keterangan',
            'jenis' => 'terikat_permanen', 'peruntukan' => '',
        ]);
    }

    public function test_dana_yang_sudah_dipakai_di_jurnal_tak_bisa_dihapus(): void
    {
        $this->danaWakaf();
        $this->terima('WKF001', '10000000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/bagian riwayat pembukuan/');
        (new DanaService)->hapus('WKF001');
    }

    // ---- Laporan pertanggungjawaban ----

    public function test_laporan_menghitung_diterima_terpakai_dan_sisa(): void
    {
        $this->danaWakaf();
        $this->terima('WKF001', '100000000');
        $this->belanja('WKF001', self::BEBAN_BANGUN, '30000000');

        $baris = collect((new DanaService)->laporan()['baris'])->firstWhere('kode_dana', 'WKF001');

        $this->assertSame('100000000.00', $baris['diterima']);
        $this->assertSame('30000000.00', $baris['terpakai']);
        $this->assertSame('70000000.00', $baris['sisa']);
        $this->assertFalse($baris['defisit']);
    }

    public function test_laporan_menandai_dana_yang_terpakai_melebihi_penerimaannya(): void
    {
        // Kasnya bercampur di rekening yang sama, jadi belanja melebihi
        // penerimaan tak pernah tertahan sendirinya — laporan ini yang harus
        // menyebutnya.
        $this->danaWakaf();
        $this->terima('WKF001', '10000000');
        $this->belanja('WKF001', self::BEBAN_BANGUN, '25000000');

        $baris = collect((new DanaService)->laporan()['baris'])->firstWhere('kode_dana', 'WKF001');

        $this->assertSame('-15000000.00', $baris['sisa']);
        $this->assertTrue($baris['defisit']);
    }

    public function test_jurnal_tanpa_tanda_dana_tak_terhitung_di_laporan(): void
    {
        $this->danaWakaf();

        PostingService::postJournal([
            'referensi' => 'JU-LEPAS', 'tanggal' => '2026-07-05', 'sumber_modul' => 'JurnalUmum',
            'lines' => [
                ['kode_coa' => self::KAS, 'debet' => '99000000', 'kredit' => '0'],
                ['kode_coa' => self::PEND_WAKAF, 'debet' => '0', 'kredit' => '99000000'],
            ],
        ]);

        $baris = collect((new DanaService)->laporan()['baris'])->firstWhere('kode_dana', 'WKF001');
        $this->assertSame('0.00', $baris['diterima']);
    }

    // ---- Void ----

    /**
     * Belanja dari dana wakaf yang dibatalkan harus HILANG dari laporan donatur.
     *
     * Dulu jurnal pembalik menyalin akun, bagian, dan unit — tetapi tidak
     * `kode_dana`. Baris aslinya tetap bertanda wakaf, pembaliknya tidak, jadi
     * laporan dana yang menyaring `kode_dana` hanya melihat belanjanya: uang
     * yang tak jadi dibelanjakan tetap tercatat "terpakai" di depan donatur.
     */
    public function test_belanja_dana_yang_di_void_hilang_dari_laporan_dana(): void
    {
        $this->danaWakaf();
        $this->terima('WKF001', '100000000');
        $this->belanja('WKF001', self::BEBAN_BANGUN, '30000000');

        $belanja = JournalEntry::where('referensi', 'JU-D2')->firstOrFail();
        $pembalik = ReversalService::reverseJournalEntry($belanja->id, ['tanggal' => '2026-07-20']);

        // Setiap baris pembalik membawa tanda dana yang sama dengan baris aslinya.
        foreach ($pembalik->lines as $l) {
            $this->assertSame('WKF001', $l->kode_dana, "baris {$l->kode_coa}");
        }

        $baris = collect((new DanaService)->laporan()['baris'])->firstWhere('kode_dana', 'WKF001');
        $this->assertSame('100000000.00', $baris['diterima']);
        $this->assertSame('0.00', $baris['terpakai'], 'Belanja yang dibatalkan tak boleh terhitung terpakai.');
        $this->assertSame('100000000.00', $baris['sisa']);

        // Laporan Perubahan Aset Neto (ISAK 35) membaca penanda yang sama.
        $neto = (new ReportsService)->perubahanAsetNeto('2026-07-01', '2026-07-31');
        $this->assertSame('0.00', $neto['pelepasan']);
    }

    // ---- Layar ----

    public function test_halaman_dana_dan_laporannya_terbuka(): void
    {
        $this->danaWakaf();
        $this->terima('WKF001', '100000000');

        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('dana.index'))->assertOk()
            ->assertSee('Wakaf Pembangunan Masjid')
            ->assertSee('Terikat Temporer');

        $this->actingAs($admin)->get(route('dana.laporan'))->assertOk()
            ->assertSee('Laporan Pertanggungjawaban Dana')
            ->assertSee('H. Abdullah');
    }
}
