<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\LampiranDokumen;
use App\Models\Level;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use App\Support\SumberLampiran;
use App\Support\Unggahan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MembuatLevelPengajuan;
use Tests\TestCase;

/**
 * LAMPIRAN DOKUMEN KEUANGAN — bukti pendukung pengajuan pembayaran, uang muka,
 * dan penyelesaian uang muka.
 *
 * Yang dijaga di sini:
 *
 *  1. Berkasnya benar-benar tersimpan & terbaca kembali — bukan cuma barisnya.
 *  2. Batas ukuran SATU angka untuk seluruh aplikasi. Batas yang berbeda-beda
 *     per layar membuat orang tak pernah tahu berapa besar yang boleh.
 *  3. Sesudah keuangan memverifikasi, lampiran TERKUNCI. Bukti yang sudah
 *     dibaca penyetuju tak boleh bisa ditukar diam-diam sesudahnya — itu modus
 *     yang paling sulit ketahuan saat audit.
 *  4. Berkas hanya bisa diambil oleh yang berhak atas modul induknya. Tak ada
 *     berkas yang bisa diunduh cuma karena nomornya ditebak.
 */
class LampiranDokumenTest extends TestCase
{
    use MembuatLevelPengajuan;
    use RefreshDatabase;

    private const GRP = 'ZZLM';

    private const BEBAN = '5.ZZLM.1';

    private const UNIT = 'ZZLMU';

    private User $staff;

    private User $orangLain;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        Storage::fake('local');

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->buatLevelPengajuan(3, 'Mudir Bagian');
        $this->buatLevelPengajuan(4, 'Staff');
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian 1', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Lampiran Test']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4, 'status' => 'aktif']);
        // Punya akun, TIDAK punya hak modul apa pun.
        $this->orangLain = User::create(['username' => 'lain', 'nama' => 'Orang Lain', 'password_hash' => 'x',
            'kode_level' => 'L1', 'status' => 'aktif']);

        foreach (['pengajuan-pembayaran', 'operational-advance', 'advance-settlement'] as $modul) {
            HakAksesModul::create(['id_pengguna' => $this->staff->id_pengguna, 'kode_modul' => $modul,
                'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true]);
        }

        $flow = ApprovalFlow::create(['kode_flow' => 'FPP', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    /** @param array<string,mixed> $tambahan */
    private function buatPengajuan(array $tambahan = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->post(route('pengajuan.store'), [
            'jenis' => 'pembayaran',
            'tanggal' => '2026-08-11',
            'keterangan' => 'Beli ATK',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '100000']],
            ...$tambahan,
        ]);
    }

    private function uangMuka(string $status = 'outstanding'): OperationalAdvance
    {
        return OperationalAdvance::create([
            'nomor_ref' => 'UMB-2608-0001', 'tanggal' => '2026-08-11', 'kode_unit' => self::UNIT,
            'kode_rekening' => '1.ZZLM.9', 'kode_coa_uang_muka' => '1.ZZLM.8', 'nama_coa_uang_muka' => 'Uang Muka',
            'keterangan' => 'UM belanja', 'nominal' => '500000', 'status' => $status,
            'id_pengguna' => $this->staff->id_pengguna,
        ]);
    }

    /** Berkas ikut form "Buat Pengajuan" — barisnya ada DAN isinya tersimpan. */
    public function test_lampiran_terkirim_bersama_pengajuan(): void
    {
        $this->buatPengajuan([
            'lampiran' => [
                UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
                UploadedFile::fake()->image('nota.jpg'),
            ],
        ])->assertRedirect();

        $rec = PengajuanPembayaran::firstOrFail();
        $rows = LampiranDokumen::where('jenis_dokumen', SumberLampiran::PENGAJUAN)
            ->where('id_dokumen', (string) $rec->id)->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['invoice.pdf', 'nota.jpg'], $rows->pluck('nama_asli')->all());
        // Berkasnya benar-benar ada di disk, bukan cuma barisnya di database.
        foreach ($rows as $r) {
            Storage::disk('local')->assertExists($r->path);
            $this->assertSame($this->staff->id_pengguna, $r->diunggah_oleh);
            $this->assertNotEmpty($r->hash_sha256);
        }
    }

    /**
     * Berkas melebihi batas ditolak — DAN pengajuannya sendiri ikut ditolak,
     * karena validasinya berjalan sebelum dokumen terbit. Yang tak boleh
     * terjadi: pengajuan terbit tanpa kabar bahwa lampirannya tak masuk.
     */
    public function test_berkas_melebihi_batas_ditolak(): void
    {
        $this->buatPengajuan([
            'lampiran' => [UploadedFile::fake()->create('besar.pdf', Unggahan::MAKS_KB + 100, 'application/pdf')],
        ])->assertSessionHasErrors('lampiran.0');

        $this->assertSame(0, PengajuanPembayaran::count());
        $this->assertSame(0, LampiranDokumen::count());
    }

    /** Tipe berkas di luar daftar ditolak — .exe tak pernah jadi bukti pembayaran. */
    public function test_tipe_berkas_asing_ditolak(): void
    {
        $this->buatPengajuan([
            'lampiran' => [UploadedFile::fake()->create('virus.exe', 10)],
        ])->assertSessionHasErrors('lampiran.0');

        $this->assertSame(0, LampiranDokumen::count());
    }

    /** Unggah susulan dari halaman lampiran, selama dokumennya masih terbuka. */
    public function test_unggah_susulan_dan_hapus(): void
    {
        $this->buatPengajuan();
        $rec = PengajuanPembayaran::firstOrFail();

        $this->actingAs($this->staff)
            ->post(route('lampiran.store', [SumberLampiran::PENGAJUAN, $rec->id]), [
                'berkas' => UploadedFile::fake()->create('kwitansi.pdf', 50, 'application/pdf'),
                'keterangan' => 'Kwitansi toko',
            ])->assertRedirect(route('lampiran.index', [SumberLampiran::PENGAJUAN, $rec->id]));

        $row = LampiranDokumen::firstOrFail();
        $this->assertSame('Kwitansi toko', $row->keterangan);
        $path = $row->path;

        // Hapus: baris DAN berkasnya hilang bersama, tak meninggalkan sampah.
        $this->actingAs($this->staff)->delete(route('lampiran.destroy', $row->id))->assertRedirect();
        $this->assertSame(0, LampiranDokumen::count());
        Storage::disk('local')->assertMissing($path);
    }

    /**
     * TERKUNCI sesudah keuangan memverifikasi. Ini inti aturannya: bukti yang
     * sudah jadi dasar persetujuan tak boleh ditambah maupun dibuang.
     */
    public function test_terkunci_setelah_diverifikasi(): void
    {
        $this->buatPengajuan([
            'lampiran' => [UploadedFile::fake()->create('invoice.pdf', 30, 'application/pdf')],
        ]);
        $rec = PengajuanPembayaran::firstOrFail();
        $lama = LampiranDokumen::firstOrFail();

        $rec->update(['status' => 'diposting']);

        // Tambah ditolak.
        $this->actingAs($this->staff)
            ->post(route('lampiran.store', [SumberLampiran::PENGAJUAN, $rec->id]), [
                'berkas' => UploadedFile::fake()->create('susulan.pdf', 20, 'application/pdf'),
            ])->assertSessionHas('error');
        $this->assertSame(1, LampiranDokumen::count());

        // Hapus ditolak — yang lama tetap utuh.
        $this->actingAs($this->staff)->delete(route('lampiran.destroy', $lama->id))->assertSessionHas('error');
        $this->assertSame(1, LampiranDokumen::count());
        Storage::disk('local')->assertExists($lama->path);

        // Tapi masih boleh DILIHAT & diunduh — ia memang bukti pembukuan.
        $this->actingAs($this->staff)->get(route('lampiran.unduh', $lama->id))->assertOk();
        $this->actingAs($this->staff)->get(route('lampiran.index', [SumberLampiran::PENGAJUAN, $rec->id]))
            ->assertOk()->assertSee('bagian bukti pembukuan');
    }

    /** Tanpa hak modul induknya, berkas tak bisa diambil walau nomornya ditebak. */
    public function test_tanpa_hak_modul_tidak_bisa_menyentuh_berkas(): void
    {
        $this->buatPengajuan([
            'lampiran' => [UploadedFile::fake()->create('invoice.pdf', 30, 'application/pdf')],
        ]);
        $rec = PengajuanPembayaran::firstOrFail();
        $row = LampiranDokumen::firstOrFail();

        foreach ([
            route('lampiran.index', [SumberLampiran::PENGAJUAN, $rec->id]),
            route('lampiran.berkas', $row->id),
            route('lampiran.unduh', $row->id),
        ] as $url) {
            $this->actingAs($this->orangLain)->get($url)->assertForbidden();
        }

        $this->actingAs($this->orangLain)->delete(route('lampiran.destroy', $row->id))->assertForbidden();
        $this->assertSame(1, LampiranDokumen::count());
    }

    /** Berkas disajikan inline untuk pratinjau, dengan nama aslinya. */
    public function test_berkas_disajikan_inline(): void
    {
        $this->buatPengajuan(['lampiran' => [UploadedFile::fake()->create('invoice.pdf', 30, 'application/pdf')]]);
        $row = LampiranDokumen::firstOrFail();

        $this->actingAs($this->staff)->get(route('lampiran.berkas', $row->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="invoice.pdf"');
    }

    /** Uang muka operasional ikut bisa dilampiri — dan terkunci setelah void. */
    public function test_uang_muka_operasional_bisa_dilampiri(): void
    {
        $um = $this->uangMuka();

        $this->actingAs($this->staff)
            ->post(route('lampiran.store', [SumberLampiran::UANG_MUKA, $um->id]), [
                'berkas' => UploadedFile::fake()->image('nota-belanja.png'),
            ])->assertRedirect();
        $this->assertSame(1, LampiranDokumen::where('jenis_dokumen', SumberLampiran::UANG_MUKA)->count());

        $um->update(['status' => 'void']);
        $this->actingAs($this->staff)
            ->post(route('lampiran.store', [SumberLampiran::UANG_MUKA, $um->id]), [
                'berkas' => UploadedFile::fake()->image('lagi.png'),
            ])->assertSessionHas('error');
        $this->assertSame(1, LampiranDokumen::where('jenis_dokumen', SumberLampiran::UANG_MUKA)->count());
    }

    /**
     * Ketiga halaman DAFTAR ikut membawa jumlah lampiran per baris.
     *
     * Dua di antaranya (uang muka operasional & penyelesaian) sebelum ini tak
     * pernah disentuh test mana pun, jadi variabel baru di layarnya tak akan
     * ketahuan salah sampai ada yang membukanya di produksi.
     */
    public function test_ketiga_halaman_daftar_menampilkan_jumlah_lampiran(): void
    {
        $this->buatPengajuan(['lampiran' => [UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')]]);
        $um = $this->uangMuka();
        $this->actingAs($this->staff)->post(route('lampiran.store', [SumberLampiran::UANG_MUKA, $um->id]), [
            'berkas' => UploadedFile::fake()->image('nota.png'),
        ]);

        // Penyelesaian uang muka: rekening tujuannya ber-kunci asing ke bank_accounts.
        CoaDetail::create(['kode_coa' => '1.ZZLM.9', 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        \App\Models\BankAccount::create(['kode_coa' => '1.ZZLM.9', 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        $pum = \App\Models\AdvanceSettlement::create([
            'tanggal' => '2026-08-11', 'kode_coa_uang_muka' => '1.ZZLM.8', 'nama_coa_uang_muka' => 'Uang Muka',
            'nominal_uang_muka' => '500000', 'kode_coa_realisasi' => self::BEBAN, 'nama_coa_realisasi' => 'Beban ATK',
            'nominal_realisasi' => '450000', 'kode_rekening' => '1.ZZLM.9', 'kode_unit' => self::UNIT,
            'nomor_referensi' => 'PUM-202608-00001', 'id_uang_muka' => $um->id, 'status' => 'aktif',
            'id_pengguna' => $this->staff->id_pengguna,
        ]);

        $this->actingAs($this->staff)->get(route('pengajuan.index'))->assertOk()->assertSee('📎');
        $this->actingAs($this->staff)->get(route('operational_advance.index'))->assertOk()->assertSee('Lampiran');
        $this->actingAs($this->staff)->get(route('advance_settlement.index'))->assertOk()->assertSee('Lampiran');

        // Yang belum berlampir tetap punya tautannya, tanpa angka.
        $this->assertSame(0, LampiranDokumen::where('jenis_dokumen', SumberLampiran::PENYELESAIAN)
            ->where('id_dokumen', (string) $pum->id)->count());
    }

    /** Jenis dokumen yang tak terdaftar tak punya pintu masuk sama sekali. */
    public function test_jenis_dokumen_asing_ditolak(): void
    {
        $this->actingAs($this->staff)->get(route('lampiran.index', ['tabel_apa_saja', 1]))->assertNotFound();
    }

    /**
     * SATU batas untuk seluruh aplikasi. Kalau ada layar yang menuliskan
     * angkanya sendiri, batas di sini berubah tapi layar itu tidak — dan
     * pemakainya tak pernah tahu mana yang berlaku.
     */
    public function test_batas_unggah_seragam_di_semua_titik(): void
    {
        $this->assertSame(500, Unggahan::MAKS_KB);
        $this->assertSame('500 KB', Unggahan::maksLabel());

        // Aturannya benar-benar dipakai, bukan ditulis ulang per controller.
        foreach ([
            'app/Http/Controllers/DokumenSantriController.php',
            'app/Http/Controllers/PembayaranSantriController.php',
            'app/Http/Controllers/ImporDataAwalController.php',
            'app/Http/Requests/PengajuanRequest.php',
        ] as $berkas) {
            $isi = file_get_contents(base_path($berkas));
            $this->assertStringContainsString('Unggahan::aturan', $isi, "{$berkas} tak memakai batas terpusat");
            $this->assertStringNotContainsString("'max:5120'", $isi);
            $this->assertStringNotContainsString("'max:8192'", $isi);
        }
    }
}
