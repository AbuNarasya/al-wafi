<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use App\Models\Wali;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fondasi skema Portal Wali (migrasi 000053). Yang dijaga di sini adalah
 * janji yang ditegakkan MESIN, bukan service: tiap transaksi punya tepat satu
 * pencatat, dari staf atau dari wali — tak pernah keduanya, tak pernah tak ada.
 */
class SkemaPortalWaliTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->staf = User::create([
            'username' => 'zzpw_staf', 'nama' => 'Staf Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        $this->wali = Wali::create([
            'kontak_utama' => 'wali', 'nama_wali' => 'Ibu Aminah', 'telepon_wali' => '081200000001',
            'email_wali' => 'Aminah@Contoh.id', 'nama' => 'Ibu Aminah', 'telepon' => '081200000001', 'status' => 'aktif',
        ]);
    }

    private function mutasi(array $pencatat): void
    {
        static $n = 0;
        DB::table('mutasi_dompet')->insert($pencatat + [
            'nomor' => 'MUT-ZZPW-'.(++$n), 'pemilik' => 'wali', 'id_dompet' => 1, 'jenis' => 'topup',
            'nominal' => 100000, 'saldo_setelah' => 0, 'tanggal' => '2026-10-12',
            'status' => 'menunggu_verifikasi', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_transaksi_boleh_dicatat_staf_atau_wali(): void
    {
        $this->mutasi(['dicatat_oleh' => $this->staf->id_pengguna]);
        $this->mutasi(['dicatat_oleh_wali' => $this->wali->id, 'dicatat_oleh_peran' => 'wali']);

        $this->assertSame(2, DB::table('mutasi_dompet')->count());
    }

    public function test_transaksi_tanpa_pencatat_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->mutasi([]);
    }

    public function test_transaksi_dengan_dua_pencatat_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->mutasi(['dicatat_oleh' => $this->staf->id_pengguna,
            'dicatat_oleh_wali' => $this->wali->id, 'dicatat_oleh_peran' => 'wali']);
    }

    /** Peran menentukan SIAPA di keluarga itu yang mencatat — wajib menyertai wali. */
    public function test_pencatat_wali_tanpa_peran_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->mutasi(['dicatat_oleh_wali' => $this->wali->id]);
    }

    public function test_peran_tanpa_wali_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->mutasi(['dicatat_oleh' => $this->staf->id_pengguna, 'dicatat_oleh_peran' => 'ayah']);
    }

    /** Penjagaan yang sama dipasang di pembayaran_santri — diperiksa dari katalog. */
    public function test_pembayaran_santri_dijaga_constraint_yang_sama(): void
    {
        $ada = collect(DB::select("SELECT conname FROM pg_constraint
            WHERE conrelid = 'pembayaran_santri'::regclass AND contype = 'c'"))->pluck('conname');

        $this->assertContains('pembayaran_santri_satu_pencatat', $ada);
        $this->assertContains('pembayaran_santri_peran_pencatat', $ada);
    }

    /** Email dicari tanpa membedakan huruf — dan indeksnya benar-benar ada. */
    public function test_email_wali_bisa_dicari_lewat_indeks_huruf_kecil(): void
    {
        $id = DB::table('wali')->whereRaw('lower(trim(email_wali)) = ?', ['aminah@contoh.id'])->value('id');
        $this->assertSame($this->wali->id, $id);

        $indeks = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'wali'"))->pluck('indexname');
        foreach (['ayah', 'ibu', 'wali'] as $p) {
            $this->assertContains("wali_email_{$p}_lower", $indeks);
        }
    }

    public function test_kolom_otp_lama_di_wali_sudah_dibuang(): void
    {
        $this->assertFalse(Schema::hasColumn('wali', 'otp_hash'));
        $this->assertFalse(Schema::hasColumn('wali', 'otp_expires'));
        $this->assertTrue(Schema::hasTable('otp_wali'));
    }

    public function test_tabel_portal_wali_terhapus_bersama_walinya(): void
    {
        DB::table('perangkat_wali')->insert([
            'id_wali' => $this->wali->id, 'peran' => 'wali', 'token_hash' => str_repeat('a', 64),
            'kedaluwarsa_pada' => now()->addDays(90), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notifikasi_wali')->insert([
            'id_wali' => $this->wali->id, 'peran' => 'wali', 'judul' => 'Uji', 'pesan' => 'Uji',
            'jenis' => 'uji', 'created_at' => now(),
        ]);

        $this->wali->delete();

        $this->assertSame(0, DB::table('perangkat_wali')->count());
        $this->assertSame(0, DB::table('notifikasi_wali')->count());
    }

    public function test_bawaan_kolom_baru(): void
    {
        $bawaan = fn (string $tabel, string $kolom) => DB::selectOne(
            'SELECT column_default d FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$tabel, $kolom])->d;

        $this->assertStringContainsString("'staf'", $bawaan('pendaftaran', 'asal'));
        $this->assertStringContainsString("'otp_email'", $bawaan('persetujuan_term', 'metode_ttd'));
    }
}
