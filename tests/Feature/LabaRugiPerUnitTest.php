<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Reports\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laba Rugi per unit bisnis.
 *
 * Dimensi unit melekat di BARIS jurnal — PostingService menyalinnya dari kepala
 * transaksi ke tiap baris. Test ini mengunci dua hal: penyaringannya benar, dan
 * baris yang TIDAK berunit tidak diam-diam hilang tanpa pemberitahuan.
 */
class LabaRugiPerUnitTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZLR';
    private const PENDAPATAN = '4.ZZLR.1';
    private const BEBAN = '5.ZZLR.1';
    private const KAS = '1.ZZLR.1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzlr_admin', 'nama' => 'Admin LR', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        BusinessUnit::create(['kode_unit' => 'UA', 'nama_unit' => 'Unit A', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'UB', 'nama_unit' => 'Unit B', 'status' => 'aktif']);

        // Grup root 4 & 5 harus ada supaya akunnya dikenali sebagai pendapatan/beban.
        CoaGroup::create(['kode_grup' => '4', 'nama_grup' => 'Pendapatan']);
        CoaGroup::create(['kode_grup' => '5', 'nama_grup' => 'Beban']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Kas LR']);
        CoaGroup::create(['kode_grup' => self::GRP.'4', 'nama_grup' => 'Pendapatan LR', 'kode_induk' => '4']);
        CoaGroup::create(['kode_grup' => self::GRP.'5', 'nama_grup' => 'Beban LR', 'kode_induk' => '5']);

        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Lain', 'kode_grup' => self::GRP.'4', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban Operasional', 'kode_grup' => self::GRP.'5', 'jenis_saldo' => 'debet']);
    }

    /** Pendapatan masuk di unit tertentu (unit diambil dari kepala transaksi). */
    private function pendapatan(string $nominal, ?string $unit, string $ref): void
    {
        PostingService::postJournal([
            'referensi' => $ref, 'tanggal' => '2026-03-10', 'kode_unit' => $unit,
            'sumber_modul' => 'JurnalUmum', 'id_sumber' => $ref, 'id_pengguna' => $this->admin->id_pengguna,
            'keterangan' => 'uji', 'lines' => [
                ['kode_coa' => self::KAS, 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => self::PENDAPATAN, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);
    }

    public function test_unit_menempel_di_setiap_baris_jurnal(): void
    {
        $this->pendapatan('1000000', 'UA', 'JU-1');

        // Bukan hanya di kepala transaksi — penyaringan laporan bersandar pada baris.
        $baris = \App\Models\JournalLine::all();
        $this->assertCount(2, $baris);
        $this->assertSame(['UA', 'UA'], $baris->pluck('kode_unit')->all());
    }

    public function test_laba_rugi_tersaring_per_unit(): void
    {
        $this->pendapatan('1000000', 'UA', 'JU-1');
        $this->pendapatan('400000', 'UB', 'JU-2');

        $svc = new ReportsService;
        $semua = $svc->labaRugi('2026-01-01', '2026-12-31');
        $ua = $svc->labaRugi('2026-01-01', '2026-12-31', 'UA');
        $ub = $svc->labaRugi('2026-01-01', '2026-12-31', 'UB');

        $this->assertSame(1400000.0, (float) $semua['total_pendapatan']);
        $this->assertSame(1000000.0, (float) $ua['total_pendapatan']);
        $this->assertSame(400000.0, (float) $ub['total_pendapatan']);
        $this->assertSame('UA', $ua['kode_unit']);

        // Tanpa baris tanpa-unit, jumlah semua unit = keseluruhan.
        $this->assertSame(
            (float) $semua['total_pendapatan'],
            (float) $ua['total_pendapatan'] + (float) $ub['total_pendapatan'],
        );
    }

    public function test_baris_tanpa_unit_dilaporkan_bukan_disembunyikan(): void
    {
        $this->pendapatan('1000000', 'UA', 'JU-1');
        $this->pendapatan('250000', null, 'JU-2'); // tak berunit

        $svc = new ReportsService;
        $ua = $svc->labaRugi('2026-01-01', '2026-12-31', 'UA');

        // Yang tak berunit TIDAK ikut ke unit mana pun…
        $this->assertSame(1000000.0, (float) $ua['total_pendapatan']);
        // …tetapi nilainya dilaporkan supaya pembaca tahu ada yang tercecer.
        // 250.000 muncul di dua sisi? Tidak — hanya akun pendapatan yang dihitung.
        $this->assertSame(250000.0, (float) $ua['tanpa_unit']);
    }

    public function test_buku_besar_ikut_tersaring_supaya_cocok_dengan_laba_rugi(): void
    {
        $this->pendapatan('1000000', 'UA', 'JU-1');
        $this->pendapatan('400000', 'UB', 'JU-2');

        $bb = (new ReportsService)->bukuBesar(self::PENDAPATAN, '2026-01-01', '2026-12-31', 'UA');

        $this->assertCount(1, $bb['mutasi']);
        $this->assertSame(1000000.0, (float) $bb['mutasi'][0]['kredit']);
        $this->assertSame('UA', $bb['kode_unit']);
    }

    public function test_halaman_laba_rugi_menyediakan_pilihan_unit_dan_menyaring(): void
    {
        $this->pendapatan('1000000', 'UA', 'JU-1');
        $this->pendapatan('400000', 'UB', 'JU-2');
        HakAksesModul::create([
            'id_pengguna' => $this->admin->id_pengguna, 'kode_modul' => 'reports',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);

        $q = 'from=2026-01-01&to=2026-12-31';
        $this->actingAs($this->admin)->get("/reports/laba-rugi?{$q}")->assertOk()
            ->assertSee('Unit Bisnis')->assertSee('Semua unit');

        $this->actingAs($this->admin)->get("/reports/laba-rugi?{$q}&kode_unit=UA")->assertOk()
            ->assertSee('unit Unit A', false)
            ->assertSee('1.000.000');

        // Unit yang tak dikenal diabaikan, bukan menghasilkan laporan kosong.
        $this->actingAs($this->admin)->get("/reports/laba-rugi?{$q}&kode_unit=NGAWUR")->assertOk()
            ->assertSee('1.400.000');
    }
}
