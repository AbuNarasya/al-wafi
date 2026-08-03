<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\AkunPengurangDanaBebas;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\PerintahPembayaran;
use App\Models\PerintahPembayaranDetail;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Ledger\PostingService;
use App\Services\Modules\PerintahPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PERINTAH PEMBAYARAN — daur hidup dokumen.
 *
 * Yang dijaga di sini adalah hal-hal yang kalau salah, uangnya yang celaka:
 *  • satu kewajiban tak bisa berada di dua perintah sekaligus (bayar dobel);
 *  • penyusun tak boleh mengotorisasi perintahnya sendiri (empat mata);
 *  • otorisasi tak boleh melampaui dana yang benar-benar bebas dipakai;
 *  • baris yang ditunda MELEPAS kuncinya, agar kewajibannya tak tersandera;
 *  • penutupan selalu mungkin, dan alasannya wajib bila masih ada sisa.
 */
class PerintahPembayaranTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPP';

    private const BANK = '1.1.01.ZZPP';

    private const HUTANG = '2.1.02.ZZPP';

    private const TITIPAN = '2.1.01.ZZPP';

    private const PENDAPATAN = '4.1.01.ZZPP';

    private const UNIT = 'ZZPPU';

    private User $penyusun;

    private User $pejabat;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PP Uji']);
        CoaDetail::create(['kode_coa' => self::BANK, 'nama_coa' => 'Bank Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Vendor', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::TITIPAN, 'nama_coa' => 'Titipan Tabungan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'Bank Operasional', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'V1', 'nama_vendor' => 'PT Sumber Pangan', 'kode_jenis_vendor' => 'JV']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        $this->penyusun = User::create(['username' => 'bendahara', 'nama' => 'Ustadz Bendahara', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        $this->pejabat = User::create(['username' => 'mudir', 'nama' => 'Mudir Umum', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        // Uang MILIK PESANTREN 30jt. Angka ini yang menentukan dana bebas —
        // setoran titipan tak menambahnya, karena ia menambah kas dan kewajiban
        // dalam jumlah yang sama.
        $this->setorKas('30000000', self::PENDAPATAN);
    }

    private function setorKas(string $nominal, string $lawan): void
    {
        PostingService::postJournal([
            'referensi' => 'UJI/'.uniqid(), 'tanggal' => '2026-08-01', 'kode_unit' => self::UNIT,
            'sumber_modul' => 'Uji', 'id_pengguna' => $this->penyusun->id_pengguna, 'keterangan' => 'setoran',
            'lines' => [
                ['kode_coa' => self::BANK, 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => $lawan, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);
    }

    private function invoice(string $nomor, string $sisa): Invoice
    {
        return Invoice::create([
            'nomor_invoice' => $nomor, 'tanggal_invoice' => '2026-07-20', 'tanggal_jatuh_tempo' => '2026-08-08',
            'kode_vendor' => 'V1', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'keterangan' => 'Beras 2 ton & minyak goreng', 'total' => $sisa, 'sisa_hutang' => $sisa,
            'status' => 'belum_bayar', 'id_pengguna' => $this->penyusun->id_pengguna,
        ]);
    }

    private function svc(): PerintahPembayaranService
    {
        return new PerintahPembayaranService;
    }

    private function buatPp(Invoice $inv, string $nominal): PerintahPembayaran
    {
        return $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Pembayaran termin I',
            'detail' => [['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => $nominal]],
        ], $this->penyusun->id_pengguna);
    }

    private function otorisasiPenuh(PerintahPembayaran $pp): PerintahPembayaran
    {
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);
        $baris = $pp->detail()->get()->mapWithKeys(fn ($d) => [$d->id => $d->nominal_diajukan])->all();

        return $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer', 'baris' => $baris,
        ], $this->pejabat->id_pengguna);
    }

    // ---- Menyusun ----

    public function test_kewajiban_tersedia_diambil_dari_dokumen_yang_masih_bersisa(): void
    {
        $this->invoice('INV-0001', '12500000');
        $lunas = $this->invoice('INV-0002', '3000000');
        $lunas->update(['sisa_hutang' => '0', 'status' => 'lunas']);

        $tersedia = $this->svc()->kewajibanTersedia();

        $this->assertCount(1, $tersedia);
        $this->assertSame('INV-0001', $tersedia[0]['nomor_dokumen']);
        $this->assertSame('PT Sumber Pangan', $tersedia[0]['pihak']);
        $this->assertSame('Beras 2 ton & minyak goreng', $tersedia[0]['keterangan']);
        $this->assertNull($tersedia[0]['terkunci_di']);
    }

    public function test_nomor_dokumen_berpola_pp_tahun_bulan(): void
    {
        $pp = $this->buatPp($this->invoice('INV-0001', '5000000'), '5000000');

        $this->assertMatchesRegularExpression('/^PP-2608-\d{4}$/', $pp->nomor);
        $this->assertSame('draf', $pp->status);
        $this->assertSame(5000000.0, (float) $pp->total_diajukan);
    }

    public function test_nominal_tak_boleh_melebihi_sisa_kewajiban(): void
    {
        $inv = $this->invoice('INV-0001', '5000000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('melebihi sisa kewajibannya');
        $this->buatPp($inv, '5000001');
    }

    public function test_satu_kewajiban_tak_boleh_dua_kali_dalam_satu_perintah(): void
    {
        $inv = $this->invoice('INV-0001', '5000000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('dua kali dalam perintah yang sama');
        $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'x',
            'detail' => [
                ['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => '1000000'],
                ['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => '2000000'],
            ],
        ], $this->penyusun->id_pengguna);
    }

    // ---- Anti bayar-dobel ----

    public function test_kewajiban_yang_sedang_di_pp_lain_ditolak_dan_disebut_nomornya(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pertama = $this->buatPp($inv, '12500000');

        $tersedia = collect($this->svc()->kewajibanTersedia())->firstWhere('nomor_dokumen', 'INV-0001');
        $this->assertSame($pertama->nomor, $tersedia['terkunci_di'], 'penyusun harus tahu ia sedang diproses di mana');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage("sedang berada di perintah {$pertama->nomor}");
        $this->buatPp($inv, '1000000');
    }

    // ---- Otorisasi ----

    public function test_penyusun_tak_boleh_mengotorisasi_perintahnya_sendiri(): void
    {
        $pp = $this->buatPp($this->invoice('INV-0001', '5000000'), '5000000');
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);
        $id = $pp->detail()->value('id');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tidak boleh mengotorisasi perintahnya sendiri');
        $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer', 'baris' => [$id => '5000000'],
        ], $this->penyusun->id_pengguna);
    }

    public function test_otorisasi_menetapkan_waktu_metode_dan_totalnya(): void
    {
        $pp = $this->otorisasiPenuh($this->buatPp($this->invoice('INV-0001', '12500000'), '12500000'));

        $this->assertSame('diotorisasi', $pp->status);
        $this->assertSame('2026-08-10', $pp->tanggal_bayar->toDateString());
        $this->assertSame('transfer', $pp->metode);
        $this->assertSame(12500000.0, (float) $pp->total_diotorisasi);
        $this->assertSame($this->pejabat->id_pengguna, $pp->diotorisasi_oleh);
        $this->assertNotNull($pp->diotorisasi_pada);
    }

    /** Otorisasi PARSIAL: sebagian disetujui, sebagian ditunda, satu dikurangi. */
    public function test_otorisasi_parsial_menyetujui_menunda_dan_mengurangi(): void
    {
        $a = $this->invoice('INV-0001', '12500000');
        $b = $this->invoice('INV-0002', '8400000');
        $c = $this->invoice('INV-0003', '4000000');
        $pp = $this->svc()->buat([
            'tanggal' => '2026-08-03', 'keterangan' => 'Termin I',
            'detail' => [
                ['sumber' => 'invoice', 'id_dokumen' => $a->id_invoice, 'nominal' => '12500000'],
                ['sumber' => 'invoice', 'id_dokumen' => $b->id_invoice, 'nominal' => '4000000'],
                ['sumber' => 'invoice', 'id_dokumen' => $c->id_invoice, 'nominal' => '4000000'],
            ],
        ], $this->penyusun->id_pengguna);
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);

        $d = $pp->detail()->orderBy('id')->get();
        $pp = $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [
                $d[0]->id => '12500000',  // disetujui penuh
                $d[1]->id => '2000000',   // dikurangi
                $d[2]->id => '0',         // ditunda
            ],
            'alasan' => [$d[2]->id => 'Dana dialihkan ke perbaikan pompa'],
        ], $this->pejabat->id_pengguna);

        $this->assertSame(14500000.0, (float) $pp->total_diotorisasi);
        $baris = $pp->detail()->orderBy('id')->get();
        $this->assertSame('disetujui', $baris[0]->status_baris);
        $this->assertSame(2000000.0, (float) $baris[1]->nominal_diotorisasi, 'nominal boleh dikurangi pejabat');
        $this->assertSame(4000000.0, (float) $baris[1]->nominal_diajukan, 'yang diajukan tetap tersimpan');
        $this->assertSame('ditunda', $baris[2]->status_baris);
        $this->assertSame('Dana dialihkan ke perbaikan pompa', $baris[2]->alasan);

        // Yang ditunda kuncinya LEPAS — bisa diajukan lagi.
        $tersedia = collect($this->svc()->kewajibanTersedia())->firstWhere('nomor_dokumen', 'INV-0003');
        $this->assertNull($tersedia['terkunci_di']);
    }

    public function test_pejabat_boleh_menambah_kewajiban_dan_barisnya_ditandai(): void
    {
        $a = $this->invoice('INV-0001', '5000000');
        $b = $this->invoice('INV-0002', '3750000');
        $pp = $this->buatPp($a, '5000000');
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);
        $id = $pp->detail()->value('id');

        $pp = $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$id => '5000000'],
            'tambahan' => [['sumber' => 'invoice', 'id_dokumen' => $b->id_invoice, 'nominal' => '3750000']],
        ], $this->pejabat->id_pengguna);

        $this->assertSame(8750000.0, (float) $pp->total_diotorisasi);
        $tambahan = $pp->detail()->where('ditambahkan_pengotorisasi', true)->sole();
        $this->assertSame('INV-0002', $tambahan->nomor_dokumen);
        $this->assertSame('disetujui', $tambahan->status_baris);
    }

    public function test_otorisasi_ditolak_bila_semua_baris_ditunda(): void
    {
        $pp = $this->buatPp($this->invoice('INV-0001', '5000000'), '5000000');
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);
        $id = $pp->detail()->value('id');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tolak perintahnya');
        $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer', 'baris' => [$id => '0'],
        ], $this->pejabat->id_pengguna);
    }

    /** Angka di PP hanyalah potret — yang berlaku sisa dokumen asalnya. */
    public function test_otorisasi_ditolak_bila_sisa_kewajiban_sudah_berubah(): void
    {
        $inv = $this->invoice('INV-0001', '12500000');
        $pp = $this->buatPp($inv, '12500000');
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);

        // Sesudah PP disusun, invoice-nya sebagian dilunasi lewat jalur lain.
        $inv->update(['sisa_hutang' => '4000000', 'status' => 'sebagian']);
        $id = $pp->detail()->value('id');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah berubah sejak perintah ini disusun');
        $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer', 'baris' => [$id => '12500000'],
        ], $this->pejabat->id_pengguna);
    }

    // ---- Dana bebas ----

    /**
     * Kas berlimpah tapi sebagian besar milik orang lain. Inilah keadaan yang
     * paling mudah menipu: saldo 130jt, yang boleh dipakai hanya 30jt.
     */
    public function test_otorisasi_ditolak_bila_melebihi_dana_bebas(): void
    {
        $this->setorKas('100000000', self::TITIPAN);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIPAN]);

        $svc = new \App\Services\Modules\DanaBebasService;
        $this->assertSame(130000000.0, (float) $svc->hitung()['saldo_kas'], 'saldo fisiknya memang 130jt');
        $this->assertSame(30000000.0, (float) $svc->danaBebas(), 'tapi hanya 30jt yang boleh dibelanjakan');

        $pp = $this->buatPp($this->invoice('INV-0001', '35000000'), '35000000');
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);
        $id = $pp->detail()->value('id');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('melebihi dana yang bisa dipakai');
        $this->svc()->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer', 'baris' => [$id => '35000000'],
        ], $this->pejabat->id_pengguna);
    }

    /**
     * Dua PP tak boleh bersama-sama melampaui saldo. Tanpa memperhitungkan
     * komitmen, keduanya melihat dana penuh — uangnya memang belum keluar.
     */
    public function test_pp_kedua_terhalang_komitmen_pp_pertama(): void
    {
        // Dana bebas 30jt; PP pertama mengunci 25jt.
        $this->otorisasiPenuh($this->buatPp($this->invoice('INV-0001', '25000000'), '25000000'));

        $kedua = $this->buatPp($this->invoice('INV-0002', '10000000'), '10000000');
        $this->svc()->ajukan($kedua->kode_transaksi, $this->penyusun->id_pengguna);
        $id = $kedua->detail()->value('id');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('melebihi dana yang bisa dipakai');
        $this->svc()->otorisasi($kedua->kode_transaksi, [
            'tanggal_bayar' => '2026-08-11', 'metode' => 'transfer', 'baris' => [$id => '10000000'],
        ], $this->pejabat->id_pengguna);
    }

    // ---- Penolakan & penutupan ----

    public function test_tolak_melepas_seluruh_kewajibannya(): void
    {
        $pp = $this->buatPp($this->invoice('INV-0001', '5000000'), '5000000');
        $this->svc()->ajukan($pp->kode_transaksi, $this->penyusun->id_pengguna);

        $pp = $this->svc()->tolak($pp->kode_transaksi, 'Dana belum tersedia', $this->pejabat->id_pengguna);

        $this->assertSame('ditolak', $pp->status);
        $this->assertNull(collect($this->svc()->kewajibanTersedia())->firstWhere('nomor_dokumen', 'INV-0001')['terkunci_di']);
    }

    public function test_penutupan_wajib_beralasan_bila_masih_ada_sisa(): void
    {
        $pp = $this->otorisasiPenuh($this->buatPp($this->invoice('INV-0001', '5000000'), '5000000'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Alasan penutupan wajib diisi');
        $this->svc()->tutup($pp->kode_transaksi, '', $this->pejabat->id_pengguna);
    }

    /** Sasarannya: tak boleh ada PP yang menggantung. */
    public function test_pp_selalu_bisa_ditutup_dan_sisanya_dibatalkan(): void
    {
        $pp = $this->otorisasiPenuh($this->buatPp($this->invoice('INV-0001', '5000000'), '5000000'));

        $pp = $this->svc()->tutup($pp->kode_transaksi, 'Vendor menunda pengiriman', $this->pejabat->id_pengguna);

        $this->assertSame('selesai', $pp->status);
        $this->assertSame('Vendor menunda pengiriman', $pp->alasan_tutup);
        $this->assertNotNull($pp->ditutup_pada);

        $baris = $pp->detail()->sole();
        $this->assertSame('batal', $baris->status_baris);
        $this->assertSame(0.0, (float) $baris->sisa);

        // Kewajibannya utuh & bebas diajukan lagi.
        $tersedia = collect($this->svc()->kewajibanTersedia())->firstWhere('nomor_dokumen', 'INV-0001');
        $this->assertNull($tersedia['terkunci_di']);
        $this->assertSame(5000000.0, (float) $tersedia['sisa'], 'kewajibannya tidak ikut hilang');
    }

    // ---- Riwayat ----

    public function test_riwayat_kewajiban_terbawa_ke_pp_berikutnya(): void
    {
        $inv = $this->invoice('INV-0001', '8400000');

        $pertama = $this->buatPp($inv, '8400000');
        $this->svc()->ajukan($pertama->kode_transaksi, $this->penyusun->id_pengguna);
        $this->svc()->otorisasi($pertama->kode_transaksi, [
            'tanggal_bayar' => '2026-08-05', 'metode' => 'transfer',
            'baris' => [$pertama->detail()->value('id') => '4000000'],
            'alasan' => [$pertama->detail()->value('id') => 'Tunggu termin I diperiksa'],
        ], $this->pejabat->id_pengguna);
        $this->svc()->tutup($pertama->kode_transaksi, 'Termin ditutup', $this->pejabat->id_pengguna);

        $riwayat = $this->svc()->riwayat('invoice', $inv->id_invoice);

        $this->assertCount(1, $riwayat);
        $this->assertSame($pertama->nomor, $riwayat[0]['nomor_pp']);
        $this->assertSame(8400000.0, (float) $riwayat[0]['diajukan']);
        $this->assertSame(4000000.0, (float) $riwayat[0]['diotorisasi']);
        $this->assertSame('Tunggu termin I diperiksa', $riwayat[0]['alasan']);
    }
}
