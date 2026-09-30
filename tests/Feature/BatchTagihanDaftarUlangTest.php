<?php

namespace Tests\Feature;

use App\Models\BatchTagihan;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\BatchTagihanService;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\TarifService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenghitungKueri;
use Tests\TestCase;

/**
 * BATCH TAGIHAN — jalur daftar ulang.
 *
 * Jalur ini yang paling banyak penjaganya (tingkat 1 dilewati, tahun masuk
 * dilewati, tarif per tingkat TUJUAN), dan semuanya harus tetap berlaku saat
 * lewat batch — bukan hanya saat diterbitkan langsung dari layarnya.
 */
class BatchTagihanDaftarUlangTest extends TestCase
{
    use MenghitungKueri;
    use RefreshDatabase;

    private const GRP = 'ZZBD';

    private const PEND = '4.ZZBD.1';

    private const PIUT = '1.ZZBD.1';

    private const UNIT = 'ZZBDU';

    /** Angkatan santri. */
    private const TA = '2026/2027';

    /** Tahun ajaran yang DITAGIH — sengaja berbeda dari angkatan. */
    private const TA2 = '2027/2028';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Batch DU']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUT, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => self::TA2, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1, 'jumlah_tingkat' => 3]);
        JalurPendaftaran::create(['kode' => 'REG', 'nama' => 'Reguler']);

        $this->admin = User::create(['username' => 'zzbd_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        $svc = new JenisBiayaService;
        $svc->create(['kode' => 'REG-SMP', 'nama' => 'Registrasi SMP', 'tipe' => 'registrasi', 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT]);
        $svc->create(['kode' => 'DU-SMP', 'nama' => 'Daftar Ulang SMP', 'tipe' => 'daftar_ulang', 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);

        $tarif = new TarifService;
        $tarif->simpan(self::TA, 'SMP', ['-' => ['registrasi' => ['nominal' => '500000']]]);
        // Tarif disimpan pada tingkat TUJUAN: SMP bertingkat 3 → sel 2 dan 3.
        $tarif->simpanUmum(self::TA2, 'SMP', ['daftar_ulang' => [2 => ['nominal' => '2000000'], 3 => ['nominal' => '2400000']]]);
    }

    private function santriAktif(string $nama, int $tingkat = 2): Santri
    {
        $wali = (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Budi',
            'telepon_ayah' => '08'.random_int(1000000, 9999999),
        ]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L', 'gelombang' => 1,
            'tahun_ajaran' => self::TA, 'jalur' => 'REG', 'kode_jenjang' => 'SMP', 'tingkat' => $tingkat,
        ]);
        $santri->update(['status' => 'aktif']);

        return $santri->refresh();
    }

    private function susun(): BatchTagihan
    {
        return (new BatchTagihanService)->susun([
            'modul' => 'daftar_ulang',
            'parameter' => ['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'SMP', 'jatuh_tempo' => '2027-07-31'],
        ], $this->admin->id_pengguna);
    }

    public function test_draft_menjepret_angka_dan_belum_menerbitkan_apa_pun(): void
    {
        $this->santriAktif('Ahmad Fauzi');

        $batch = $this->susun();

        $this->assertSame('draft', $batch->status);
        $this->assertSame(1, $batch->jumlah_baris);
        $this->assertSame('2000000.00', $batch->total);
        $this->assertSame(0, TagihanSantri::where('perilaku', 'daftar_ulang')->count());
    }

    public function test_nominal_dikunci_walau_tarif_naik_sebelum_rilis(): void
    {
        $this->santriAktif('Bilal Ramadhan');

        $svc = new BatchTagihanService;
        $batch = $this->susun();
        $svc->otorisasi($batch->id, [], $this->admin->id_pengguna);

        // Tarif direvisi setelah petugas memeriksa.
        (new TarifService)->simpanUmum(self::TA2, 'SMP', ['daftar_ulang' => [2 => ['nominal' => '3300000'], 3 => ['nominal' => '3700000']]]);

        $hasil = $svc->rilis($batch->id, $this->admin->id_pengguna);

        $this->assertSame(1, $hasil['terbit']);
        $t = TagihanSantri::where('perilaku', 'daftar_ulang')->firstOrFail();
        $this->assertSame('2000000.00', $t->nominal);
        $this->assertSame(self::TA2, $t->tahun_ajaran, 'Tahun TAGIHAN, bukan angkatan santrinya.');
        $this->assertSame('2027-07-31', $t->jatuh_tempo->toDateString());
        $this->assertTrue($t->sudah_akrual);
    }

    public function test_santri_tingkat_satu_tak_ikut_terhitung_akan_terbit(): void
    {
        $this->santriAktif('Hafizh Nur', 2);
        $belumNaik = $this->santriAktif('Ilham Saputra', 1);

        $batch = $this->susun();

        $this->assertSame(1, $batch->jumlah_baris, 'Yang masih di tingkat 1 belum punya daftar ulang.');
        $baris = $batch->baris()->where('id_santri', $belumNaik->id)->firstOrFail();
        $this->assertSame('dilewati', $baris->keputusan);
        $this->assertStringContainsString('tingkat 1', $baris->alasan);
    }

    public function test_santri_yang_keluar_di_tengah_jeda_dilewati_dan_sisanya_tetap_terbit(): void
    {
        $tetap = $this->santriAktif('Junaidi Akbar');
        $keluar = $this->santriAktif('Karim Abdullah');

        $svc = new BatchTagihanService;
        $batch = $this->susun();
        $this->assertSame(2, $batch->jumlah_baris);
        $svc->otorisasi($batch->id, [], $this->admin->id_pengguna);

        $keluar->update(['status' => 'keluar']);

        $hasil = $svc->rilis($batch->id, $this->admin->id_pengguna);

        $this->assertSame(1, $hasil['terbit']);
        $this->assertSame(1, $hasil['dilewati']);
        $this->assertSame('sebagian', $hasil['status']);
        $this->assertSame(1, TagihanSantri::where('perilaku', 'daftar_ulang')->where('id_santri', $tetap->id)->count());
        $this->assertSame(0, TagihanSantri::where('perilaku', 'daftar_ulang')->where('id_santri', $keluar->id)->count());
    }

    /**
     * Susun & rilis tak bertambah kuerinya bersama jumlah santri. Dulu susun
     * mencari jenis biaya ulang untuk tiap baris, dan rilis menandai tiap baris
     * dengan UPDATE-nya sendiri.
     */
    public function test_susun_dan_rilis_tak_tumbuh_bersama_jumlah_santri(): void
    {
        $this->santriAktif('A1');
        $this->santriAktif('A2');
        [$susunSedikit, $rilisSedikit] = $this->ukur();

        for ($i = 1; $i <= 8; $i++) {
            $this->santriAktif("B{$i}");
        }
        // Yang dua pertama kini sudah punya tagihan → baris "dilewati"; batch
        // kedua tetap memuat 10 baris, 8 di antaranya terbit.
        [$susunBanyak, $rilisBanyak, $batch] = $this->ukur();

        $this->assertLessThanOrEqual($susunSedikit, $susunBanyak, "susun: 2 santri = {$susunSedikit}, 10 santri = {$susunBanyak}");
        $this->assertLessThanOrEqual($rilisSedikit, $rilisBanyak, "rilis: 2 santri = {$rilisSedikit}, 10 santri = {$rilisBanyak}");

        $terbit = $batch->baris()->where('keputusan', 'terbit')->get();
        $this->assertCount(8, $terbit);
        foreach ($terbit as $b) {
            $this->assertSame('terbit', $b->hasil);
            $this->assertSame('DU-SMP', $b->kode_jenis);
            $this->assertSame($b->id_santri, TagihanSantri::find($b->id_tagihan)?->id_santri);
        }
    }

    /** @return array{0:int,1:int,2:BatchTagihan} */
    private function ukur(): array
    {
        $batch = null;
        $susun = $this->hitungKueri(function () use (&$batch) {
            $batch = $this->susun();
        });
        $svc = new BatchTagihanService;
        $svc->otorisasi($batch->id, [], $this->admin->id_pengguna);
        $rilis = $this->hitungKueri(fn () => $svc->rilis($batch->id, $this->admin->id_pengguna));

        return [$susun, $rilis, $batch];
    }
}
