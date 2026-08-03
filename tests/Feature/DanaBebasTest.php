<?php

namespace Tests\Feature;

use App\Models\AkunPengurangDanaBebas;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\PerintahPembayaran;
use App\Models\PerintahPembayaranDetail;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Modules\DanaBebasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DANA BEBAS DIPAKAI — saldo kas dikurangi titipan dan komitmen PP.
 *
 * Yang dijaga:
 *  • titipan milik orang lain (tabungan santri, dompet wali) TIDAK ikut terhitung
 *    sebagai uang yang boleh dibelanjakan;
 *  • akun pengurang mengikuti PENGATURAN, bukan daftar yang dipaku di kode;
 *  • PP yang sudah diotorisasi tapi belum dibayar ikut mengurangi — inilah yang
 *    paling mudah terlupa, dan tanpanya dua PP bisa lolos sendiri-sendiri lalu
 *    bersama-sama melampaui saldo.
 */
class DanaBebasTest extends TestCase
{
    use RefreshDatabase;

    private const GRP_KAS = 'ZZDBK';

    private const GRP_TITIP = 'ZZDBT';

    private const BANK = '1.1.01.ZZDB';

    private const KAS = '1.1.02.ZZDB';

    private const TITIP_TABUNGAN = '2.1.01.ZZDB1';

    private const TITIP_DOMPET = '2.1.01.ZZDB2';

    private const PENDAPATAN = '4.1.01.ZZDB';

    private const UNIT = 'ZZDBU';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP_KAS, 'nama_grup' => 'Kas Uji']);
        CoaGroup::create(['kode_grup' => self::GRP_TITIP, 'nama_grup' => 'Titipan Uji']);
        CoaDetail::create(['kode_coa' => self::BANK, 'nama_coa' => 'Bank Uji', 'kode_grup' => self::GRP_KAS, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas Uji', 'kode_grup' => self::GRP_KAS, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::TITIP_TABUNGAN, 'nama_coa' => 'Titipan Tabungan Santri', 'kode_grup' => self::GRP_TITIP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::TITIP_DOMPET, 'nama_coa' => 'Titipan Dompet Wali', 'kode_grup' => self::GRP_TITIP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Uji', 'kode_grup' => self::GRP_KAS, 'jenis_saldo' => 'kredit']);

        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'Bank Operasional', 'jenis_rekening' => 'bank']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
    }

    /** Uang masuk ke rekening: Kas/Bank (D) — lawan (K). */
    private function terima(string $rekening, string $nominal, string $lawan): void
    {
        PostingService::postJournal([
            'referensi' => 'UJI/'.uniqid(), 'tanggal' => '2026-08-01', 'kode_unit' => self::UNIT,
            'sumber_modul' => 'Uji', 'id_pengguna' => $this->admin->id_pengguna, 'keterangan' => 'setoran uji',
            'lines' => [
                ['kode_coa' => $rekening, 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => $lawan, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);
    }

    private function pp(string $status, string $sisa, string $statusBaris = 'disetujui'): PerintahPembayaran
    {
        $pp = PerintahPembayaran::create([
            'nomor' => 'PP-2608-'.str_pad((string) (PerintahPembayaran::count() + 1), 4, '0', STR_PAD_LEFT),
            'tanggal' => '2026-08-03', 'keterangan' => 'Uji', 'status' => $status,
            'disusun_oleh' => $this->admin->id_pengguna,
            'total_diajukan' => $sisa, 'total_diotorisasi' => $sisa,
        ]);
        PerintahPembayaranDetail::create([
            'kode_transaksi' => $pp->kode_transaksi, 'sumber' => 'invoice',
            'id_dokumen' => random_int(1000, 999999), 'nomor_dokumen' => 'INV-UJI', 'kode_unit' => self::UNIT,
            'nominal_diajukan' => $sisa, 'nominal_diotorisasi' => $sisa, 'terbayar' => '0', 'sisa' => $sisa,
            'status_baris' => $statusBaris,
        ]);

        return $pp;
    }

    // ---- Saldo kas ----

    public function test_saldo_kas_dijumlah_dari_seluruh_rekening(): void
    {
        $this->terima(self::BANK, '100000000', self::PENDAPATAN);
        $this->terima(self::KAS, '25000000', self::PENDAPATAN);

        $h = (new DanaBebasService)->hitung();

        $this->assertSame(125000000.0, (float) $h['saldo_kas']);
        $this->assertCount(2, $h['rincian_kas']);
        // Saldo aktual per rekening tetap tersedia apa adanya.
        $this->assertSame(100000000.0, (float) (new DanaBebasService)->saldoRekening(self::BANK));
    }

    // ---- Titipan ----

    /** Inti modul ini: uang titipan ada di rekening, tapi bukan milik pesantren. */
    public function test_titipan_tidak_ikut_dihitung_sebagai_dana_bebas(): void
    {
        // 100jt masuk bank, 60jt di antaranya titipan tabungan santri.
        $this->terima(self::BANK, '40000000', self::PENDAPATAN);
        $this->terima(self::BANK, '60000000', self::TITIP_TABUNGAN);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_TABUNGAN]);

        $h = (new DanaBebasService)->hitung();

        $this->assertSame(100000000.0, (float) $h['saldo_kas'], 'saldo fisiknya tetap 100jt');
        $this->assertSame(60000000.0, (float) $h['pengurang']);
        $this->assertSame(40000000.0, (float) $h['dana_bebas'], 'hanya 40jt yang boleh dibelanjakan');
    }

    /** Daftarnya PENGATURAN, bukan dipaku di kode. */
    public function test_akun_pengurang_mengikuti_pengaturan(): void
    {
        $this->terima(self::BANK, '30000000', self::TITIP_TABUNGAN);
        $this->terima(self::BANK, '20000000', self::TITIP_DOMPET);

        // Belum ada yang dicentang → tak ada yang dikurangi.
        $this->assertSame(50000000.0, (float) (new DanaBebasService)->danaBebas());

        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_TABUNGAN]);
        $this->assertSame(20000000.0, (float) (new DanaBebasService)->danaBebas());

        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_DOMPET]);
        $this->assertSame(0.0, (float) (new DanaBebasService)->danaBebas());

        // Dicabut centangnya → ikut terhitung lagi.
        AkunPengurangDanaBebas::where('kode_coa', self::TITIP_DOMPET)->delete();
        $this->assertSame(20000000.0, (float) (new DanaBebasService)->danaBebas());
    }

    // ---- Komitmen PP ----

    /**
     * Yang paling mudah terlupa. Tanpa ini, dua PP masing-masing melihat saldo
     * penuh dan bersama-sama melampauinya — uangnya memang belum keluar.
     */
    public function test_pp_diotorisasi_yang_belum_dibayar_ikut_mengurangi(): void
    {
        $this->terima(self::BANK, '50000000', self::PENDAPATAN);
        $this->assertSame(50000000.0, (float) (new DanaBebasService)->danaBebas());

        $this->pp('diotorisasi', '30000000');
        $this->assertSame(20000000.0, (float) (new DanaBebasService)->danaBebas(),
            'uang yang sudah diperintahkan bayar dianggap terpakai sejak diotorisasi');

        $this->pp('sebagian', '5000000');
        $this->assertSame(15000000.0, (float) (new DanaBebasService)->danaBebas());
    }

    public function test_pp_draf_dan_selesai_tidak_mengurangi(): void
    {
        $this->terima(self::BANK, '50000000', self::PENDAPATAN);

        // Draf & menunggu belum diperintahkan bayar.
        $this->pp('draf', '10000000');
        $this->pp('menunggu', '10000000');
        // Selesai & ditolak sudah tak menyisakan komitmen.
        $this->pp('selesai', '10000000', 'batal');
        $this->pp('ditolak', '10000000', 'batal');

        $this->assertSame(50000000.0, (float) (new DanaBebasService)->danaBebas());
    }

    /** Baris yang ditunda melepas komitmennya meski PP-nya masih hidup. */
    public function test_baris_ditunda_tidak_ikut_jadi_komitmen(): void
    {
        $this->terima(self::BANK, '50000000', self::PENDAPATAN);
        $this->pp('diotorisasi', '12000000', 'ditunda');

        $this->assertSame(50000000.0, (float) (new DanaBebasService)->danaBebas());
    }

    /**
     * PP yang sedang diotorisasi ulang tak boleh menghalangi dirinya sendiri.
     */
    public function test_komitmen_pp_sendiri_bisa_dikecualikan(): void
    {
        $this->terima(self::BANK, '50000000', self::PENDAPATAN);
        $pp = $this->pp('diotorisasi', '30000000');

        $svc = new DanaBebasService;
        $this->assertSame(20000000.0, (float) $svc->danaBebas());
        $this->assertSame(50000000.0, (float) $svc->danaBebasKecuali($pp->kode_transaksi));
    }

    // ---- Gabungan ----

    public function test_rumus_lengkap_saldo_dikurangi_titipan_dan_komitmen(): void
    {
        $this->terima(self::BANK, '150000000', self::PENDAPATAN);
        $this->terima(self::BANK, '80000000', self::TITIP_TABUNGAN);
        $this->terima(self::KAS, '18400000', self::PENDAPATAN);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_TABUNGAN]);
        $this->pp('diotorisasi', '19500000');

        $h = (new DanaBebasService)->hitung();

        $this->assertSame(248400000.0, (float) $h['saldo_kas']);
        $this->assertSame(80000000.0, (float) $h['pengurang']);
        $this->assertSame(19500000.0, (float) $h['komitmen']);
        $this->assertSame(148900000.0, (float) $h['dana_bebas']);

        // Rinciannya ikut, supaya panel bisa membukanya tanpa menghitung ulang.
        $this->assertCount(2, $h['rincian_kas']);
        $this->assertCount(1, $h['rincian_pengurang']);
        $this->assertCount(1, $h['rincian_komitmen']);
        $this->assertSame('Titipan Tabungan Santri', $h['rincian_pengurang'][0]['nama']);
    }

    /** Saldo awal (opening balance) ikut terhitung, bukan hanya jurnal. */
    public function test_saldo_awal_ikut_dihitung(): void
    {
        \App\Models\OpeningBalance::create(['kode_coa' => self::BANK, 'jenis_saldo' => 'debet', 'saldo' => '75000000']);
        \App\Models\OpeningBalance::create(['kode_coa' => self::TITIP_TABUNGAN, 'jenis_saldo' => 'kredit', 'saldo' => '25000000']);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_TABUNGAN]);

        $h = (new DanaBebasService)->hitung();

        $this->assertSame(75000000.0, (float) $h['saldo_kas']);
        $this->assertSame(25000000.0, (float) $h['pengurang']);
        $this->assertSame(50000000.0, (float) $h['dana_bebas']);
    }

    /** Kartu dashboard memakai perhitungan yang SAMA — satu tempat, satu angka. */
    public function test_kartu_dashboard_menampilkan_dana_bebas(): void
    {
        $this->terima(self::BANK, '40000000', self::PENDAPATAN);
        $this->terima(self::BANK, '60000000', self::TITIP_TABUNGAN);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_TABUNGAN]);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dana Bisa Dipakai')
            ->assertViewHas('danaBebas', fn ($d) => (float) $d['dana_bebas'] === 40000000.0);
    }

    /** Dana bebas boleh negatif — itu justru keadaan yang harus terlihat. */
    public function test_dana_bebas_negatif_bila_titipan_melebihi_saldo(): void
    {
        // Seluruh isi rekening adalah titipan; tak ada dana milik pesantren.
        $this->terima(self::BANK, '10000000', self::TITIP_TABUNGAN);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIP_TABUNGAN]);
        $this->pp('diotorisasi', '4000000');

        $this->assertSame(-4000000.0, (float) (new DanaBebasService)->danaBebas(),
            'titipan sudah terpakai — keadaan ini wajib terlihat, bukan disembunyikan jadi nol');
    }
}
