<?php

namespace Tests\Feature;

use App\Models\ImporBatch;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\KebijakanKhusus;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Impor\ImporSaldoAwal;
use App\Services\Impor\Pemeta\PemetaSantriLama;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `santri:impor-ulang` (5 Nov 2026): kosongkan semua santri, wali & transaksinya lalu impor ulang dalam
 * satu transaksi; Kebijakan Khusus disalin dan disambung ulang lewat NIS dengan id yang sama.
 */
class ImporUlangSantriTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'admin', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $baris nis, nama, nama ayah, telepon ayah */
    private function berkas(array $baris): string
    {
        $csv = tempnam(sys_get_temp_dir(), 'iu').'.csv';
        $isi = ['NIS,NAMA,JENIS_KELAMIN,KODE_JENJANG,TINGKAT,NAMA_AYAH,TELEPON_AYAH,NAMA_IBU,TELEPON_IBU'];
        foreach ($baris as [$nis, $nama, $ayah, $telp]) {
            $isi[] = "{$nis},{$nama},L,SMP,1,{$ayah},\"{$telp}\",Ibu {$ayah},";
        }
        file_put_contents($csv, implode("\n", $isi));

        return $csv;
    }

    /** Keadaan awal: dua santri hasil impor lama, satu kebijakan khusus, satu jurnal pembayaran santri, satu jurnal umum. */
    private function siapkanDataLama(): KebijakanKhusus
    {
        app(ImporSaldoAwal::class)->jalankan(PemetaSantriLama::kunci(), $this->berkas([
            ['801', 'LAMA SATU', 'Ayah Lama', '081700000001'],
            ['802', 'LAMA DUA', 'Ayah Lama', '081700000001'],
        ]), ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001'], $this->admin->id_pengguna);

        foreach (['PembayaranSantri' => 'BYR-UJI', 'JurnalUmum' => 'JU-UJI'] as $sumber => $ref) {
            DB::table('journal_entries')->insert(['referensi' => $ref, 'tanggal' => '2026-11-01', 'keterangan' => 'uji',
                'sumber_modul' => $sumber, 'status' => 'aktif', 'created_at' => now()]);
        }

        return KebijakanKhusus::create(['id_santri' => Santri::where('nis', '802')->value('id'), 'jenis' => 'beasiswa',
            'perilaku' => 'spp', 'cara' => 'persen', 'besaran' => 50, 'alasan' => 'Yatim', 'status' => 'disetujui']);
    }

    private function jalankan(string $csv, bool $sungguhan): int
    {
        return $this->artisan('santri:impor-ulang', array_filter([
            'berkas' => $csv, '--ta' => '2026/2027', '--jalur' => '001',
            '--jalankan' => $sungguhan, '--alasan' => $sungguhan ? 'impor ulang uji' : null,
        ]))->run();
    }

    private const BARU = [
        ['802', 'LAMA DUA DIPERBAIKI', 'Ayah Baru', '081700000009'],
        ['803', 'SANTRI TIGA', 'Ayah Tiga', '081700000003'],
    ];

    public function test_uji_kering_tak_mengubah_apa_pun(): void
    {
        $kebijakan = $this->siapkanDataLama();
        $sebelum = [Santri::count(), Wali::count(), DB::table('journal_entries')->count(), $kebijakan->fresh()->id_santri];

        $this->assertSame(0, $this->jalankan($this->berkas(self::BARU), false));

        $this->assertSame($sebelum, [Santri::count(), Wali::count(), DB::table('journal_entries')->count(), $kebijakan->fresh()->id_santri]);
    }

    public function test_sungguhan_mengganti_semua_dan_menyambung_kebijakan_lewat_nis(): void
    {
        $kebijakan = $this->siapkanDataLama();
        $idLama = $kebijakan->id_santri;

        $this->assertSame(0, $this->jalankan($this->berkas(self::BARU), true));

        $this->assertSame(['802', '803'], Santri::orderBy('nis')->pluck('nis')->all());
        $this->assertSame(['081700000003', '081700000009'], Wali::orderBy('telepon')->pluck('telepon')->all(), 'wali lama ikut dibuang');
        $this->assertSame('LAMA DUA DIPERBAIKI', Santri::where('nis', '802')->value('nama'));

        $k = KebijakanKhusus::findOrFail($kebijakan->id);   // id tetap — lampiran surat menunjuk id ini
        $this->assertNotSame($idLama, $k->id_santri);
        $this->assertSame('802', $k->santri->nis);
        $this->assertSame([50.0, 'disetujui'], [(float) $k->besaran, $k->status]);

        $this->assertSame(['JurnalUmum'], DB::table('journal_entries')->pluck('sumber_modul')->all(), 'hanya jurnal modul santri yang dibuang');
        $this->assertNotNull(ImporBatch::orderBy('id')->first()->dibatalkan_pada, 'batch impor lama ditandai dibatalkan');
        $this->assertSame(1, DB::table('activity_log')->where('aksi', 'impor_ulang_santri')->count());
    }

    public function test_kebijakan_yang_nisnya_tak_ada_di_berkas_membatalkan_semuanya(): void
    {
        $this->siapkanDataLama();

        $this->assertSame(1, $this->jalankan($this->berkas([['803', 'SANTRI TIGA', 'Ayah Tiga', '081700000003']]), true));

        $this->assertSame(['801', '802'], Santri::orderBy('nis')->pluck('nis')->all(), 'tak ada yang berubah');
        $this->assertSame(1, KebijakanKhusus::count());
    }

    public function test_berkas_yang_belum_bersih_membatalkan_semuanya(): void
    {
        $this->siapkanDataLama();

        // Baris kedua tanpa nama ayah & ibu & telepon.
        $csv = $this->berkas(self::BARU);
        file_put_contents($csv, "\n804,TANPA WALI,L,SMP,1,,,,", FILE_APPEND);

        $this->assertSame(1, $this->jalankan($csv, true));
        $this->assertSame(['801', '802'], Santri::orderBy('nis')->pluck('nis')->all());
    }
}
