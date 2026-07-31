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
use App\Models\PotonganUangPangkal;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\PotonganGelombangService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Master Potongan Gelombang — terutama SUNTINGANNYA.
 *
 * Dulu master ini hanya bisa dibuat & dihapus, padahal pesan bentrokannya
 * sendiri sudah menyuruh "sunting baris itu". Akibatnya satu salah ketik memaksa
 * baris dihapus lalu dibuat ulang — dan riwayat kebijakannya ikut hilang.
 */
class PotonganGelombangTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZPG';

    private const PEND = '4.ZZPG.UP';

    private const PIUT = '1.ZZPG.UP';

    private const UNIT = 'ZZPGU';

    private PotonganGelombangService $service;

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PotonganGelombangService;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PG']);
        foreach ([[self::PEND, 'Pendapatan UP', 'kredit'], [self::PIUT, 'Piutang UP', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'zzpg', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

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

    public function test_sunting_mengubah_nominal_tanpa_membuat_baris_baru(): void
    {
        $row = $this->buat();

        $hasil = $this->service->update($row->id, ['potongan' => '3000000', 'masa_berlaku_hari' => 14]);

        $this->assertSame(1, PotonganGelombang::count(), 'menyunting tidak boleh menambah baris');
        $this->assertSame(3000000.0, (float) $hasil->potongan);
        $this->assertSame(14, $hasil->masa_berlaku_hari);
        // Yang tidak dikirim tetap seperti semula (suntingan parsial).
        $this->assertSame(1, $hasil->gelombang);
        $this->assertSame('J002', $hasil->kode_jenjang);
        $this->assertTrue($hasil->aktif);
    }

    /** Menyalakan satu baris mengarsipkan baris lain di (gelombang, jenjang) yang sama. */
    public function test_mengaktifkan_lewat_sunting_mengarsipkan_yang_lain(): void
    {
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        $lama = $this->buat();
        $baru = $this->buat(['tahun_ajaran' => '2027/2028', 'potongan' => '7000000', 'aktif' => false]);
        $this->assertTrue($lama->refresh()->aktif);

        $this->service->update($baru->id, ['aktif' => true]);

        $this->assertTrue($baru->refresh()->aktif);
        $this->assertFalse($lama->refresh()->aktif, 'baris lama otomatis jadi arsip');
    }

    public function test_sunting_menolak_bentrokan_dan_nominal_negatif(): void
    {
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        $satu = $this->buat();
        $dua = $this->buat(['tahun_ajaran' => '2027/2028', 'aktif' => false]);

        // Digeser ke (T.A, gelombang, jenjang) yang sudah dipakai baris lain.
        try {
            $this->service->update($dua->id, ['tahun_ajaran' => self::TA]);
            $this->fail('harus 409');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('sudah ada', $e->getMessage());
        }

        // Barisnya sendiri boleh disimpan ulang tanpa mengubah kuncinya.
        $this->assertSame($satu->id, $this->service->update($satu->id, ['potongan' => '4000000'])->id);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tidak boleh negatif');
        $this->service->update($satu->id, ['potongan' => '-1']);
    }

    /**
     * Tagihan yang SUDAH TERBIT tidak ikut berubah: potongannya disalin ke
     * `potongan_uang_pangkal` saat tagihan lahir, jadi angka yang sudah
     * dijanjikan ke wali tetap seperti semula.
     */
    public function test_sunting_tidak_mengubah_tagihan_yang_sudah_terbit(): void
    {
        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_jenjang' => 'J002', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '20000000',
            'kode_jenjang' => 'J002', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $potongan = $this->buat();

        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '081200001']);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'gelombang' => 1, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);
        $santri->update(['status' => 'diterima']);
        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '20000000'])['uang_pangkal'];

        // 20jt − 5jt = 15jt.
        $this->assertSame(15000000.0, (float) $tagihan->nominal);

        $this->service->update($potongan->id, ['potongan' => '1000000']);

        $this->assertSame(15000000.0, (float) $tagihan->refresh()->nominal, 'tagihan lama tak boleh ikut berubah');
        $this->assertSame(5000000.0, (float) PotonganUangPangkal::where('id_tagihan', $tagihan->id)->value('potongan'));
    }

    /**
     * Potongan ≥ uang pangkal DIPERINGATKAN saat disimpan, bukan didiamkan sampai
     * penagihan santri pertama ditolak. Sengaja peringatan, bukan penolakan:
     * tarif uang pangkalnya boleh belum diisi atau akan dinaikkan setelah ini.
     */
    public function test_potongan_melebihi_uang_pangkal_diperingatkan_bukan_ditolak(): void
    {
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '20000000',
            'kode_jenjang' => 'J002', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);

        $aman = $this->buat(['potongan' => '5000000']);
        $this->assertNull($this->service->peringatanNominal($aman));

        // Persis sama besar — inilah yang membuat penagihan ditolak.
        $sama = $this->service->update($aman->id, ['potongan' => '20000000']);
        $pesan = $this->service->peringatanNominal($sama);
        $this->assertNotNull($pesan, 'potongan = uang pangkal harus diperingatkan');
        $this->assertStringContainsString('DITOLAK', $pesan);
    }

    /** Tanpa tarif uang pangkal, tak ada yang bisa dibandingkan — jangan mengarang peringatan. */
    public function test_tanpa_tarif_uang_pangkal_tidak_ada_peringatan(): void
    {
        $this->assertNull($this->service->peringatanNominal($this->buat(['potongan' => '99000000'])));
    }

    public function test_halaman_sunting_terbuka_dan_form_menyimpan(): void
    {
        $row = $this->buat();
        $user = User::find($this->admin);

        $this->actingAs($user)->get(route('potongan_gelombang.edit', $row->id))
            ->assertOk()
            ->assertSee('Perbarui', false);

        $this->actingAs($user)->put(route('potongan_gelombang.update', $row->id), [
            'tahun_ajaran' => self::TA, 'gelombang' => 2, 'kode_jenjang' => 'J002',
            'potongan' => '2500000', 'masa_berlaku_hari' => 10, 'aktif' => '1',
        ])->assertRedirect(route('potongan_gelombang.index'));

        $row->refresh();
        $this->assertSame(2, $row->gelombang);
        $this->assertSame(2500000.0, (float) $row->potongan);
        $this->assertSame(10, $row->masa_berlaku_hari);
    }
}
