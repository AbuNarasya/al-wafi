<?php

namespace Tests\Feature;

use App\Models\ImporBatch;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\KunjunganKlinik;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Impor\ImporSaldoAwal;
use App\Services\Impor\Pemeta\PemetaSantriLama;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keputusan user 22 Okt 2026: NAMA WALI + TELEPON = satu kunci tak terpisahkan di impor santri lama.
 * (Impor 21 Okt menggabungkan anak dari 4 keluarga berbeda karena memakai telepon yang sama.)
 * Juga: pembatalan batch ditolak bila santrinya sudah punya data operasional (klinik, kartu, …).
 */
class ImporKunciWaliTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $kunci;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        $this->kunci = array_search(PemetaSantriLama::class, ImporSaldoAwal::daftar(), true);
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $baris nis, nama santri, nama wali, telepon */
    private function berkas(array $baris): string
    {
        $csv = tempnam(sys_get_temp_dir(), 'kw').'.csv';
        $isi = ['NIS,NAMA,JENIS_KELAMIN,KODE_JENJANG,TINGKAT,WALI_NAMA,WALI_TELEPON'];
        foreach ($baris as [$nis, $nama, $wali, $telp]) {
            $isi[] = "{$nis},{$nama},L,SMP,1,{$wali},\"{$telp}\"";
        }
        file_put_contents($csv, implode("\n", $isi));

        return $csv;
    }

    private function pratinjau(string $csv): array
    {
        return app(ImporSaldoAwal::class)->pratinjau($this->kunci, $csv, ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001']);
    }

    public function test_di_dalam_berkas_telepon_sama_nama_beda_dan_nama_sama_telepon_beda_ditolak_semua(): void
    {
        $p = $this->pratinjau($this->berkas([
            ['901', 'KAKAK', 'Budi', '081200000001'],
            ['902', 'ADIK', 'BUDI', '+62 812-0000-0001'],   // sama orangnya: telepon dinormalkan, huruf besar-kecil diabaikan
            ['903', 'ZAIN', 'Rezi', '081932440560'],
            ['904', 'MALIKA', 'Crys', '081932440560'],      // telepon sama, nama beda → keduanya ditolak
            ['905', 'RAFA', 'Saefudin', '081300000005'],
            ['906', 'RAFI', 'Saefudin', '081300000006'],    // nama sama, telepon beda → keduanya ditolak
        ]));

        $this->assertSame([2, 4], [$p['siap'], $p['masalah']], json_encode($p['baris_masalah']));
        $alasan = collect($p['baris_masalah'])->pluck('alasan', 'nomor');
        $this->assertStringContainsString('lebih dari satu nama wali', $alasan[4]);
        $this->assertStringContainsString('lebih dari satu nama wali', $alasan[5]);
        $this->assertStringContainsString('telepon berbeda', $alasan[6]);
        $this->assertStringContainsString('telepon berbeda', $alasan[7]);

        app(ImporSaldoAwal::class)->jalankan($this->kunci, $this->berkas([['901', 'KAKAK', 'Budi', '081200000001'], ['902', 'ADIK', 'BUDI', '+62 812-0000-0001']]),
            ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001'], $this->admin->id_pengguna);
        $this->assertSame(1, Wali::count(), 'kakak-beradik satu wali');
        $this->assertSame('081200000001', Wali::sole()->telepon, 'telepon disimpan ternormalkan');
    }

    public function test_terhadap_wali_yang_sudah_ada_dua_arah(): void
    {
        Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Hariyanto', 'telepon_ayah' => '08127361447', 'nama' => 'Hariyanto', 'telepon' => '08127361447', 'status' => 'aktif']);

        // Satu berkas per kasus — baris yang bentrok di DALAM berkas ikut menolak pasangannya.
        $telpBeda = $this->pratinjau($this->berkas([['911', 'SULTHAN', 'Harianto', '08127361447']]));  // telepon terdaftar atas nama lain
        $this->assertStringContainsString('sudah dipakai wali bernama', $telpBeda['baris_masalah'][0]['alasan'] ?? '');
        $namaBeda = $this->pratinjau($this->berkas([['912', 'SUNAN', 'Hariyanto', '08127361448']]));  // nama terdaftar dengan telepon lain
        $this->assertStringContainsString('sudah terdaftar dengan telepon', $namaBeda['baris_masalah'][0]['alasan'] ?? '');
        $cocok = $this->pratinjau($this->berkas([['913', 'HASAN', 'hariyanto', '+628127361447']]));  // pasangan sama → boleh
        $this->assertSame(1, $cocok['siap'], json_encode($cocok['baris_masalah']));
    }

    /** 22 Okt 2026, produksi: kakak di batch 1, adik di batch 2; batch dibatalkan 1 lalu 2 → walinya dulu tertinggal. */
    public function test_wali_kakak_adik_beda_batch_terhapus_setelah_semua_batch_dibatalkan(): void
    {
        $opsi = ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001'];
        $imp = app(ImporSaldoAwal::class);
        $b1 = $imp->jalankan($this->kunci, $this->berkas([['931', 'KAKAK', 'Budi', '081200000031']]), $opsi, $this->admin->id_pengguna)['id_batch'];
        $b2 = $imp->jalankan($this->kunci, $this->berkas([['932', 'ADIK', 'Budi', '081200000031']]), $opsi, $this->admin->id_pengguna)['id_batch'];
        $this->assertSame(1, Wali::count(), 'adik memakai wali yang lahir di batch 1');

        $imp->batalkanBatch($b1, 'uji', $this->admin->id_pengguna);
        $this->assertSame(1, Wali::count(), 'masih menaungi adik di batch 2');
        $imp->batalkanBatch($b2, 'uji', $this->admin->id_pengguna);
        $this->assertSame([0, 0], [Santri::count(), Wali::count()]);
    }

    public function test_batal_batch_ditolak_bila_santri_sudah_punya_kunjungan_klinik(): void
    {
        $hasil = app(ImporSaldoAwal::class)->jalankan($this->kunci, $this->berkas([['921', 'AHMAD', 'Budi', '081200000009']]),
            ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001'], $this->admin->id_pengguna);
        $batch = ImporBatch::findOrFail($hasil['id_batch']);
        $this->assertSame([], app(ImporSaldoAwal::class)->halanganBatalBatch($batch));

        KunjunganKlinik::create(['nomor' => 'KLN-UJI-1', 'id_santri' => Santri::sole()->id, 'datang_pada' => now(), 'keluhan' => 'Demam',
            'tindak_lanjut' => 'kembali_kelas', 'status' => 'selesai']);
        $this->assertSame(['Sudah ada 1 kunjungan klinik.'], app(ImporSaldoAwal::class)->halanganBatalBatch($batch));
    }
}
