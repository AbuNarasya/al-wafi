<?php

namespace Tests\Feature;

use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
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
 * 5 Nov 2026: impor santri lama memakai isian keluarga yang sama dengan formulir PPSB — nama, telepon,
 * email, pekerjaan, pendapatan per ayah/ibu/wali, kontak utama, NIK, alamat; juga asal sekolah santri.
 * Kunci keluarga tetap nama + telepon KONTAK UTAMA (keputusan 22 Okt). Format lama (wali_nama +
 * wali_telepon) tetap diterima.
 */
class ImporKeluargaSantriLamaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $kunci;

    private const OPSI = ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001'];

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

    private const JUDUL = ['NIS', 'NAMA', 'JENIS_KELAMIN', 'KODE_JENJANG', 'TINGKAT', 'ASAL_SEKOLAH', 'KONTAK_UTAMA',
        'NAMA_AYAH', 'TELEPON_AYAH', 'EMAIL_AYAH', 'PEKERJAAN_AYAH', 'PENDAPATAN_AYAH',
        'NAMA_IBU', 'TELEPON_IBU', 'EMAIL_IBU', 'PEKERJAAN_IBU', 'PENDAPATAN_IBU', 'NIK', 'ALAMAT'];

    /** @param list<array<string,string>> $baris kunci = judul kolom (huruf kecil); yang tak disebut kosong */
    private function berkas(array $baris): string
    {
        $csv = tempnam(sys_get_temp_dir(), 'kel').'.csv';
        $f = fopen($csv, 'w');
        fputcsv($f, self::JUDUL, ',', '"', '\\');
        foreach ($baris as $b) {
            $b += ['jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => '1'];
            fputcsv($f, array_map(fn ($j) => $b[strtolower($j)] ?? '', self::JUDUL), ',', '"', '\\');
        }
        fclose($f);

        return $csv;
    }

    private function pratinjau(array $baris): array
    {
        return app(ImporSaldoAwal::class)->pratinjau($this->kunci, $this->berkas($baris), self::OPSI);
    }

    public function test_data_keluarga_lengkap_tersimpan_seperti_formulir_ppsb(): void
    {
        $hasil = app(ImporSaldoAwal::class)->jalankan($this->kunci, $this->berkas([
            ['nis' => '701', 'nama' => 'KAKAK', 'asal_sekolah' => 'SDIT Al Hikmah',
                'nama_ayah' => 'Budi', 'telepon_ayah' => '+62 812-0000-0001', 'email_ayah' => 'Budi@Contoh.id',
                'pekerjaan_ayah' => 'Wiraswasta / Pengusaha', 'pendapatan_ayah' => 'Rp 5–10 juta',
                'nama_ibu' => 'Siti', 'telepon_ibu' => '081200000002', 'pekerjaan_ibu' => 'Ibu Rumah Tangga', 'pendapatan_ibu' => 'di_bawah_5',
                'nik' => '3201010101010001', 'alamat' => 'Jl. Melati 5'],
            // Adik: cukup kontak utamanya sama — kolom lain boleh tak diulang.
            ['nis' => '702', 'nama' => 'ADIK', 'nama_ayah' => 'BUDI', 'telepon_ayah' => '081200000001'],
        ]), self::OPSI, $this->admin->id_pengguna);

        $this->assertSame(['santri' => 2, 'wali' => 1], ['santri' => $hasil['tersimpan']['santri'], 'wali' => $hasil['tersimpan']['wali']]);
        $w = Wali::sole();
        $this->assertSame(['ayah', 'Budi', '081200000001'], [$w->kontak_utama, $w->nama, $w->telepon]);
        $this->assertSame(['Budi', '081200000001', 'budi@contoh.id', 'Wiraswasta / Pengusaha', 'juta_5_10'],
            [$w->nama_ayah, $w->telepon_ayah, $w->email_ayah, $w->pekerjaan_ayah, $w->pendapatan_ayah]);
        $this->assertSame(['Siti', '081200000002', 'Ibu Rumah Tangga', 'di_bawah_5'], [$w->nama_ibu, $w->telepon_ibu, $w->pekerjaan_ibu, $w->pendapatan_ibu]);
        $this->assertSame(['3201010101010001', 'Jl. Melati 5'], [$w->nik, $w->alamat]);
        $this->assertSame('SDIT Al Hikmah', Santri::where('nis', '701')->value('asal_sekolah'));
        $this->assertSame(2, Santri::where('id_wali', $w->id)->count());
    }

    /** Ayah tanpa telepon: kontak utama jatuh ke ibu, dan ibulah kunci keluarganya. */
    public function test_kontak_utama_otomatis_ibu_bila_ayah_tak_bertelepon(): void
    {
        app(ImporSaldoAwal::class)->jalankan($this->kunci, $this->berkas([
            ['nis' => '711', 'nama' => 'ZAID', 'nama_ayah' => 'Ahmad', 'nama_ibu' => 'Aminah', 'telepon_ibu' => '081300000001'],
        ]), self::OPSI, $this->admin->id_pengguna);

        $w = Wali::sole();
        $this->assertSame(['ibu', 'Aminah', '081300000001', 'Ahmad', null], [$w->kontak_utama, $w->nama, $w->telepon, $w->nama_ayah, $w->telepon_ayah]);
    }

    public function test_kontak_utama_dipilih_di_berkas(): void
    {
        app(ImporSaldoAwal::class)->jalankan($this->kunci, $this->berkas([
            ['nis' => '721', 'nama' => 'HASAN', 'kontak_utama' => 'Ibu', 'nama_ayah' => 'Ali', 'telepon_ayah' => '081400000001',
                'nama_ibu' => 'Fatimah', 'telepon_ibu' => '081400000002'],
        ]), self::OPSI, $this->admin->id_pengguna);

        $this->assertSame(['ibu', 'Fatimah', '081400000002'], [Wali::sole()->kontak_utama, Wali::sole()->nama, Wali::sole()->telepon]);
    }

    public function test_isian_yang_tak_sah_ditolak_dengan_alasan(): void
    {
        $p = $this->pratinjau([
            ['nis' => '731', 'nama' => 'A', 'telepon_ayah' => '081500000001'],                                        // tanpa nama ayah & ibu
            ['nis' => '732', 'nama' => 'B', 'nama_ayah' => 'Umar', 'nama_ibu' => 'Hafsah'],                           // tanpa telepon
            ['nis' => '733', 'nama' => 'C', 'kontak_utama' => 'paman', 'nama_ayah' => 'X', 'telepon_ayah' => '081500000003'],
            ['nis' => '734', 'nama' => 'D', 'nama_ayah' => 'Y', 'telepon_ayah' => '081500000004', 'email_ayah' => 'bukan-email'],
            ['nis' => '735', 'nama' => 'E', 'nama_ayah' => 'Z', 'telepon_ayah' => '081500000005', 'pendapatan_ayah' => 'banyak'],
            // Satu email di dua keluarga berbeda → keduanya ditolak.
            ['nis' => '736', 'nama' => 'F', 'nama_ayah' => 'P', 'telepon_ayah' => '081500000006', 'email_ayah' => 'sama@contoh.id'],
            ['nis' => '737', 'nama' => 'G', 'nama_ayah' => 'Q', 'telepon_ayah' => '081500000007', 'email_ibu' => 'SAMA@contoh.id', 'nama_ibu' => 'R'],
        ]);

        $this->assertSame(0, $p['siap'], json_encode($p['baris_masalah']));
        $alasan = collect($p['baris_masalah'])->pluck('alasan', 'nomor');
        $this->assertStringContainsString('Nama ayah dan nama ibu kosong', $alasan[2]);
        $this->assertStringContainsString('kontak utama (Ayah) kosong', $alasan[3]);
        $this->assertStringContainsString('Kontak utama "paman" tidak dikenal', $alasan[4]);
        $this->assertStringContainsString('tidak sah', $alasan[5]);
        $this->assertStringContainsString('Pendapatan ayah "banyak" tidak dikenal', $alasan[6]);
        $this->assertStringContainsString('lebih dari satu keluarga', $alasan[7]);
        $this->assertStringContainsString('lebih dari satu keluarga', $alasan[8]);
    }

    public function test_email_yang_sudah_dipakai_keluarga_lain_ditolak(): void
    {
        Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lama', 'telepon_ayah' => '081600000001', 'email_ayah' => 'lama@contoh.id',
            'nama' => 'Lama', 'telepon' => '081600000001', 'status' => 'aktif']);

        $p = $this->pratinjau([['nis' => '741', 'nama' => 'H', 'nama_ayah' => 'Baru', 'telepon_ayah' => '081600000002', 'email_ayah' => 'lama@contoh.id']]);

        $this->assertStringContainsString('sudah dipakai keluarga lain', $p['baris_masalah'][0]['alasan'] ?? '');
    }

    /** Templat unduhan memuat kolom per orang, dan kolom lama tak lagi wajib. */
    public function test_templat_memuat_kolom_keluarga(): void
    {
        $kolom = app(PemetaSantriLama::class)->kolom();
        foreach (['nama_ayah', 'telepon_ibu', 'email_wali', 'pekerjaan_ayah', 'pendapatan_ibu', 'kontak_utama', 'nik', 'alamat', 'asal_sekolah'] as $k) {
            $this->assertArrayHasKey($k, $kolom);
        }
        $this->assertFalse($kolom['wali_nama']['wajib']);
        $this->assertFalse($kolom['wali_telepon']['wajib']);
    }
}
