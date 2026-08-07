<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\KoreksiTagihanService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KOREKSI SAMPAI NOL = penghapusan penuh.
 *
 * Dulu ditolak dengan alasan "menolkan tagihan bukan koreksi, itu pembatalan".
 * Keliru: pembatalan menarik tagihan SEBELUM ada jurnal, sedangkan penghapusan
 * lewat koreksi justru MENERBITKAN jurnal penyesuaian — piutangnya dibalik, dan
 * uang yang terlanjur dibayar pindah ke Dompet Wali.
 *
 * Statusnya `dihapus`, BUKAN `lunas`. Itu inti perbedaannya: sisa nol di sini
 * tidak berarti terbayar, dan menyebutnya lunas akan membuat tagihan yang tak
 * pernah dibayar terhitung tuntas di setiap rekap pembayaran.
 */
class KoreksiTagihanNolTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZNL';

    private const PIUTANG = '1.ZZNL.1';

    private const PENDAPATAN = '4.ZZNL.1';

    private const KAS = '1.ZZNL.9';

    private const TA = '2026/2027';

    private User $keuangan;

    private Santri $santri;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->keuangan = User::create([
            'username' => 'zznl_keu', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZNLU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Koreksi Nol Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'spp'],
            ['nama' => 'SPP', 'perilaku' => 'spp', 'urutan' => 3, 'bawaan' => true, 'status' => 'aktif']);
        JenisBiaya::create([
            'kode' => 'SPP-UJI', 'nama' => 'SPP Uji', 'tipe' => 'spp',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZNLU', 'status' => 'aktif', 'pengakuan' => 'akrual',
        ]);

        $this->wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak', 'telepon_ayah' => '0811',
            'nama' => 'Bapak', 'telepon' => '0811', 'status' => 'aktif',
        ]);
        $this->santri = Santri::create([
            'no_pendaftaran' => 'UJI-1', 'nis' => '660001', 'nama' => 'Santri Uji',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id,
        ]);
    }

    private function tagihan(string $nominal, ?string $periode = null): TagihanSantri
    {
        return TagihanSantri::create([
            'id_santri' => $this->santri->id, 'kode_jenis' => 'SPP-UJI', 'perilaku' => 'spp',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA, 'periode' => $periode,
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar', 'sudah_akrual' => true,
        ]);
    }

    private function saldo(string $coa): float
    {
        return (float) JournalLine::where('kode_coa', $coa)
            ->selectRaw('COALESCE(SUM(debet),0) - COALESCE(SUM(kredit),0) AS s')->value('s');
    }

    private function svc(): KoreksiTagihanService
    {
        return new KoreksiTagihanService;
    }

    public function test_koreksi_ke_nol_menghapus_tagihan_dan_membalik_piutangnya(): void
    {
        $t = $this->tagihan('1500000');

        $this->svc()->koreksi($t->id, '0', 'Salah terbit', $this->keuangan->id_pengguna);

        $t->refresh();
        $this->assertSame('dihapus', $t->status, 'Nol bukan "lunas" — tagihan ini tak pernah dibayar.');
        $this->assertSame(0, bccomp($t->sisa, '0', 2));
        $this->assertSame(0, bccomp($t->nominal, '0', 2));

        // Piutang dibalik penuh; pendapatan yang tak jadi ikut dikembalikan.
        $this->assertSame(-1500000.0, $this->saldo(self::PIUTANG));
        $this->assertSame(1500000.0, $this->saldo(self::PENDAPATAN));
    }

    public function test_yang_sudah_dibayar_kembali_ke_dompet_wali_saat_dihapus(): void
    {
        $t = $this->tagihan('1500000');
        PembayaranSantri::create([
            'nomor' => 'BYR-'.uniqid(), 'id_tagihan' => $t->id, 'id_santri' => $this->santri->id,
            'tanggal' => '2026-08-01', 'nominal' => '500000', 'metode' => 'tunai',
            'kode_rekening' => self::KAS, 'status' => 'terverifikasi',
            'dicatat_oleh' => $this->keuangan->id_pengguna,
        ]);
        $t->update(['sisa' => '1000000', 'status' => 'sebagian']);

        $hasil = $this->svc()->koreksi($t->id, '0', 'Dibatalkan sepihak', $this->keuangan->id_pengguna);

        $this->assertSame('dihapus', $t->refresh()->status);
        $this->assertSame(0, bccomp($hasil['koreksi']->kelebihan_ke_dompet, '500000', 2));
        $this->assertSame(0, bccomp(DompetWali::where('id_wali', $this->wali->id)->value('saldo'), '500000', 2));
    }

    public function test_tagihan_yang_dihapus_tidak_bisa_dibayar_lagi(): void
    {
        $t = $this->tagihan('1500000');
        $this->svc()->koreksi($t->id, '0', 'Salah terbit', $this->keuangan->id_pengguna);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah dihapus lewat koreksi/');
        (new PembayaranSantriService)->catat([
            'id_tagihan' => $t->id, 'id_santri' => $this->santri->id,
            'tanggal' => '2026-09-01', 'nominal' => '100000',
            'kode_rekening' => self::KAS, 'metode' => 'tunai',
        ], $this->keuangan->id_pengguna, 'kesantrian');
    }

    public function test_nominal_negatif_tetap_ditolak(): void
    {
        $t = $this->tagihan('1500000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak boleh negatif/');
        $this->svc()->koreksi($t->id, '-1000', 'Ngawur', $this->keuangan->id_pengguna);
    }

    public function test_tagihan_yang_dihapus_tak_lagi_memblokir_indeks_sekali_per_ta(): void
    {
        // Indeks parsial anti tagih-ganda `tagihan_santri_sekali_per_ta` semula
        // hanya mengecualikan `batal`. Tanpa penyesuaian, tagihan yang dihapus
        // akan menolak penerbitan ulang lewat pelanggaran indeks di tengah jalan
        // — bukan lewat pesan yang terbaca petugas.
        //
        // Diuji TANPA periode, karena itulah wilayah indeks tersebut: ia memakai
        // COALESCE(periode,'-') sehingga dua baris tanpa periode saling bentrok,
        // sedangkan indeks unik biasa (id_santri, kode_jenis, periode) justru
        // membiarkannya lewat — dua NULL dianggap berbeda oleh PostgreSQL.
        $t = $this->tagihan('1500000');
        $this->svc()->koreksi($t->id, '0', 'Salah nominal, terbitkan ulang', $this->keuangan->id_pengguna);

        $ulang = $this->tagihan('1750000');

        $this->assertNotSame($t->id, $ulang->id);
        $this->assertSame(2, TagihanSantri::where('id_santri', $this->santri->id)->count());
    }

    /**
     * Batas yang TIDAK berubah, supaya tak salah harap.
     *
     * Indeks unik biasa `(id_santri, kode_jenis, periode)` dari migrasi awal tak
     * mengenal status sama sekali. Tagihan berperiode karena itu tetap tak bisa
     * diterbitkan ulang setelah dihapus — persis seperti setelah dibatalkan.
     */
    public function test_tagihan_berperiode_tetap_tak_bisa_diterbitkan_ulang(): void
    {
        $t = $this->tagihan('1500000', '2026-08');
        $this->svc()->koreksi($t->id, '0', 'Salah nominal', $this->keuangan->id_pengguna);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->tagihan('1750000', '2026-08');
    }

    public function test_tagihan_yang_dihapus_masih_bisa_dikoreksi_naik_lagi(): void
    {
        // Penghapusan yang keliru tak perlu diterbitkan ulang dari nol —
        // menaikkannya kembali mempertahankan tautan ke tagihan aslinya.
        $t = $this->tagihan('1500000');
        $this->svc()->koreksi($t->id, '0', 'Terlanjur dihapus', $this->keuangan->id_pengguna);

        $this->svc()->koreksi($t->id, '1500000', 'Ternyata sah, dikembalikan', $this->keuangan->id_pengguna);

        $t->refresh();
        $this->assertSame('belum_bayar', $t->status);
        $this->assertSame(0, bccomp($t->sisa, '1500000', 2));
        // Dibalik lalu dikembalikan ⇒ buku besar kembali ke posisi semula.
        $this->assertSame(0.0, $this->saldo(self::PIUTANG));
        $this->assertSame(0.0, $this->saldo(self::PENDAPATAN));
    }
}
