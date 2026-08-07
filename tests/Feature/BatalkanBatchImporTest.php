<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ImporBatch;
use App\Models\NisSantri;
use App\Models\PembayaranSantri;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Services\Impor\ImporSaldoAwal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PEMBATALAN BATCH IMPOR.
 *
 * Sebelum ini, satu berkas keliru berisi ratusan santri hanya punya dua jalan
 * keluar: membetulkan satu per satu lewat layar — dan tunggakannya bahkan tak
 * bisa dibatalkan sama sekali — atau `dummy:hapus` yang membuang SELURUH santri
 * termasuk yang benar.
 *
 * Yang dijaga di sini bukan cuma "barisnya terhapus", melainkan BATAS-nya:
 * wali yang sudah ada sebelum impor tidak ikut terhapus, dan begitu ada
 * pembayaran atau pekerjaan lain menempel, pembatalannya menolak dan menyebut
 * SEMUA alasannya sekaligus.
 */
class BatalkanBatchImporTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZBT';

    private string $jenisTunggakan = 'TUNGGAKAN-SPP';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzbt_admin', 'nama' => 'Admin Batch', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        JalurPendaftaran::create(['kode' => 'LAMA', 'nama' => 'Santri Lama', 'status' => 'aktif']);

        BusinessUnit::create(['kode_unit' => 'ZZBTU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Batch Uji']);
        CoaDetail::create(['kode_coa' => '4.ZZBT.1', 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZBT.1', 'nama_coa' => 'Piutang Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        TipeBiaya::firstOrCreate(
            ['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif'],
        );
        JenisBiaya::create([
            'kode' => $this->jenisTunggakan, 'nama' => 'Tunggakan SPP (saldo awal)', 'tipe' => 'lain',
            'kode_coa_pendapatan' => '4.ZZBT.1', 'kode_coa_piutang' => '1.ZZBT.1',
            'kode_unit' => 'ZZBTU', 'status' => 'aktif',
        ]);
    }

    /** @param list<array<string,string>> $baris */
    private function berkas(array $baris): string
    {
        $kolom = ['nis', 'nama', 'jenis_kelamin', 'kode_jenjang', 'tingkat', 'tahun_ajaran', 'jalur',
            'wali_nama', 'wali_telepon', 'tunggakan_spp', 'ket_tunggakan_spp'];
        $isi = implode(',', $kolom)."\n";
        foreach ($baris as $b) {
            $isi .= implode(',', array_map(fn ($k) => $b[$k] ?? '', $kolom))."\n";
        }

        $path = sys_get_temp_dir().'/batch-uji-'.uniqid().'.csv';
        file_put_contents($path, $isi);

        return $path;
    }

    private function baris(array $ganti = []): array
    {
        return array_merge([
            'nis' => '230015', 'nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'tingkat' => '2', 'tahun_ajaran' => self::TA, 'jalur' => 'LAMA',
            'wali_nama' => 'Bapak Fauzi', 'wali_telepon' => '08123456789',
        ], $ganti);
    }

    private function param(): array
    {
        return ['jenis_tunggakan_spp' => $this->jenisTunggakan, 'jenis_tunggakan_uang_pangkal' => ''];
    }

    private function impor(array $baris): ImporBatch
    {
        $hasil = app(ImporSaldoAwal::class)->jalankan('santri-lama', $this->berkas($baris), $this->param());

        return ImporBatch::findOrFail($hasil['id_batch']);
    }

    public function test_impor_mencatat_batchnya(): void
    {
        $batch = $this->impor([$this->baris()]);

        $this->assertSame('santri-lama', $batch->kunci);
        $this->assertSame(1, $batch->ringkasan['santri']);
        $this->assertTrue($batch->aktif());
        $this->assertSame($batch->id, Santri::sole()->id_batch);
    }

    /** Membatalkan membuang santri beserta segala yang menggantung padanya. */
    public function test_pembatalan_membuang_seluruh_baris_batch(): void
    {
        $batch = $this->impor([
            $this->baris(),
            $this->baris(['nis' => '230016', 'nama' => 'Budi', 'wali_telepon' => '08222', 'wali_nama' => 'Bapak Budi',
                'tunggakan_spp' => '500000', 'ket_tunggakan_spp' => 'Tunggakan SPP']),
        ]);

        $this->assertSame(2, Santri::count());
        $this->assertSame(1, TagihanSantri::count());

        app(ImporSaldoAwal::class)->batalkanBatch($batch->id, 'Berkas salah kolom');

        $this->assertSame(0, Santri::count());
        $this->assertSame(0, TagihanSantri::count());
        $this->assertSame(0, NisSantri::count());
        $this->assertSame(0, RiwayatTingkat::count());
        $this->assertSame(0, Wali::count());
        $this->assertFalse($batch->refresh()->aktif());
        $this->assertSame('Berkas salah kolom', $batch->alasan_batal);
    }

    /**
     * WALI YANG SUDAH ADA SEBELUM IMPOR TIDAK IKUT TERHAPUS.
     *
     * Impor memakai ulang wali yang teleponnya sudah dikenal — ia bisa saja
     * menaungi anak dari angkatan sebelumnya. Menghapusnya bersama batch berarti
     * membuang keluarga yang tak ada hubungannya dengan berkas yang keliru.
     */
    public function test_wali_lama_tidak_ikut_terhapus(): void
    {
        $lama = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Fauzi', 'telepon_ayah' => '08123456789',
            'nama' => 'Bapak Fauzi', 'telepon' => '08123456789', 'status' => 'aktif',
        ]);

        $batch = $this->impor([$this->baris()]);

        // Santrinya menempel ke wali yang sudah ada, bukan wali baru.
        $this->assertSame($lama->id, Santri::sole()->id_wali);
        $this->assertNull($lama->refresh()->id_batch);

        app(ImporSaldoAwal::class)->batalkanBatch($batch->id, 'Salah berkas');

        $this->assertSame(0, Santri::count());
        $this->assertNotNull(Wali::find($lama->id), 'wali yang sudah ada sebelum impor wajib tetap ada');
    }

    /** Begitu ada pembayaran, pembatalan menolak — itu catatan uang yang nyata. */
    public function test_batch_dengan_pembayaran_tidak_bisa_dibatalkan(): void
    {
        $batch = $this->impor([$this->baris(['tunggakan_spp' => '500000', 'ket_tunggakan_spp' => 'SPP'])]);
        $tagihan = TagihanSantri::sole();

        PembayaranSantri::create([
            'nomor' => 'BYR-UJI-1', 'id_tagihan' => $tagihan->id, 'id_santri' => $tagihan->id_santri,
            'tanggal' => '2026-08-01', 'nominal' => '100000', 'metode' => 'tunai',
            'kode_rekening' => '1.ZZBT.1', 'status' => 'menunggu_verifikasi',
            'dicatat_oleh' => $this->admin->id_pengguna,
        ]);

        $halangan = app(ImporSaldoAwal::class)->halanganBatalBatch($batch->refresh());
        $this->assertNotEmpty($halangan);
        $this->assertStringContainsString('pembayaran', $halangan[0]);

        try {
            app(ImporSaldoAwal::class)->batalkanBatch($batch->id, 'Coba batalkan');
            $this->fail('batch berpembayaran seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('tidak bisa dibatalkan', $e->getMessage());
        }

        $this->assertSame(1, Santri::count(), 'tak ada yang boleh terhapus saat ditolak');
    }

    /**
     * Tagihan yang diterbitkan petugas SESUDAH impor menghalangi pembatalan —
     * membuangnya bersama batch akan menghapus pekerjaan yang tak ada
     * hubungannya dengan berkas yang keliru.
     *
     * Dibedakan lewat PENANDA batch, bukan perbandingan waktu. Versi pertama
     * penjagaan ini memakai `created_at > dijalankan_pada`, dan karena catatan
     * batch dibuat SEBELUM barisnya disimpan, tagihan dari impor itu sendiri
     * selalu bertanggal sesudahnya — impor jadi menolak membatalkan dirinya
     * sendiri. Di lari tunggal lolos, di suite paralel ketahuan.
     */
    public function test_tagihan_di_luar_impor_menghalangi_pembatalan(): void
    {
        $batch = $this->impor([$this->baris(['tunggakan_spp' => '500000', 'ket_tunggakan_spp' => 'SPP'])]);

        // Tunggakan hasil impor TIDAK boleh dianggap sebagai tagihan luar.
        $this->assertSame([], app(ImporSaldoAwal::class)->halanganBatalBatch($batch->refresh()));

        // Tagihan yang diterbitkan kemudian — tanpa penanda batch.
        TagihanSantri::create([
            'id_santri' => Santri::sole()->id, 'kode_jenis' => $this->jenisTunggakan, 'perilaku' => 'lain',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => '250000', 'sisa' => '250000', 'status' => 'belum_bayar', 'sudah_akrual' => false,
        ]);

        $halangan = app(ImporSaldoAwal::class)->halanganBatalBatch($batch->refresh());
        $this->assertNotEmpty($halangan);
        $this->assertStringContainsString('di luar impor ini', $halangan[0]);
    }

    /** Alasan wajib — ia yang menjawab "kenapa ratusan baris ini hilang?". */
    public function test_alasan_wajib_diisi(): void
    {
        $batch = $this->impor([$this->baris()]);

        try {
            app(ImporSaldoAwal::class)->batalkanBatch($batch->id, '   ');
            $this->fail('alasan kosong seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('Alasan pembatalan wajib', $e->getMessage());
        }

        $this->assertSame(1, Santri::count());
    }

    /**
     * Jalur HTTP-nya — yang sesungguhnya dipakai petugas.
     *
     * Haknya `hapus`, bukan `buat`: menjalankan impor dan membatalkannya adalah
     * dua wewenang berbeda. Yang boleh memasukkan ratusan baris belum tentu
     * boleh membuangnya lagi.
     */
    public function test_layar_menampilkan_riwayat_dan_membatalkan_lewat_http(): void
    {
        $batch = $this->impor([$this->baris()]);

        $this->actingAs($this->admin)->get(route('impor_data_awal.index', ['jenis' => 'santri-lama']))
            ->assertOk()
            ->assertSee('Riwayat Impor')
            ->assertSee('Batalkan Impor');

        $this->actingAs($this->admin)
            ->delete(route('impor_data_awal.batalkan_batch', $batch->id), ['alasan' => 'Kolom tingkat tertukar'])
            ->assertRedirect();

        $this->assertSame(0, Santri::count());
        $this->assertSame('Kolom tingkat tertukar', $batch->refresh()->alasan_batal);
    }

    /** Tanpa hak `hapus`, tombolnya tak muncul dan rutenya menolak. */
    public function test_tanpa_hak_hapus_pembatalan_ditolak(): void
    {
        $batch = $this->impor([$this->baris()]);

        $petugas = User::create([
            'username' => 'zzbt_ptg', 'nama' => 'Petugas', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        \App\Models\HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'impor-data-awal',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true,
        ]);

        $this->actingAs($petugas)->get(route('impor_data_awal.index', ['jenis' => 'santri-lama']))
            ->assertOk()
            ->assertDontSee('Batalkan Impor');

        $this->actingAs($petugas)
            ->delete(route('impor_data_awal.batalkan_batch', $batch->id), ['alasan' => 'Coba'])
            ->assertForbidden();

        $this->assertSame(1, Santri::count());
    }

    /** Batch yang sudah dibatalkan tak bisa dibatalkan dua kali. */
    public function test_batch_yang_sudah_dibatalkan_ditolak(): void
    {
        $batch = $this->impor([$this->baris()]);
        app(ImporSaldoAwal::class)->batalkanBatch($batch->id, 'Sekali');

        try {
            app(ImporSaldoAwal::class)->batalkanBatch($batch->id, 'Dua kali');
            $this->fail('pembatalan kedua seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('sudah dibatalkan', $e->getMessage());
        }
    }
}
