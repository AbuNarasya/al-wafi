<?php

namespace Tests\Feature;

use App\Models\AkunPengurangDanaBebas;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\PerintahPembayaran;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Ledger\PostingService;
use App\Services\Modules\PerintahPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LAYAR Perintah Pembayaran — rute, hak akses, dan yang tampil.
 *
 * Yang paling penting di sini: EMPAT MATA ditegakkan lewat PEMBERIAN HAK, bukan
 * sekadar kesepakatan. Otorisasi & penutupan memakai modul `otorisasi-pembayaran`
 * yang terpisah dari `perintah-pembayaran`, sehingga penyusun bisa diberi hak
 * menyusun tanpa ikut bisa menyetujui.
 */
class PerintahPembayaranLayarTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZLY';

    private const BANK = '1.1.01.ZZLY';

    private const HUTANG = '2.1.02.ZZLY';

    private const TITIPAN = '2.1.01.ZZLY';

    private const PENDAPATAN = '4.1.01.ZZLY';

    private const UNIT = 'ZZLYU';

    private User $admin;

    private User $penyusun;

    private User $pejabat;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Layar Uji']);
        CoaDetail::create(['kode_coa' => self::BANK, 'nama_coa' => 'Bank Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::HUTANG, 'nama_coa' => 'Hutang Vendor', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::TITIPAN, 'nama_coa' => 'Titipan Tabungan Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::BANK, 'nama_rekening' => 'Bank Operasional', 'jenis_rekening' => 'bank', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        VendorType::create(['kode_jenis_vendor' => 'JV', 'nama' => 'Umum']);
        Vendor::create(['kode_vendor' => 'V1', 'nama_vendor' => 'PT Sumber Pangan', 'kode_jenis_vendor' => 'JV']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        // Bukan admin — haknya diberikan satu per satu, seperti di lapangan.
        $this->penyusun = User::create(['username' => 'bendahara', 'nama' => 'Bendahara', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        $this->pejabat = User::create(['username' => 'mudir', 'nama' => 'Mudir Umum', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);

        $this->beriHak($this->penyusun, 'perintah-pembayaran');
        $this->beriHak($this->pejabat, 'perintah-pembayaran');
        $this->beriHak($this->pejabat, 'otorisasi-pembayaran');

        PostingService::postJournal([
            'referensi' => 'MODAL/1', 'tanggal' => '2026-08-01', 'kode_unit' => self::UNIT,
            'sumber_modul' => 'Uji', 'id_pengguna' => $this->admin->id_pengguna, 'keterangan' => 'modal',
            'lines' => [
                ['kode_coa' => self::BANK, 'debet' => '100000000', 'kredit' => '0'],
                ['kode_coa' => self::PENDAPATAN, 'debet' => '0', 'kredit' => '100000000'],
            ],
        ]);
    }

    private function beriHak(User $u, string $modul): void
    {
        HakAksesModul::updateOrCreate(
            ['id_pengguna' => $u->id_pengguna, 'kode_modul' => $modul],
            ['lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true],
        );
    }

    private function invoice(string $nomor, string $sisa): Invoice
    {
        return Invoice::create([
            'nomor_invoice' => $nomor, 'tanggal_invoice' => '2026-07-20', 'tanggal_jatuh_tempo' => '2026-08-08',
            'kode_vendor' => 'V1', 'kode_unit' => self::UNIT, 'kode_coa_hutang' => self::HUTANG,
            'keterangan' => 'Beras 2 ton & minyak goreng', 'total' => $sisa, 'sisa_hutang' => $sisa,
            'status' => 'belum_bayar', 'id_pengguna' => $this->admin->id_pengguna,
        ]);
    }

    private function buatLewatLayar(Invoice $inv, string $nominal): PerintahPembayaran
    {
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.store'), [
            'tanggal' => '2026-08-03', 'keterangan' => 'Pembayaran termin I',
            'detail' => [['sumber' => 'invoice', 'id_dokumen' => $inv->id_invoice, 'nominal' => $nominal]],
        ])->assertRedirect();

        return PerintahPembayaran::latest('kode_transaksi')->first();
    }

    // ---- Daftar & penyusunan ----

    public function test_daftar_menampilkan_dana_bebas_dan_rinciannya(): void
    {
        // 100jt kas, 70jt titipan → 30jt bebas.
        PostingService::postJournal([
            'referensi' => 'TITIP/1', 'tanggal' => '2026-08-01', 'kode_unit' => self::UNIT,
            'sumber_modul' => 'Uji', 'id_pengguna' => $this->admin->id_pengguna, 'keterangan' => 'titipan',
            'lines' => [
                ['kode_coa' => self::BANK, 'debet' => '70000000', 'kredit' => '0'],
                ['kode_coa' => self::TITIPAN, 'debet' => '0', 'kredit' => '70000000'],
            ],
        ]);
        AkunPengurangDanaBebas::create(['kode_coa' => self::TITIPAN]);

        $this->actingAs($this->penyusun)->get(route('perintah_pembayaran.index'))
            ->assertOk()
            ->assertSee('Dana yang bisa dipakai')
            ->assertSee('100.000.000')   // saldo kotor tetap terlihat di rincian
            ->assertSee('Lihat rincian perhitungan');
    }

    public function test_layar_susun_menampilkan_kewajiban_berikut_keterangannya(): void
    {
        $this->invoice('INV-0001', '12500000');

        // Tabelnya dirender Alpine dari blob JSON, jadi yang bisa diperiksa dari
        // sisi server adalah datanya memang dioper — bukan sel yang sudah jadi.
        // `&` di dalam blob itu ter-escape menjadi &, karena itu yang dicari
        // penggalan yang tak menyertakannya.
        $this->actingAs($this->penyusun)->get(route('perintah_pembayaran.create'))
            ->assertOk()
            ->assertSee('INV-0001')
            ->assertSee('PT Sumber Pangan')
            // Keterangan per item, bukan hanya pihaknya.
            ->assertSee('Beras 2 ton', false)
            ->assertSee('minyak goreng', false)
            ->assertSee('Keterangan', false);
    }

    public function test_menyimpan_draf_lewat_layar(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '12500000'), '12500000');

        $this->assertSame('draf', $pp->status);
        $this->assertSame(12500000.0, (float) $pp->total_diajukan);
        $this->assertSame($this->penyusun->id_pengguna, $pp->disusun_oleh);
    }

    // ---- Hak akses & empat mata ----

    public function test_penyusun_tanpa_hak_otorisasi_ditolak_rutenya(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '5000000'), '5000000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));

        // `perintah-pembayaran` saja tidak cukup — otorisasi modulnya lain.
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.otorisasi', $pp->kode_transaksi), [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$pp->detail()->value('id') => '5000000'],
        ])->assertForbidden();

        $this->assertSame('menunggu', $pp->refresh()->status);
    }

    /** Punya hak pun tak cukup bila ia penyusunnya sendiri. */
    public function test_penyusun_berhak_otorisasi_tetap_ditolak_untuk_perintahnya_sendiri(): void
    {
        $this->beriHak($this->penyusun, 'otorisasi-pembayaran');
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '5000000'), '5000000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));

        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.otorisasi', $pp->kode_transaksi), [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$pp->detail()->value('id') => '5000000'],
        ])->assertRedirect();

        $this->assertSame('menunggu', $pp->refresh()->status, 'tetap belum diotorisasi');
        $this->assertNotNull(session('error'));
    }

    public function test_tombol_otorisasi_hanya_tampil_bagi_yang_berwenang(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '5000000'), '5000000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));

        $this->actingAs($this->penyusun)->get(route('perintah_pembayaran.show', $pp->kode_transaksi))
            ->assertOk()
            ->assertSee('Anda penyusun perintah ini')
            ->assertDontSee('Otorisasi Pembayaran');

        $this->actingAs($this->pejabat)->get(route('perintah_pembayaran.show', $pp->kode_transaksi))
            ->assertOk()
            ->assertSee('Otorisasi Pembayaran')
            ->assertSee('Saldo aktual per rekening');
    }

    // ---- Otorisasi lewat layar ----

    public function test_otorisasi_parsial_lewat_layar(): void
    {
        $a = $this->invoice('INV-0001', '12500000');
        $b = $this->invoice('INV-0002', '4000000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.store'), [
            'tanggal' => '2026-08-03', 'keterangan' => 'Termin I',
            'detail' => [
                ['sumber' => 'invoice', 'id_dokumen' => $a->id_invoice, 'nominal' => '12500000'],
                ['sumber' => 'invoice', 'id_dokumen' => $b->id_invoice, 'nominal' => '4000000'],
            ],
        ]);
        $pp = PerintahPembayaran::latest('kode_transaksi')->first();
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));

        $d = $pp->detail()->orderBy('id')->get();
        $this->actingAs($this->pejabat)->post(route('perintah_pembayaran.otorisasi', $pp->kode_transaksi), [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'kode_rekening_rencana' => self::BANK,
            'baris' => [$d[0]->id => '12500000', $d[1]->id => '0'],
            'alasan' => [$d[1]->id => 'Ditunda ke termin berikutnya'],
        ])->assertRedirect();

        $pp->refresh();
        $this->assertSame('diotorisasi', $pp->status);
        $this->assertSame(12500000.0, (float) $pp->total_diotorisasi);
        $this->assertSame('ditunda', $pp->detail()->orderBy('id')->get()[1]->status_baris);
    }

    public function test_layar_menampilkan_riwayat_pengajuan_sebelumnya(): void
    {
        $inv = $this->invoice('INV-0001', '8400000');
        $svc = new PerintahPembayaranService;

        $pertama = $this->buatLewatLayar($inv, '8400000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pertama->kode_transaksi));
        $svc->otorisasi($pertama->kode_transaksi, [
            'tanggal_bayar' => '2026-08-05', 'metode' => 'transfer',
            'baris' => [$pertama->detail()->value('id') => '4000000'],
            'alasan' => [$pertama->detail()->value('id') => 'Tunggu termin diperiksa'],
        ], $this->pejabat->id_pengguna);
        $svc->tutup($pertama->kode_transaksi, 'Termin ditutup', $this->pejabat->id_pengguna);

        $kedua = $this->buatLewatLayar($inv, '4400000');

        $this->actingAs($this->pejabat)->get(route('perintah_pembayaran.show', $kedua->kode_transaksi))
            ->assertOk()
            ->assertSee('ke-2')
            ->assertSee('Tunggu termin diperiksa');
    }

    // ---- Penutupan ----

    public function test_menutup_lewat_layar_membatalkan_sisanya(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '5000000'), '5000000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));
        (new PerintahPembayaranService)->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$pp->detail()->value('id') => '5000000'],
        ], $this->pejabat->id_pengguna);

        $this->actingAs($this->pejabat)->post(route('perintah_pembayaran.tutup', $pp->kode_transaksi), [
            'alasan' => 'Vendor menunda pengiriman',
        ])->assertRedirect();

        $this->assertSame('selesai', $pp->refresh()->status);
        $this->assertSame('batal', $pp->detail()->sole()->status_baris);
    }

    public function test_penutupan_butuh_hak_otorisasi(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '5000000'), '5000000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));

        $this->actingAs($this->penyusun)
            ->post(route('perintah_pembayaran.tutup', $pp->kode_transaksi), ['alasan' => 'x'])
            ->assertForbidden();
    }

    // ---- Cetak ----

    /** Draf tak boleh bisa disodorkan seolah sudah disetujui. */
    public function test_cetak_draf_membawa_cap_belum_diotorisasi(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '5000000'), '5000000');

        $this->actingAs($this->penyusun)->get(route('perintah_pembayaran.print', $pp->kode_transaksi))
            ->assertOk()
            ->assertSee('BELUM DIOTORISASI')
            ->assertSee('bukan')
            ->assertDontSee('Digitally approved');
    }

    public function test_cetak_setelah_otorisasi_membawa_digitally_approved(): void
    {
        $pp = $this->buatLewatLayar($this->invoice('INV-0001', '8400000'), '8400000');
        $this->actingAs($this->penyusun)->post(route('perintah_pembayaran.ajukan', $pp->kode_transaksi));
        (new PerintahPembayaranService)->otorisasi($pp->kode_transaksi, [
            'tanggal_bayar' => '2026-08-10', 'metode' => 'transfer',
            'baris' => [$pp->detail()->value('id') => '4000000'],
            'alasan' => [$pp->detail()->value('id') => 'Dikurangi, tunggu termin'],
        ], $this->pejabat->id_pengguna);

        $html = $this->actingAs($this->pejabat)->get(route('perintah_pembayaran.print', $pp->kode_transaksi))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Digitally approved', $html);
        $this->assertStringContainsString('Mudir Umum', $html);
        $this->assertStringContainsString('verifikasi:', $html);
        $this->assertStringNotContainsString('BELUM DIOTORISASI', $html);
        // Kedua kolom nominal terbawa, agar yang dikurangi terbaca penerimanya.
        $this->assertStringContainsString('8.400.000', $html);
        $this->assertStringContainsString('4.000.000', $html);
        $this->assertStringContainsString('Dikurangi, tunggu termin', $html);
    }

    // ---- Pengaturan akun pengurang ----

    public function test_pengaturan_akun_pengurang_bisa_diubah(): void
    {
        $this->beriHak($this->pejabat, 'pengaturan-dana-bebas');

        $this->actingAs($this->pejabat)->get(route('pengaturan_dana_bebas.index'))
            ->assertOk()->assertSee('Titipan Tabungan Santri');

        $this->actingAs($this->pejabat)->put(route('pengaturan_dana_bebas.update'), [
            'kode_coa' => [self::TITIPAN],
        ])->assertRedirect();

        $this->assertDatabaseHas('akun_pengurang_dana_bebas', ['kode_coa' => self::TITIPAN]);

        // Dicabut lagi → daftarnya kosong.
        $this->actingAs($this->pejabat)->put(route('pengaturan_dana_bebas.update'), ['kode_coa' => []]);
        $this->assertDatabaseCount('akun_pengurang_dana_bebas', 0);
    }

    public function test_pengaturan_tak_bisa_dibuka_tanpa_hak(): void
    {
        $this->actingAs($this->penyusun)->get(route('pengaturan_dana_bebas.index'))->assertForbidden();
    }
}
