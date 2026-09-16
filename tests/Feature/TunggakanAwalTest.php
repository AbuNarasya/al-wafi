<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
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
use App\Services\Modules\TunggakanAwalService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TUNGGAKAN AWAL — pintu manual saldo awal, di samping pintu impor.
 *
 * Yang dijaga di sini bukan "barisnya tersimpan", melainkan tiga hal yang kalau
 * meleset akan merusak pembukuan tanpa bersuara:
 *
 *   1. TIDAK ADA jurnal yang terbit. Nilainya sudah diakui sebagai pendapatan di
 *      pembukuan lama; menjurnalnya lagi berarti mengakui pendapatan dua kali.
 *   2. Barisnya BERTANDA `saldo_awal`, sehingga tombol hapus-tanpa-jurnal tak
 *      pernah menyentuh tagihan yang justru PUNYA jurnal.
 *   3. Pintunya TERTUTUP begitu ada uang masuk — termasuk uang yang masuk tanpa
 *      meninggalkan baris pembayaran sama sekali (auto-debet & SPP prabayar).
 */
class TunggakanAwalTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTA';

    private const PIUTANG = '1.ZZTA.1';

    private const PENDAPATAN = '4.ZZTA.1';

    private const TA_LAMA = '2024/2025';

    private const TA = '2026/2027';

    private User $admin;

    private Santri $santri;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzta_admin', 'nama' => 'Admin Uji', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA_LAMA, 'nama' => 'TA Lama']);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Berjalan']);
        BusinessUnit::create(['kode_unit' => 'ZZTAU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Tunggakan Awal Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        TipeBiaya::firstOrCreate(['kode' => 'spp'],
            ['nama' => 'SPP', 'perilaku' => 'spp', 'urutan' => 5, 'bawaan' => true, 'status' => 'aktif']);

        $this->buatJenis('WARISAN', 'Tunggakan Warisan', 'lain', self::PIUTANG);

        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Uji', 'telepon_ayah' => '0811',
            'nama' => 'Bapak Uji', 'telepon' => '0811', 'status' => 'aktif',
        ]);
        $this->santri = Santri::create([
            'no_pendaftaran' => 'UJI-0001', 'nis' => '990001', 'nama' => 'Santri Uji',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    private function buatJenis(string $kode, string $nama, string $tipe, ?string $piutang): JenisBiaya
    {
        return JenisBiaya::create([
            'kode' => $kode, 'nama' => $nama, 'tipe' => $tipe,
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => $piutang,
            'kode_unit' => 'ZZTAU', 'kode_jenjang' => 'SMP', 'status' => 'aktif',
        ]);
    }

    private function svc(): TunggakanAwalService
    {
        return new TunggakanAwalService;
    }

    /** @param array<string,mixed> $timpa */
    private function tambah(array $timpa = []): TagihanSantri
    {
        return $this->svc()->tambah($this->santri->id, $timpa + [
            'kode_jenis' => 'WARISAN',
            'tahun_ajaran' => self::TA_LAMA,
            'nominal' => '1500000',
            'keterangan' => 'Tunggakan SPP Jan–Jun 2025',
        ]);
    }

    /**
     * Inti seluruh fitur: barisnya berakrual, TAPI buku besar tak bergerak
     * sedikit pun. Keduanya harus benar bersamaan — berakrual tanpa jurnal
     * adalah yang membuat pembayarannya kelak mengkredit Piutang, dan
     * tiadanya jurnal adalah yang mencegah pendapatan lama diakui dua kali.
     */
    public function test_tercatat_berakrual_tanpa_menerbitkan_jurnal(): void
    {
        $t = $this->tambah();

        $this->assertTrue($t->sudah_akrual, 'pembayarannya kelak harus mengkredit Piutang, bukan Pendapatan');
        $this->assertTrue($t->saldo_awal);
        $this->assertSame(1500000.0, (float) $t->nominal);
        $this->assertSame(1500000.0, (float) $t->sisa);
        $this->assertSame('belum_bayar', $t->status);
        $this->assertSame('lain', $t->perilaku);
        $this->assertSame('SMP', $t->kode_jenjang, 'jenjang disalin dari santrinya');
        $this->assertNull($t->periode, 'tunggakan warisan menggumpal, bukan tagihan bulan tertentu');

        $this->assertSame(0, JournalLine::count(), 'saldo awal TIDAK pernah menjurnal — buku besarnya lewat menu Saldo Awal');
    }

    /** Tahun ajaran adalah tahun ASAL tunggakannya, supaya aging piutang jujur. */
    public function test_tahun_ajaran_yang_dicap_adalah_pilihan_petugas(): void
    {
        $t = $this->tambah();

        $this->assertSame(self::TA_LAMA, $t->tahun_ajaran);
        $this->assertNotSame($this->santri->tahun_ajaran_berjalan, $t->tahun_ajaran);
    }

    /**
     * Ditolak DI MUKA, bukan saat dibayar. Tanpa akun piutang, pembayaran atas
     * baris berakrual akan gagal berbulan-bulan kemudian di layar yang sama
     * sekali lain — jauh dari orang yang membuatnya.
     */
    public function test_jenis_tanpa_akun_piutang_ditolak_sejak_awal(): void
    {
        $this->buatJenis('TANPA-PIUTANG', 'Jenis Tanpa Piutang', 'lain', null);

        try {
            $this->tambah(['kode_jenis' => 'TANPA-PIUTANG']);
            $this->fail('seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('akun piutang', $e->getMessage());
        }

        $this->assertSame(0, TagihanSantri::count());
    }

    public function test_nominal_nol_ditolak(): void
    {
        $this->expectException(AppException::class);
        $this->tambah(['nominal' => '0']);
    }

    /**
     * Tabrakan indeks unik parsial dijelaskan dengan kalimat yang bisa dibaca,
     * bukan dibiarkan meledak jadi SQLSTATE[23505] di tengah jalan.
     */
    public function test_bentrok_sekali_per_tahun_ditolak_dengan_kalimat_manusia(): void
    {
        $this->buatJenis('SPP-SMP', 'SPP SMP', 'spp', self::PIUTANG);
        $this->tambah(['kode_jenis' => 'SPP-SMP']);

        try {
            $this->tambah(['kode_jenis' => 'SPP-SMP']);
            $this->fail('perilaku spp hanya boleh sekali per tahun ajaran');
        } catch (AppException $e) {
            $this->assertStringContainsString('sekali per tahun ajaran', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $this->assertSame(1, TagihanSantri::count());
    }

    /** Perilaku `lain` memang boleh berkali-kali — tunggakan insidental tak tunggal. */
    public function test_perilaku_lain_boleh_lebih_dari_satu(): void
    {
        $this->tambah();
        $this->tambah(['keterangan' => 'Sisa seragam & buku']);

        $this->assertSame(2, TagihanSantri::count());
    }

    public function test_boleh_diubah_dan_dihapus_selama_belum_tersentuh(): void
    {
        $t = $this->tambah();

        $this->assertSame([], TunggakanAwalService::halangan($t));

        $t = $this->svc()->ubah($t->id, ['nominal' => '900000', 'keterangan' => 'Setelah dicek ulang']);
        $this->assertSame(900000.0, (float) $t->nominal);
        $this->assertSame(900000.0, (float) $t->sisa, 'sisa ikut, karena belum sepeser pun terbayar');
        $this->assertSame(0, JournalLine::count(), 'membetulkan salah ketik bukan peristiwa akuntansi');

        $this->svc()->hapus($t->id);
        $this->assertSame(0, TagihanSantri::count());
        $this->assertSame(0, JournalLine::count());
    }

    /**
     * Setoran yang MASIH menunggu verifikasi belum mengurangi `sisa` — kalau
     * penjaganya hanya melihat sisa, baris ini akan tampak perawan dan bisa
     * dihapus di bawah kaki setoran yang sudah dicatat petugas.
     */
    public function test_pembayaran_yang_belum_terverifikasi_pun_menutup_pintu(): void
    {
        $t = $this->tambah();
        PembayaranSantri::create([
            'nomor' => 'BYR-'.uniqid(), 'id_tagihan' => $t->id, 'id_santri' => $t->id_santri,
            'tanggal' => '2026-08-01', 'nominal' => '100000', 'metode' => 'tunai',
            'kode_rekening' => self::PIUTANG, 'status' => 'menunggu_verifikasi',
            'dicatat_oleh' => $this->admin->id_pengguna,
        ]);

        $this->assertNotSame([], TunggakanAwalService::halangan($t->refresh()));

        $this->expectException(AppException::class);
        $this->svc()->hapus($t->id);
    }

    /**
     * Auto-debet dompet & SPP prabayar mengurangi `sisa` TANPA meninggalkan
     * baris pembayaran. Penjaga yang hanya menghitung baris akan melewatkannya
     * — dan menghapus tagihan yang uangnya sudah diterima.
     */
    public function test_uang_masuk_tanpa_baris_pembayaran_tetap_menutup_pintu(): void
    {
        $t = $this->tambah();
        $t->update(['sisa' => '1400000', 'status' => 'sebagian']);

        $this->assertSame(0, PembayaranSantri::count(), 'memang tak ada baris pembayaran — itulah keadaan yang diuji');
        $this->assertNotSame([], TunggakanAwalService::halangan($t->refresh()));

        $this->expectException(AppException::class);
        $this->svc()->ubah($t->id, ['nominal' => '500000', 'keterangan' => 'coba ubah']);
    }

    /**
     * Penjaga terpenting: tagihan biasa PUNYA jurnal. Menghapusnya lewat pintu
     * ini akan meninggalkan piutang di buku besar tanpa lawan di buku pembantu.
     */
    public function test_tagihan_berjurnal_tak_bisa_disentuh_pintu_ini(): void
    {
        $biasa = TagihanSantri::create([
            'id_santri' => $this->santri->id, 'kode_jenis' => 'WARISAN', 'perilaku' => 'lain',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => '250000', 'sisa' => '250000', 'status' => 'belum_bayar',
            'sudah_akrual' => true, 'saldo_awal' => false,
        ]);

        $halangan = TunggakanAwalService::halangan($biasa);
        $this->assertNotSame([], $halangan);
        $this->assertStringContainsString('Koreksi Nominal Tagihan', implode(' ', $halangan));

        $this->expectException(AppException::class);
        $this->svc()->hapus($biasa->id);
    }

    /** Alur layar: tercatat, dan petugas DIINGATKAN bahwa neraca belum ikut bergerak. */
    public function test_alur_layar_mencatat_dan_mengingatkan_jurnal_pembuka(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tunggakan_awal.store', $this->santri->id), [
                'kode_jenis' => 'WARISAN',
                'tahun_ajaran' => self::TA_LAMA,
                'nominal_tunggakan' => '1500000',
                'keterangan' => 'Tunggakan SPP Jan–Jun 2025',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($p) => str_contains($p, 'Saldo Awal'));

        $this->assertSame(1, TagihanSantri::where('saldo_awal', true)->count());
        $this->assertSame(0, JournalLine::count());

        $this->actingAs($this->admin)->get(route('santri.show', $this->santri->id))
            ->assertOk()
            ->assertSee('Tunggakan Awal')
            ->assertSee('saldo awal');
    }

    /**
     * Wewenangnya menumpang `impor-data-awal`. Yang hanya boleh melihat santri
     * tak melihat tombolnya, dan rutenya pun menolaknya — tombol yang
     * disembunyikan saja tak pernah cukup.
     */
    public function test_tanpa_hak_impor_tombolnya_tak_ada_dan_rutenya_ditolak(): void
    {
        $staf = User::create(['username' => 'zzta_staf', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        HakAksesModul::create(['id_pengguna' => $staf->id_pengguna, 'kode_modul' => 'santri',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $this->actingAs($staf)->get(route('santri.show', $this->santri->id))
            ->assertOk()
            ->assertDontSee('Tunggakan Awal');

        $this->actingAs($staf)->post(route('tunggakan_awal.store', $this->santri->id), [
            'kode_jenis' => 'WARISAN',
            'tahun_ajaran' => self::TA_LAMA,
            'nominal_tunggakan' => '1500000',
            'keterangan' => 'Tunggakan SPP',
        ])->assertForbidden();

        $this->assertSame(0, TagihanSantri::count());
    }
}
