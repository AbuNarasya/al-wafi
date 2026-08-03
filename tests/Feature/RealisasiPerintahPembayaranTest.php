<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashOut;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\PerintahPembayaran;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Ledger\PostingService;
use App\Services\Modules\CashOutService;
use App\Services\Modules\PerintahPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REALISASI PERINTAH PEMBAYARAN lewat Kas Keluar.
 *
 * Tiga hal yang kalau salah, uangnya yang celaka:
 *  • KUNCI KERAS — kewajiban yang sedang di PP tak boleh dibayar dari jalur lain;
 *  • pembayaran tak boleh melampaui yang diotorisasi;
 *  • VOID harus mengembalikan sisa ke PP. Tanpa itu kewajiban tampak lunas
 *    padahal uangnya sudah ditarik kembali — rusak tanpa gejala.
 */
class RealisasiPerintahPembayaranTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZRL';

    private const BANK = '1.1.01.ZZRL';

    private const BANK2 = '1.1.01.ZZRL2';

    private const HUTANG = '2.1.02.ZZRL';

    private const PENDAPATAN = '4.1.01.ZZRL';

    private const UNIT = 'ZZRLU';

    private User $penyusun;

    private User $pejabat;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Realisasi Uji']);
        CoaDetail::create(['kode_coa' => self::BANK, 'nama_coa' => 'Bank Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::BANK2, 'nama_coa' => 'Bank Cadangan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Vendor', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'Bank Operasional', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian Uji', 'level' => 3]);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'V1', 'nama_vendor' => 'PT Sumber Pangan', 'kode_jenis_vendor' => 'JV']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        $this->penyusun = User::create(['username' => 'bendahara', 'nama' => 'Bendahara', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        $this->pejabat = User::create(['username' => 'mudir', 'nama' => 'Mudir Umum', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        PostingService::postJournal([
            'referensi' => 'MODAL/1', 'tanggal' => '2026-08-01', 'kode_unit' => self::UNIT,
            'sumber_modul' => 'Uji', 'id_pengguna' => $this->penyusun->id_pengguna, 'keterangan' => 'modal awal',
            'lines' => [
                ['kode_coa' => self::BANK, 'debet' => '100000000', 'kredit' => '0'],
                ['kode_coa' => self::PENDAPATAN, 'debet' => '0', 'kredit' => '100000000'],
            ],
        ]);
    }

    private function svc(): PerintahPembayaranService
    {
        return new PerintahPembayaranService;
    }

    private function invoice(string $nomor, string $sisa): Invoice
    {
        return Invoice::create([
            'nomor_invoice' => $nomor, 'tanggal_invoice' => '2026-07-20', 'tanggal_jatuh_tempo' => '2026-08-08',
            'kode_vendor' => 'V1', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'keterangan' => 'Beras & minyak', 'total' => $sisa, 'sisa_hutang' => $sisa,
            'status' => 'belum_bayar', 'id_pengguna' => $this->penyusun->id_pengguna,
        ]);
    }

    /** PP berisi satu invoice, sudah diotorisasi penuh. */
    private function ppDiotorisasi(Invoice $inv, string $nominal): PerintahPembayaran
    {
        $pp = $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Termin I',
            'detail' => [['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => $nominal]],
        ], $this->penyusun->id_pengguna);
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);

        return $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$pp->detail()->value('id') => $nominal],
        ], $this->pejabat->id_pengguna);
    }

    private function bayar(PerintahPembayaran $pp, string $nominal, ?int $idDetail = null): CashOut
    {
        $d = $idDetail ? $pp->detail()->find($idDetail) : $pp->detail()->first();

        return (new CashOutService)->create([
            'tanggal' => '2026-08-10', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK,
            'keterangan' => "Realisasi {$pp->nomor}", 'id_perintah' => $pp->kode_transaksi,
            'details' => [[
                'tipe' => 'invoice', 'id_invoice' => $d->id_dokumen, 'nominal' => $nominal,
                'id_perintah_detail' => $d->id, 'kode_bagian' => 'B1',
            ]],
        ], $this->penyusun->id_pengguna);
    }

    // ---- Kunci keras ----

    /** Kewajiban yang sedang di PP tak boleh dibayar lewat Kas Keluar biasa. */
    public function test_kewajiban_di_pp_hidup_ditolak_bila_dibayar_langsung(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage("sedang berada di perintah pembayaran {$pp->nomor}");
        (new CashOutService)->create([
            'tanggal' => '2026-08-10', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK,
            'keterangan' => 'Bayar langsung',
            'details' => [['tipe' => 'invoice', 'id_invoice' => $inv->id_invoice, 'nominal' => '1000000', 'kode_bagian' => 'B1']],
        ], $this->penyusun->id_pengguna);
    }

    /** Kuncinya melekat pada DOKUMEN, bukan jenisnya — yang bebas tetap bisa. */
    public function test_kewajiban_di_luar_pp_tetap_bisa_dibayar_langsung(): void
    {
        $this->ppDiotorisasi($this->invoice('INV-0001', '12500000'), '12500000');
        $bebas = $this->invoice('INV-0002', '3000000');

        $kk = (new CashOutService)->create([
            'tanggal' => '2026-08-10', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK,
            'keterangan' => 'Bayar langsung',
            'details' => [['tipe' => 'invoice', 'id_invoice' => $bebas->id_invoice, 'nominal' => '3000000', 'kode_bagian' => 'B1']],
        ], $this->penyusun->id_pengguna);

        $this->assertSame('aktif', $kk->status);
        $this->assertSame(0.0, (float) $bebas->refresh()->sisa_hutang);
    }

    /** Sesudah dikeluarkan dari PP (ditutup), jalur langsung terbuka lagi. */
    public function test_setelah_pp_ditutup_kewajibannya_bisa_dibayar_langsung(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');
        $this->svc()->tutup($pp->kode_transaksi, 'Mendesak, dibayar di luar PP', $this->pejabat->id_pengguna);

        $kk = (new CashOutService)->create([
            'tanggal' => '2026-08-10', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK,
            'keterangan' => 'Bayar langsung',
            'details' => [['tipe' => 'invoice', 'id_invoice' => $inv->id_invoice, 'nominal' => '12500000', 'kode_bagian' => 'B1']],
        ], $this->penyusun->id_pengguna);

        $this->assertSame('aktif', $kk->status);
    }

    // ---- Realisasi ----

    public function test_pembayaran_penuh_menandai_pp_terbayar(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');

        $kk = $this->bayar($pp, '12500000');

        $this->assertSame($pp->kode_transaksi, $kk->id_perintah);
        $baris = $pp->detail()->sole();
        $this->assertSame(12500000.0, (float) $baris->terbayar);
        $this->assertSame(0.0, (float) $baris->sisa);
        // Lunas TAPI belum ditutup — penutupan selalu keputusan sadar.
        $this->assertSame('terbayar', $pp->refresh()->status);
        $this->assertSame(0.0, (float) $inv->refresh()->sisa_hutang, 'invoice-nya ikut lunas');
    }

    public function test_pembayaran_sebagian_menyisakan_di_pp(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');

        $this->bayar($pp, '5000000');

        $baris = $pp->detail()->sole();
        $this->assertSame(5000000.0, (float) $baris->terbayar);
        $this->assertSame(7500000.0, (float) $baris->sisa);
        $this->assertSame('sebagian', $pp->refresh()->status);
    }

    /** Boleh dicicil dari rekening berbeda — satu voucher tetap satu sumber. */
    public function test_dicicil_dua_kali_sampai_lunas(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');

        $this->bayar($pp, '5000000');
        $this->bayar($pp, '7500000');

        $this->assertSame(0.0, (float) $pp->detail()->sole()->sisa);
        $this->assertSame('terbayar', $pp->refresh()->status);
    }

    public function test_pembayaran_melebihi_yang_diotorisasi_ditolak(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '8000000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('melebihi sisa yang diotorisasi');
        $this->bayar($pp, '8000001');
    }

    public function test_pp_yang_belum_diotorisasi_tak_bisa_direalisasikan(): void
    {
        $inv = $this->invoice('INV-0001', '5000000');
        $pp = $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Draf',
            'detail' => [['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => '5000000']],
        ], $this->penyusun->id_pengguna);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('belum diotorisasi');
        $this->bayar($pp, '5000000');
    }

    // ---- Formulir Kas Keluar terisi dari PP ----

    /**
     * Tanpa pengisian otomatis, modul ini justru MENAMBAH pekerjaan admin —
     * mengetik hal yang sama dua kali.
     */
    public function test_formulir_kas_keluar_terisi_dari_perintah(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '8000000');

        $this->actingAs($this->penyusun)
            ->get(route('cash_out.create', ['perintah' => $pp->kode_transaksi]))
            ->assertOk()
            ->assertSee('Terisi dari')
            ->assertSee($pp->nomor)
            ->assertViewHas('prefill', function ($prefill) use ($inv) {
                return count($prefill) === 1
                    && $prefill[0]['tipe'] === 'invoice'
                    && (int) $prefill[0]['id_invoice'] === $inv->id_invoice
                    // Nominalnya sebesar yang DIOTORISASI, bukan sisa invoice-nya.
                    && (float) $prefill[0]['nominal'] === 8000000.0;
            });
    }

    public function test_baris_yang_ditunda_tidak_ikut_terisi(): void
    {
        $a = $this->invoice('INV-0001', '5000000');
        $b = $this->invoice('INV-0002', '3000000');
        $pp = $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Termin I',
            'detail' => [
                ['sumber' => 'invoice', 'id_dokumen' => $a->id_invoice, 'nominal' => '5000000'],
                ['sumber' => 'invoice', 'id_dokumen' => $b->id_invoice, 'nominal' => '3000000'],
            ],
        ], $this->penyusun->id_pengguna);
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);
        $d = $pp->detail()->orderBy('id')->get();
        $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$d[0]->id => '5000000', $d[1]->id => '0'],
        ], $this->pejabat->id_pengguna);

        // Diperiksa lewat DATA VIEW, bukan HTML-nya: nomor invoice juga muncul
        // di blob pilihan invoice pada halaman yang sama, dan JSON Alpine
        // meng-escape tanda kutipnya — dua-duanya membuat pencocokan teks
        // menyesatkan.
        $this->actingAs($this->penyusun)
            ->get(route('cash_out.create', ['perintah' => $pp->kode_transaksi]))
            ->assertOk()
            ->assertViewHas('prefill', function ($prefill) use ($d) {
                $tertaut = array_column($prefill, 'id_perintah_detail');

                return count($prefill) === 1
                    && in_array($d[0]->id, $tertaut, true)
                    && ! in_array($d[1]->id, $tertaut, true);
            });
    }

    public function test_perintah_belum_diotorisasi_tidak_mengisi_formulir(): void
    {
        $inv = $this->invoice('INV-0001', '5000000');
        $pp = $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Draf',
            'detail' => [['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => '5000000']],
        ], $this->penyusun->id_pengguna);

        $this->actingAs($this->penyusun)
            ->get(route('cash_out.create', ['perintah' => $pp->kode_transaksi]))
            ->assertOk()
            ->assertDontSee('Terisi dari');
    }

    // ---- Laporan kepatuhan ----

    public function test_kepatuhan_menandai_realisasi_yang_persis_sesuai(): void
    {
        $pp = $this->ppDiotorisasi($this->invoice('INV-0001', '5000000'), '5000000');
        $pp->update(['kode_rekening_rencana' => self::BANK]);
        $this->bayar($pp, '5000000');

        $r = collect($this->svc()->kepatuhan())->firstWhere('nomor', $pp->nomor);

        $this->assertSame(5000000.0, (float) $r['diotorisasi']);
        $this->assertSame(5000000.0, (float) $r['terealisasi']);
        $this->assertSame(0.0, (float) $r['sisa']);
        $this->assertSame(0, $r['selisih_hari'], 'dibayar tepat pada tanggalnya');
        $this->assertFalse($r['rekening_beda']);
        $this->assertNull($r['terlambat_hari']);
    }

    /** Rekening & tanggal boleh berbeda — yang penting selisihnya terlihat. */
    public function test_kepatuhan_menangkap_beda_rekening_tanggal_dan_metode(): void
    {
        BankAccount::create(['kode_coa' => self::BANK2, 'nama_rekening' => 'Bank Cadangan', 'jenis_rekening' => 'bank']);
        $pp = $this->ppDiotorisasi($this->invoice('INV-0001', '5000000'), '5000000');
        $pp->update(['kode_rekening_rencana' => self::BANK, 'metode' => 'transfer']);

        $d = $pp->detail()->first();
        (new CashOutService)->create([
            'tanggal' => '2026-08-13', 'kode_unit' => self::UNIT, 'kode_rekening' => self::BANK2,
            'metode' => 'tunai', 'keterangan' => 'Realisasi', 'id_perintah' => $pp->kode_transaksi,
            'details' => [['tipe' => 'invoice', 'id_invoice' => $d->id_dokumen, 'nominal' => '5000000',
                'id_perintah_detail' => $d->id, 'kode_bagian' => 'B1']],
        ], $this->penyusun->id_pengguna);

        $r = collect($this->svc()->kepatuhan())->firstWhere('nomor', $pp->nomor);

        $this->assertSame(3, $r['selisih_hari'], 'terlambat 3 hari dari rencana 10/08');
        $this->assertTrue($r['rekening_beda']);
        $this->assertSame([self::BANK2], $r['rekening_dipakai']);
        $this->assertTrue($r['metode_beda']);
        $this->assertSame(['tunai'], $r['metode_dipakai']);
    }

    /** Yang paling perlu tertangkap: diotorisasi, lewat tanggalnya, belum dibayar. */
    public function test_kepatuhan_menandai_perintah_yang_lewat_tanggal_belum_dibayar(): void
    {
        $pp = $this->ppDiotorisasi($this->invoice('INV-0001', '5000000'), '5000000');
        // Tanggal bayarnya sudah lewat & belum ada realisasi.
        $pp->update(['tanggal_bayar' => now()->subDays(6)->toDateString()]);

        $r = collect($this->svc()->kepatuhan())->firstWhere('nomor', $pp->nomor);

        $this->assertSame(6, $r['terlambat_hari']);
        $this->assertSame(0.0, (float) $r['terealisasi']);
        $this->assertSame(5000000.0, (float) $r['sisa']);
    }

    public function test_kepatuhan_tak_memuat_draf_dan_yang_menunggu(): void
    {
        $inv = $this->invoice('INV-0001', '5000000');
        $draf = $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Draf',
            'detail' => [['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => '5000000']],
        ], $this->penyusun->id_pengguna);

        $this->assertNull(collect($this->svc()->kepatuhan())->firstWhere('nomor', $draf->nomor));
    }

    /** Voucher yang di-void tak pernah benar-benar terjadi. */
    public function test_kepatuhan_mengabaikan_voucher_yang_divoid(): void
    {
        $pp = $this->ppDiotorisasi($this->invoice('INV-0001', '5000000'), '5000000');
        $kk = $this->bayar($pp, '5000000');
        (new CashOutService)->void($kk->kode_transaksi,
            ['tanggal' => '2026-08-12', 'alasan' => 'Salah'], $this->penyusun->id_pengguna, 'Bendahara');

        $r = collect($this->svc()->kepatuhan())->firstWhere('nomor', $pp->nomor);

        $this->assertSame(0, $r['jumlah_voucher']);
        $this->assertSame(0.0, (float) $r['terealisasi']);
        $this->assertSame(5000000.0, (float) $r['sisa'], 'kembali dianggap belum dibayar');
    }

    public function test_halaman_kepatuhan_terbuka(): void
    {
        $pp = $this->ppDiotorisasi($this->invoice('INV-0001', '5000000'), '5000000');

        $this->actingAs($this->penyusun)->get(route('perintah_pembayaran.kepatuhan'))
            ->assertOk()
            ->assertSee('Kepatuhan Perintah Pembayaran')
            ->assertSee($pp->nomor);
    }

    // ---- Void ----

    /**
     * Temuan yang wajib jalan bersamaan dengan modulnya: tanpa pengembalian ini,
     * kewajiban tampak lunas di PP padahal uangnya sudah ditarik kembali.
     */
    public function test_void_mengembalikan_sisa_ke_perintah(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');
        $kk = $this->bayar($pp, '12500000');

        $this->assertSame(0.0, (float) $pp->detail()->sole()->sisa);

        (new CashOutService)->void($kk->kode_transaksi,
            ['tanggal' => '2026-08-12', 'alasan' => 'Salah rekening tujuan'],
            $this->penyusun->id_pengguna, 'Bendahara');

        $baris = $pp->detail()->sole();
        $this->assertSame(0.0, (float) $baris->terbayar, 'terbayar dikembalikan');
        $this->assertSame(12500000.0, (float) $baris->sisa, 'sisanya kembali — kewajibannya belum lunas');
        $this->assertSame('diotorisasi', $pp->refresh()->status, 'status mundur, bukan tetap terbayar');
        $this->assertSame(12500000.0, (float) $inv->refresh()->sisa_hutang);
    }

    public function test_void_pembayaran_sebagian_mengembalikan_sebagiannya_saja(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');
        $this->bayar($pp, '5000000');
        $kedua = $this->bayar($pp, '4000000');

        (new CashOutService)->void($kedua->kode_transaksi,
            ['tanggal' => '2026-08-12', 'alasan' => 'Dobel'],
            $this->penyusun->id_pengguna, 'Bendahara');

        $baris = $pp->detail()->sole();
        $this->assertSame(5000000.0, (float) $baris->terbayar);
        $this->assertSame(7500000.0, (float) $baris->sisa);
        $this->assertSame('sebagian', $pp->refresh()->status);
    }

    /** PP yang sudah ditutup: sisanya sudah dinyatakan batal, jangan dihidupkan. */
    public function test_void_setelah_pp_ditutup_tidak_menghidupkan_sisanya(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->ppDiotorisasi($inv, '12500000');
        $kk = $this->bayar($pp, '5000000');
        $this->svc()->tutup($pp->kode_transaksi, 'Cukup segitu dulu', $this->pejabat->id_pengguna);

        (new CashOutService)->void($kk->kode_transaksi,
            ['tanggal' => '2026-08-12', 'alasan' => 'Salah input'],
            $this->penyusun->id_pengguna, 'Bendahara');

        $baris = $pp->detail()->sole();
        $this->assertSame(0.0, (float) $baris->terbayar, 'terbayarnya tetap dikembalikan');
        $this->assertSame(0.0, (float) $baris->sisa, 'tapi sisanya tetap nol — sudah dinyatakan batal');
        $this->assertSame('selesai', $pp->refresh()->status);
        // Kewajibannya sendiri kembali utuh & bebas diajukan di PP berikutnya.
        $this->assertSame(12500000.0, (float) $inv->refresh()->sisa_hutang);
        $this->assertNull(collect($this->svc()->kewajibanTersedia())->firstWhere('nomor_dokumen', 'INV-0001')['terkunci_di']);
    }
}
