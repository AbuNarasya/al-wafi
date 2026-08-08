<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\Wali;
use App\Services\Impor\ImporSaldoAwal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRATINJAU IMPOR TIDAK BOLEH MENEMBAK DATABASE PER BARIS.
 *
 * `periksa()` dipanggil sekali per baris, dan dulu tiap panggilan menanyakan
 * sendiri-sendiri: NIS, jenjang (dua kueri — kode lalu nama), tahun ajaran,
 * jalur, wali, ditambah satu kueri per kolom tunggakan yang terisi. Untuk berkas
 * 202 santri itu ±1.200 kueri BERURUTAN.
 *
 * Di mesin pengembang tak terasa — database di komputer yang sama. Di produksi
 * ia ada di Neon, belasan milidetik sekali jalan, dan seribu perjalanan bolak-
 * balik itulah yang membuat pratinjau berakhir 502.
 *
 * Test ini menghitung kueri yang benar-benar dijalankan, bukan mempercayai
 * bahwa simpanannya "kelihatannya dipakai". Batasnya sengaja longgar: yang
 * dijaga adalah jumlahnya TIDAK TUMBUH mengikuti jumlah baris.
 */
class ImporTanpaNPlusSatuTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZIM';

    private const TA = '2026/2027';

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'urutan' => 1, 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZIMU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Impor Uji']);
        CoaDetail::create(['kode_coa' => '4.ZZIM.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZIM.1', 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        TipeBiaya::firstOrCreate(['kode' => 'spp'],
            ['nama' => 'SPP', 'perilaku' => 'spp', 'urutan' => 3, 'bawaan' => true, 'status' => 'aktif']);
        JenisBiaya::create([
            'kode' => 'SPP-UJI', 'nama' => 'SPP', 'tipe' => 'spp',
            'kode_coa_pendapatan' => '4.ZZIM.1', 'kode_coa_piutang' => '1.ZZIM.1',
            'kode_unit' => 'ZZIMU', 'status' => 'aktif', 'pengakuan' => 'akrual',
        ]);
    }

    /** Berkas CSV dengan $jumlah baris santri. */
    private function berkas(int $jumlah): string
    {
        $baris = ['nis,nama,jenis_kelamin,kode_jenjang,tingkat,tahun_ajaran,jalur,wali_nama,wali_telepon,tunggakan_spp'];
        for ($i = 1; $i <= $jumlah; $i++) {
            $nis = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            // Telepon sengaja BERULANG tiap dua baris: kakak-beradik berbagi wali,
            // dan itu justru yang paling mudah rusak bila simpanannya keliru.
            $telepon = '08'.str_pad((string) intdiv($i + 1, 2), 6, '0', STR_PAD_LEFT);
            $baris[] = "{$nis},Santri {$i},L,SDTQ,1,".self::TA.",reguler,Wali {$i},{$telepon},150000";
        }

        $path = tempnam(sys_get_temp_dir(), 'impor').'.csv';
        file_put_contents($path, implode("\n", $baris));

        return $path;
    }

    /** @return array{hasil:array,kueri:int} */
    private function jalankanPratinjau(int $jumlah): array
    {
        $path = $this->berkas($jumlah);
        $param = ['jenis_tunggakan_spp' => 'SPP-UJI', 'jenis_tunggakan_uang_pangkal' => '',
            'jenis_tunggakan_daftar_ulang' => '', 'jenis_tunggakan_lain' => ''];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $hasil = (new ImporSaldoAwal)->pratinjau('santri-lama', $path, $param);
        $kueri = count(DB::getQueryLog());
        DB::disableQueryLog();
        @unlink($path);

        return ['hasil' => $hasil, 'kueri' => $kueri];
    }

    public function test_jumlah_kueri_pratinjau_tidak_tumbuh_mengikuti_jumlah_baris(): void
    {
        $kecil = $this->jalankanPratinjau(5);
        $besar = $this->jalankanPratinjau(50);

        $this->assertSame(5, $kecil['hasil']['siap']);
        $this->assertSame(50, $besar['hasil']['siap']);

        // Sepuluh kali lebih banyak baris tak boleh berarti sepuluh kali lebih
        // banyak kueri. Selisihnya dibatasi beberapa saja, bukan nol, supaya
        // test ini tak pecah oleh kueri kerangka yang tak ada urusannya.
        $this->assertLessThanOrEqual(
            $kecil['kueri'] + 3,
            $besar['kueri'],
            "Pratinjau 50 baris memakai {$besar['kueri']} kueri, sedangkan 5 baris {$kecil['kueri']} — "
            .'jumlahnya masih tumbuh mengikuti baris, berarti masih ada N+1.',
        );

        // Batas mutlak: apa pun isinya, pratinjau satu berkas tak masuk akal
        // menghabiskan puluhan kueri.
        $this->assertLessThan(20, $besar['kueri']);
    }

    public function test_hasil_pratinjaunya_tetap_sama_seperti_sebelum_disimpan(): void
    {
        // Satu NIS sudah ada ⇒ "lewati"; satu jenjang ngawur ⇒ "masalah".
        $wali = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'A', 'telepon_ayah' => '081',
            'nama' => 'A', 'telepon' => '081', 'status' => 'aktif']);
        Santri::create(['no_pendaftaran' => 'X-1', 'nis' => '000001', 'nama' => 'Sudah Ada',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'J001', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id]);

        $hasil = $this->jalankanPratinjau(3)['hasil'];

        $this->assertSame(3, $hasil['jumlah']);
        $this->assertSame(1, $hasil['lewati'], 'NIS yang sudah ada tetap terbaca sebagai "lewati".');
        $this->assertSame(2, $hasil['siap']);
    }

    /** @return array{hasil:array,kueri:int} */
    private function jalankanImpor(int $jumlah): array
    {
        $path = $this->berkas($jumlah);
        $param = ['jenis_tunggakan_spp' => 'SPP-UJI', 'jenis_tunggakan_uang_pangkal' => '',
            'jenis_tunggakan_daftar_ulang' => '', 'jenis_tunggakan_lain' => ''];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $hasil = (new ImporSaldoAwal)->jalankan('santri-lama', $path, $param, null);
        $kueri = count(DB::getQueryLog());
        DB::disableQueryLog();
        @unlink($path);

        return ['hasil' => $hasil, 'kueri' => $kueri];
    }

    public function test_jumlah_kueri_impor_tidak_tumbuh_mengikuti_jumlah_baris(): void
    {
        // Ini yang membuat produksi menjawab 503 padahal datanya sudah masuk:
        // `simpan()` dulu menulis 6–9 kali per santri — wali, santri, riwayat
        // tingkat (SELECT + INSERT), riwayat NIS, lalu satu per tunggakan.
        $kecil = $this->jalankanImpor(5);
        Santri::query()->delete();
        Wali::query()->delete();
        $besar = $this->jalankanImpor(40);

        $this->assertSame(5, $kecil['hasil']['tersimpan']['santri']);
        $this->assertSame(40, $besar['hasil']['tersimpan']['santri']);

        $this->assertLessThanOrEqual(
            $kecil['kueri'] + 3,
            $besar['kueri'],
            "Impor 40 baris memakai {$besar['kueri']} kueri, sedangkan 5 baris {$kecil['kueri']} — "
            .'jumlahnya masih tumbuh mengikuti baris.',
        );
        $this->assertLessThan(30, $besar['kueri']);
    }

    public function test_seluruh_turunan_santri_ikut_tertulis(): void
    {
        // Yang paling mudah hilang saat menulis berkelompok: baris turunan yang
        // dulu dibuat satu per satu tepat setelah santrinya lahir.
        $hasil = $this->jalankanImpor(6)['hasil'];

        $this->assertSame(6, $hasil['tersimpan']['santri']);
        $this->assertSame(6, $hasil['tersimpan']['tagihan'], 'Tiap baris punya satu tunggakan SPP.');
        $this->assertSame(6, DB::table('nis_santri')->count());
        $this->assertSame(6, DB::table('riwayat_tingkat')->count());

        // Turunannya harus menunjuk santri yang BENAR, bukan sekadar berjumlah sama.
        $santri = Santri::orderBy('nis')->first();
        $this->assertSame(1, DB::table('nis_santri')->where('id_santri', $santri->id)->where('nis', $santri->nis)->count());
        $this->assertSame(1, DB::table('riwayat_tingkat')->where('id_santri', $santri->id)
            ->where('tahun_ajaran', $santri->tahun_ajaran)->count());

        $tagihan = DB::table('tagihan_santri')->where('id_santri', $santri->id)->first();
        $this->assertSame('spp', $tagihan->perilaku, 'Perilaku disalin dari jenis biayanya.');
        $this->assertTrue((bool) $tagihan->sudah_akrual);
        $this->assertSame(0, bccomp($tagihan->nominal, '150000', 2));
    }

    public function test_kakak_beradik_seberkas_tetap_berbagi_satu_wali(): void
    {
        // Yang paling mudah rusak oleh simpanan: wali yang baru dibuat di tengah
        // perulangan tak lagi ditemukan lewat kueri oleh baris berikutnya.
        $path = $this->berkas(4); // 4 baris, 2 nomor telepon
        $param = ['jenis_tunggakan_spp' => 'SPP-UJI', 'jenis_tunggakan_uang_pangkal' => '',
            'jenis_tunggakan_daftar_ulang' => '', 'jenis_tunggakan_lain' => ''];

        $hasil = (new ImporSaldoAwal)->jalankan('santri-lama', $path, $param, null);
        @unlink($path);

        $this->assertSame(4, $hasil['tersimpan']['santri']);
        $this->assertSame(2, $hasil['tersimpan']['wali'], 'Empat santri berbagi dua wali, bukan empat.');
        $this->assertSame(2, Wali::count());
    }
}
