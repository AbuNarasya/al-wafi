<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\TahunAjaran;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\TahunAjaranService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Master Tahun Ajaran: default unik, hapus terlindungi, dan gerbang registrasi per TA. */
class TahunAjaranTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZTA', 'nama_grup' => 'TA']);
        CoaDetail::create(['kode_coa' => '4.ZZTA.REG', 'nama_coa' => 'Pendapatan Registrasi', 'kode_grup' => 'ZZTA', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZTAU', 'nama_unit' => 'Unit']);
    }

    public function test_default_pendaftaran_hanya_satu(): void
    {
        $svc = new TahunAjaranService;
        $a = $svc->create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        $b = $svc->create(['kode' => '2027/2028', 'status' => 'aktif', 'default_pendaftaran' => true]);

        $this->assertFalse($a->refresh()->default_pendaftaran);
        $this->assertTrue($b->refresh()->default_pendaftaran);
        $this->assertSame('2027/2028', $svc->defaultPendaftaran()->kode);

        // TA nonaktif tak boleh jadi default.
        $this->expectException(AppException::class);
        $svc->update($b->id, ['status' => 'nonaktif', 'default_pendaftaran' => true]);
    }

    public function test_hapus_terlindungi_bila_dirujuk(): void
    {
        $svc = new TahunAjaranService;
        $ta = $svc->create(['kode' => '2026/2027', 'status' => 'aktif']);

        // Yang merujuk T.A kini SEL TARIF-nya (jenis biaya sendiri berlaku lintas
        // tahun); jalur pendaftaran TIDAK lagi merujuk T.A.
        $this->buatBiaya([
            'kode' => 'REGX', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '100000',
            'kode_coa_pendapatan' => '4.ZZTA.REG', 'kode_unit' => 'ZZTAU', 'tahun_ajaran' => '2026/2027',
        ]);

        try {
            $svc->remove($ta->id);
            $this->fail('harus 409');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
        }

        // Jalur yang masih ada tidak lagi menghalangi penghapusan T.A.
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        \App\Models\JenisBiaya::destroy('REGX');
        \App\Models\TarifBiaya::where('tahun_ajaran', '2026/2027')->delete();
        $svc->remove($ta->id);
        $this->assertDatabaseMissing('tahun_ajaran', ['kode' => '2026/2027']);
    }

    public function test_registrasi_memakai_jenis_biaya_dan_jalur_per_ta(): void
    {
        $svc = new TahunAjaranService;
        $svc->create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        $svc->create(['kode' => '2027/2028', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => '2026/2027']);
        JalurPendaftaran::create(['kode' => 'reguler28', 'nama' => 'Reguler', 'tahun_ajaran' => '2027/2028']);
        // SATU baris registrasi (akunnya sama tiap tahun), DUA sel tarif: 500rb
        // untuk T.A 26/27 dan 750rb untuk 27/28. Dulu ini menuntut dua baris
        // master; sejak tarif dipisah, tahun ajaran tak lagi memecah masternya.
        $this->buatBiaya(['kode' => 'REG27', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => '4.ZZTA.REG', 'kode_unit' => 'ZZTAU', 'tahun_ajaran' => '2026/2027']);
        $this->pasangTarif('2027/2028', null, null, 'registrasi', '750000');
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08321']);

        $santriSvc = new SantriService;

        // Tanpa TA → ditolak.
        try {
            $santriSvc->create(['id_wali' => $wali->id, 'nama' => 'A', 'jenis_kelamin' => 'L', 'jalur' => 'reguler']);
            $this->fail('harus 422');
        } catch (AppException $e) {
            $this->assertStringContainsString('Tahun ajaran wajib', $e->getMessage());
        }

        // Jalur BERLAKU LINTAS T.A — jalur mana pun boleh dipakai di T.A mana pun.
        // (Dulu ada aturan "jalur harus milik T.A yang sama"; dicabut 2026-07-28
        // karena "Reguler" memang jalur yang sama tiap tahun.)
        $lintas = $santriSvc->create(['id_wali' => $wali->id, 'nama' => 'Lintas TA', 'jenis_kelamin' => 'L', 'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler28']);
        $this->assertSame('reguler28', $lintas->jalur);

        // Jalur nonaktif tetap ditolak.
        JalurPendaftaran::whereKey('reguler28')->update(['status' => 'nonaktif']);
        try {
            $santriSvc->create(['id_wali' => $wali->id, 'nama' => 'B', 'jenis_kelamin' => 'L', 'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler28']);
            $this->fail('harus 422');
        } catch (AppException $e) {
            $this->assertStringContainsString('nonaktif', $e->getMessage());
        }
        JalurPendaftaran::whereKey('reguler28')->update(['status' => 'aktif']);

        // TA 27/28 → tagihan registrasi memakai SEL TARIF tahun itu (750rb),
        // tetapi baris master (akun) yang dipakai tetap satu-satunya yang ada.
        $santri = $santriSvc->create(['id_wali' => $wali->id, 'nama' => 'A', 'jenis_kelamin' => 'L', 'tahun_ajaran' => '2027/2028', 'jalur' => 'reguler28']);
        $this->assertSame('2027/2028', $santri->tahun_ajaran);
        $this->assertSame(750000.0, (float) $santri->tagihan()->first()->nominal);
        $this->assertSame('REG27', $santri->tagihan()->first()->kode_jenis);
        // Kolom baru ikut terisi — inilah yang membedakan tagihan antar T.A.
        $this->assertSame('2027/2028', $santri->tagihan()->first()->tahun_ajaran);
        $this->assertSame('registrasi', $santri->tagihan()->first()->perilaku);
    }

    public function test_kode_ta_tidak_bisa_diubah(): void
    {
        $svc = new TahunAjaranService;
        $ta = $svc->create(['kode' => '2026/2027', 'status' => 'aktif']);
        $svc->update($ta->id, ['kode' => '2030/2031', 'status' => 'aktif', 'keterangan' => 'ubah']);

        $this->assertSame('2026/2027', $ta->refresh()->kode);
        $this->assertSame('ubah', $ta->keterangan);
    }
}
