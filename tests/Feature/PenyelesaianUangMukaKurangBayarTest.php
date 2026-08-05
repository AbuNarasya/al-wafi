<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use App\Services\Modules\PerintahPembayaranService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penyelesaian uang muka: POSTING dipisahkan dari PEMBAYARAN kekurangannya.
 *
 * Sebelumnya, penyelesaian yang realisasinya melampaui uang muka langsung
 * MENGKREDIT KAS sebesar kekurangan — seolah uangnya sudah keluar padahal belum
 * dibayar sama sekali. Saldo kas & "dana bisa dipakai" jadi lebih rendah dari
 * kenyataan, dan kewajiban kepada pemohon tak tercatat di mana pun.
 *
 * Sekarang: kekurangan ditahan di akun HUTANG pilihan keuangan, muncul sebagai
 * kewajiban di Perintah Pembayaran, dan kas baru berkurang lewat Kas Keluar.
 * Kelebihan (uang muka > realisasi) tetap langsung mengakui kas masuk.
 */
class PenyelesaianUangMukaKurangBayarTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.ZZUM.1';
    private const UM = '1.ZZUM.9';
    private const BEBAN = '5.ZZUM.1';
    private const HUTANG = '2.ZZUM.1';

    private PengajuanPembayaranService $svc;
    private User $keuangan;
    private User $pemohon;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        $this->svc = new PengajuanPembayaranService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Divisi Umum', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => 'U1', 'nama_unit' => 'Yayasan']);
        CoaGroup::create(['kode_grup' => 'ZZUM', 'nama_grup' => 'Uji']);
        foreach ([[self::KAS, 'Bank Uji', 'debet'], [self::UM, 'Uang Muka Operasional', 'debet'],
            [self::BEBAN, 'Beban Pemeliharaan', 'debet'], [self::HUTANG, 'Hutang Penyelesaian', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => 'ZZUM', 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Bank Uji', 'status' => 'aktif']);

        $this->pemohon = User::create(['username' => 'zzum_p', 'nama' => 'Pemohon', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4])->refresh();
        $this->keuangan = User::create(['username' => 'zzum_k', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'tim_keuangan' => true, 'is_admin' => true])->refresh();

        $flow = ApprovalFlow::create(['kode_flow' => 'FUM', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    /** Uang muka Rp 10 juta yang sudah cair, siap diselesaikan. */
    private function uangMuka(string $nominal = '10000000'): OperationalAdvance
    {
        return OperationalAdvance::create([
            'nomor_ref' => 'UM-UJI-1', 'tanggal' => '2026-08-01', 'kode_unit' => 'U1',
            'kode_rekening' => self::KAS, 'kode_coa_uang_muka' => self::UM,
            'nama_coa_uang_muka' => 'Uang Muka Operasional', 'keterangan' => 'Uang muka bahan',
            'nominal' => $nominal, 'nominal_diselesaikan' => '0', 'sisa' => $nominal,
            'status' => 'outstanding', 'id_pengguna' => $this->pemohon->id_pengguna,
        ]);
    }

    /** Penyelesaian sebesar $realisasi atas uang muka itu, sudah disetujui rantai. */
    private function penyelesaian(OperationalAdvance $adv, string $realisasi): PengajuanPembayaran
    {
        $rec = $this->svc->create([
            'tanggal' => '2026-08-05', 'jenis' => 'penyelesaian_uang_muka', 'keterangan' => 'Penyelesaian bahan',
            'id_uang_muka' => $adv->id,
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => 'U1', 'nominal' => $realisasi]],
        ], $this->pemohon->id_pengguna);

        ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('id_dokumen', (string) $rec->id)->update(['status' => 'disetujui']);

        return $rec->refresh();
    }

    /** @return array<string,array{debet:string,kredit:string}> baris jurnal per akun */
    private function jurnal(PengajuanPembayaran $rec): array
    {
        $out = [];
        foreach (JournalLine::where('entry_id', $rec->fresh()->journal_entry_id)->get() as $l) {
            $out[$l->kode_coa] = ['debet' => Money::of($l->debet), 'kredit' => Money::of($l->kredit)];
        }

        return $out;
    }

    public function test_kurang_bayar_tidak_menyentuh_kas_dan_menahan_di_hutang(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000'); // kurang 2 juta

        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);
        $rec->refresh();

        $j = $this->jurnal($rec);
        $this->assertSame('12000000.00', $j[self::BEBAN]['debet'], 'Beban diakui penuh sebesar realisasi.');
        $this->assertSame('10000000.00', $j[self::UM]['kredit'], 'Uang muka dibersihkan.');
        $this->assertSame('2000000.00', $j[self::HUTANG]['kredit'], 'Kekurangan ditahan sebagai kewajiban.');
        // Inilah inti perubahannya: KAS TIDAK TERSENTUH saat posting.
        $this->assertArrayNotHasKey(self::KAS, $j, 'Kas tak boleh berkurang sebelum uangnya benar-benar keluar.');

        $this->assertSame('2000000.00', $rec->sisa_kurang_bayar);
        $this->assertSame('diposting', $rec->status);
    }

    public function test_kekurangan_muncul_sebagai_kewajiban_di_perintah_pembayaran(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000');
        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);

        $kewajiban = collect((new PerintahPembayaranService)->kewajibanTersedia())
            ->firstWhere('id_dokumen', $rec->id);

        $this->assertNotNull($kewajiban, 'Kekurangan penyelesaian harus bisa ditagih lewat Perintah Pembayaran.');
        // Yang ditagih KEKURANGANNYA, bukan nominal uang muka yang tersimpan di sisa_hutang.
        $this->assertSame('2000000.00', $kewajiban['sisa']);
        $this->assertSame('2000000.00', (new PerintahPembayaranService)->sisaKewajiban('pengajuan', $rec->id));
    }

    public function test_kelebihan_langsung_mengakui_kas_masuk(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '7500000'); // lebih 2,5 juta

        $this->svc->verifikasi($rec->id, null, $this->keuangan->id_pengguna, null, self::KAS);
        $rec->refresh();

        $j = $this->jurnal($rec);
        $this->assertSame('2500000.00', $j[self::KAS]['debet'], 'Kelebihan kembali sebagai kas masuk.');
        $this->assertSame('10000000.00', $j[self::UM]['kredit']);
        $this->assertSame('0.00', $rec->sisa_kurang_bayar);
        $this->assertSame('selesai', $rec->status, 'Tak ada yang perlu dibayar lagi.');
    }

    public function test_kurang_bayar_menolak_verifikasi_tanpa_akun_hutang(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000');

        $this->expectExceptionMessage('tentukan akun hutang penampung kekurangannya');
        // Rekening kas dioper, tetapi yang dibutuhkan justru akun hutang.
        $this->svc->verifikasi($rec->id, null, $this->keuangan->id_pengguna, null, self::KAS);
    }

    public function test_kelebihan_menolak_verifikasi_tanpa_rekening(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '7500000');

        $this->expectExceptionMessage('tentukan kas/rekening penerima pengembaliannya');
        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);
    }

    /**
     * Daftar pengajuan menampilkan KEWAJIBAN yang tersisa, bukan isi mentah
     * `sisa_hutang` — yang pada dokumen ini bernilai nominal uang mukanya.
     * Ditemukan pengguna: kolomnya menyebut 10 juta padahal yang harus dibayar
     * 2 juta, dan angka itu dibaca sebagai tagihan.
     */
    public function test_daftar_menampilkan_kekurangan_bukan_nominal_uang_muka(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000');
        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);

        $rec->refresh();
        $this->assertSame('10000000.00', $rec->sisa_hutang, 'Nilai mentahnya memang nominal uang muka.');
        $this->assertSame('2000000.00', $rec->sisaTagihan(), 'Yang ditampilkan harus kekurangannya.');

        $isi = preg_replace('/\s+/', ' ', $this->actingAs($this->keuangan)->get(route('pengajuan.index'))->assertOk()->content());
        $this->assertStringContainsString('2.000.000', $isi);
        $this->assertStringNotContainsString('Sisa Hutang', $isi, 'Judul kolom melayani dua jenis dokumen.');
    }

    /**
     * Dropdown "Pelunasan Pengajuan Pembayaran" di Kas Keluar menawarkan
     * KEKURANGANNYA. Salah di sini bukan sekadar salah tulis: angka itu ikut
     * mengisi isian Nominal, lalu service menolaknya karena menuntut pelunasan
     * penuh sebesar kekurangan yang sebenarnya — pembayarannya jadi buntu.
     */
    public function test_dropdown_kas_keluar_menawarkan_kekurangan_bukan_nominal_uang_muka(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000');
        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);

        // Blade menulis JSON-nya dengan kutip ter-escape (") — dinormalkan
        // dulu supaya yang diperiksa isinya, bukan cara menulisnya.
        $isi = str_replace(['\\u0022', '&quot;'], '"',
            $this->actingAs($this->keuangan)->get(route('cash_out.create'))->assertOk()->content());

        $this->assertStringContainsString('"sisa":"2000000.00"', $isi);
        $this->assertStringNotContainsString('"sisa":"10000000.00"', $isi);
    }

    /** Kekurangannya dibayar lewat Kas Keluar — barulah kas berkurang. */
    public function test_kekurangan_dibayar_lewat_kas_keluar(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000');
        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);

        $voucher = (new \App\Services\Modules\CashOutService)->create([
            'tanggal' => '2026-08-06', 'kode_unit' => 'U1', 'kode_rekening' => self::KAS,
            'keterangan' => 'Pelunasan kekurangan penyelesaian',
            'details' => [['tipe' => 'pengajuan', 'id_pengajuan' => $rec->id, 'nominal' => '2000000']],
        ], $this->keuangan->id_pengguna);

        // Kas Keluar menaut jurnalnya lewat sumber_modul + id_sumber, bukan kolom di recordnya.
        $entry = \App\Models\JournalEntry::where('sumber_modul', 'KasKeluar')
            ->where('id_sumber', (string) $voucher->kode_transaksi)->firstOrFail();
        $baris = [];
        foreach (JournalLine::where('entry_id', $entry->id)->get() as $l) {
            $baris[$l->kode_coa] = ['debet' => Money::of($l->debet), 'kredit' => Money::of($l->kredit)];
        }
        $this->assertSame('2000000.00', $baris[self::HUTANG]['debet'], 'Kewajibannya yang ditutup.');
        $this->assertSame('2000000.00', $baris[self::KAS]['kredit'], 'Kas baru berkurang sekarang.');

        $rec->refresh();
        $this->assertSame('0.00', $rec->sisa_kurang_bayar);
        $this->assertSame('selesai', $rec->status);
    }

    /** Pembayaran sebagian ditolak — sama seperti pelunasan pengajuan lain. */
    public function test_kekurangan_harus_dilunasi_penuh(): void
    {
        $adv = $this->uangMuka('10000000');
        $rec = $this->penyelesaian($adv, '12000000');
        $this->svc->verifikasi($rec->id, self::HUTANG, $this->keuangan->id_pengguna);

        $this->expectExceptionMessage('harus dilunasi PENUH');
        (new \App\Services\Modules\CashOutService)->create([
            'tanggal' => '2026-08-06', 'kode_unit' => 'U1', 'kode_rekening' => self::KAS,
            'keterangan' => 'Bayar sebagian',
            'details' => [['tipe' => 'pengajuan', 'id_pengajuan' => $rec->id, 'nominal' => '1000000']],
        ], $this->keuangan->id_pengguna);
    }
}
