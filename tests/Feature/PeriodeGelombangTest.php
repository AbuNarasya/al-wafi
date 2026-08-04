<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\PotonganGelombangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PERIODE BERLAKU GELOMBANG — potongan berhenti dipakai sendiri saat periodenya
 * lewat, dan hidup lagi begitu diperpanjang.
 *
 * Kedaluwarsanya DINILAI SAAT DIPAKAI, tidak ditulis ke kolom `aktif` oleh
 * penjadwal: produksi tak punya cron, jadi penonaktifan berjadwal tak akan
 * pernah jalan di sana dan potongan yang sudah lewat akan tetap terpakai —
 * kekeliruan yang berujung pada tagihan uang pangkal terlalu kecil, dan baru
 * ketahuan setelah angkanya terlanjur dijanjikan ke wali.
 *
 * Karena itu yang diuji di sini bukan "kolomnya berubah", melainkan
 * `potonganAktif()` benar-benar berhenti mengembalikan barisnya.
 */
class PeriodeGelombangTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZPRD';

    private const UNIT = 'ZZPRDU';

    private PotonganGelombangService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PotonganGelombangService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Periode']);
        CoaDetail::create(['kode_coa' => '4.ZZPRD.UP', 'nama_coa' => 'Pendapatan UP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'zzprd', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'status' => 'aktif', 'jumlah_tingkat' => 3]);
    }

    private function buat(array $ganti = []): PotonganGelombang
    {
        return $this->service->create(array_merge([
            'tahun_ajaran' => self::TA, 'gelombang' => 1, 'kode_jenjang' => 'J002',
            'potongan' => '5000000', 'masa_berlaku_hari' => 7, 'aktif' => true,
        ], $ganti));
    }

    private function berlakuPada(string $tanggal): bool
    {
        return $this->service->potonganAktif(1, 'J002', self::TA, $tanggal) !== null;
    }

    // ---- Inti: berhenti sendiri, hidup lagi bila diperpanjang ----

    public function test_potongan_berhenti_dipakai_setelah_periode_lewat(): void
    {
        $this->buat(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);

        $this->assertTrue($this->berlakuPada('2026-01-01'), 'hari pertama ikut berlaku');
        $this->assertTrue($this->berlakuPada('2026-02-28'), 'hari terakhir masih berlaku');
        $this->assertFalse($this->berlakuPada('2026-03-01'), 'sehari sesudahnya harus berhenti');
    }

    public function test_potongan_belum_dipakai_sebelum_periode_mulai(): void
    {
        $this->buat(['berlaku_mulai' => '2026-02-01', 'berlaku_sampai' => '2026-02-28']);

        $this->assertFalse($this->berlakuPada('2026-01-31'));
        $this->assertTrue($this->berlakuPada('2026-02-01'));
    }

    /** Permintaan intinya: memperpanjang tanggal selesai = berlaku lagi, tanpa aksi kedua. */
    public function test_memperpanjang_periode_menghidupkan_kembali(): void
    {
        $row = $this->buat(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);
        $this->assertFalse($this->berlakuPada('2026-03-05'));

        $this->service->update($row->id, ['berlaku_sampai' => '2026-03-31']);

        $this->assertTrue($this->berlakuPada('2026-03-05'));
        // Kolom `aktif` tak pernah ikut diutak-atik oleh kedaluwarsa.
        $this->assertTrue($row->refresh()->aktif);
    }

    /** Kolom `aktif` TETAP true selama kedaluwarsa — statusnya dihitung, bukan disimpan. */
    public function test_kedaluwarsa_tidak_mengubah_kolom_aktif(): void
    {
        $row = $this->buat(['berlaku_sampai' => '2026-02-28']);

        $this->assertTrue($row->refresh()->aktif);
        $this->assertSame('kedaluwarsa', $row->keadaan('2026-03-01'));
        $this->assertSame('berlaku', $row->keadaan('2026-02-01'));
    }

    public function test_tanpa_periode_berlaku_seperti_sebelumnya(): void
    {
        $this->buat();

        $this->assertTrue($this->berlakuPada('2020-01-01'));
        $this->assertTrue($this->berlakuPada('2099-12-31'));
    }

    /** Baris yang diarsipkan manual tetap mati walau periodenya sedang berjalan. */
    public function test_arsip_tetap_menang_atas_periode(): void
    {
        $row = $this->buat(['aktif' => false, 'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-12-31']);

        $this->assertFalse($this->berlakuPada('2026-06-01'));
        $this->assertSame('arsip', $row->keadaan('2026-06-01'));
    }

    // ---- Penjagaan isian ----

    /** Periode terbalik membuat potongan tak pernah berlaku — dan diam-diam. */
    public function test_periode_terbalik_ditolak_di_service(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/mendahului tanggal mulai/');
        $this->buat(['berlaku_mulai' => '2026-03-01', 'berlaku_sampai' => '2026-01-01']);
    }

    public function test_periode_terbalik_ditolak_di_layar(): void
    {
        $this->actingAs($this->admin)->post(route('potongan_gelombang.store'), [
            'tahun_ajaran' => self::TA, 'gelombang' => 3, 'kode_jenjang' => 'J002',
            'potongan' => '1000000', 'masa_berlaku_hari' => 7, 'aktif' => '1',
            'berlaku_mulai' => '2026-03-01', 'berlaku_sampai' => '2026-01-01',
        ])->assertSessionHasErrors('berlaku_sampai');

        $this->assertSame(0, PotonganGelombang::count());
    }

    public function test_periode_tersimpan_lewat_layar(): void
    {
        $this->actingAs($this->admin)->post(route('potongan_gelombang.store'), [
            'tahun_ajaran' => self::TA, 'gelombang' => 1, 'kode_jenjang' => 'J002',
            'potongan' => '1000000', 'masa_berlaku_hari' => 7, 'aktif' => '1',
            'berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28',
        ])->assertRedirect(route('potongan_gelombang.index'));

        $row = PotonganGelombang::firstOrFail();
        $this->assertSame('2026-01-01', $row->berlaku_mulai->toDateString());
        $this->assertSame('2026-02-28', $row->berlaku_sampai->toDateString());
    }

    /** Periode boleh dikosongkan lagi — kembali tak berbatas waktu. */
    public function test_periode_boleh_dikosongkan_kembali(): void
    {
        $row = $this->buat(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);

        $this->service->update($row->id, ['berlaku_mulai' => null, 'berlaku_sampai' => null]);

        $this->assertNull($row->refresh()->berlaku_sampai);
        $this->assertTrue($this->berlakuPada('2030-01-01'));
    }

    /** Daftar menyebut kedaluwarsa apa adanya, bukan "Aktif" yang menyesatkan. */
    public function test_daftar_menandai_kedaluwarsa(): void
    {
        Carbon::setTestNow('2026-03-05');
        $this->buat(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);

        $this->actingAs($this->admin)->get(route('potongan_gelombang.index'))
            ->assertOk()
            ->assertSee('Kedaluwarsa')
            ->assertSee('01 Jan 2026 – 28 Feb 2026');

        Carbon::setTestNow();
    }

    /** Penyaringan memakai HARI INI bila tanggalnya tak disebut pemanggil. */
    public function test_hari_ini_dipakai_bila_tanggal_tak_disebut(): void
    {
        Carbon::setTestNow('2026-03-05');
        $this->buat(['berlaku_mulai' => '2026-01-01', 'berlaku_sampai' => '2026-02-28']);

        $this->assertNull($this->service->potonganAktif(1, 'J002', self::TA));

        Carbon::setTestNow('2026-02-10');
        $this->assertNotNull($this->service->potonganAktif(1, 'J002', self::TA));

        Carbon::setTestNow();
    }
}
