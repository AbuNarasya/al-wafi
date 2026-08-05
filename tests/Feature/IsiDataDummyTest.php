<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\PembayaranSantri;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TarifBiaya;
use App\Models\User;
use App\Models\Wali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perintah data uji `dummy:isi`.
 *
 * Yang dijaga di sini BUKAN "datanya cantik", tapi bahwa komposisinya tetap
 * seperti yang dijanjikan namanya: 20 wali, 60 calon 20-per-jenjang dengan
 * tahapan tersebar, dan 120 santri aktif 10-per-tingkat. Perintah ini dipakai
 * untuk menyiapkan basis data setelah `migrate:fresh`, jadi kalau komposisinya
 * bergeser tanpa disadari, pengujian manual sesudahnya ikut menguji hal lain.
 *
 * Sekalian menjadi uji integrasi tipis: perintahnya menulis lewat service asli,
 * jadi lulusnya berarti rantai registrasi → pembayaran → verifikasi → seleksi →
 * pengumuman → med check → penagihan uang pangkal memang masih tersambung.
 */
class IsiDataDummyTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2027/2028';

    private const GRP = 'ZZDM';

    private const KAS = '1.ZZDM.KAS';

    private const PEND = '4.ZZDM.PEND';

    private const PIUT = '1.ZZDM.PIUT';

    private const UNIT = 'ZZDMU';

    /** Jenjang beserta jumlah tingkatnya — sama bentuknya dengan master di lapangan. */
    private const JENJANG = ['J001' => 6, 'J002' => 3, 'J003' => 3];

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Dummy']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);

        Level::create(['kode_level' => 'L1', 'nama_level' => 'Direktur', 'max_transaksi' => null]);
        User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true]);
        User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true]);

        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => '2028/2029', 'status' => 'aktif']);

        $urutan = 0;
        foreach (self::JENJANG as $kode => $tingkat) {
            Jenjang::create(['kode' => $kode, 'nama' => $kode, 'urutan' => ++$urutan,
                'status' => 'aktif', 'jumlah_tingkat' => $tingkat]);
        }
        foreach (['001' => 'Reguler', '002' => 'Pindahan', '005' => 'Anak Karyawan', '006' => 'OSS'] as $kode => $nama) {
            JalurPendaftaran::create(['kode' => $kode, 'nama' => $nama, 'status' => 'aktif']);
        }

        // `santri.sumber_informasi` berkunci asing ke master ini; yang terakhir
        // butuh keterangan tambahan (isian bersyarat).
        foreach ([['001', 'Sosial Media', false], ['002', 'Brosur', false], ['999', 'Lainnya', true]] as $i => [$kode, $nama, $butuh]) {
            SumberInformasi::create([
                'kode' => $kode, 'nama' => $nama, 'urutan' => $i + 1,
                'butuh_keterangan' => $butuh, 'status' => 'aktif',
            ]);
        }

        $this->masterBiaya();
    }

    /**
     * Jenis biaya per jenjang + sel tarif Umum-nya. Registrasi tanpa akun piutang
     * (cash basis); sisanya berpiutang, karena tunggakan santri lama harus bisa
     * ditunjuk ke sana.
     */
    private function masterBiaya(): void
    {
        foreach (array_keys(self::JENJANG) as $j) {
            foreach (['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp', 'lain'] as $perilaku) {
                JenisBiaya::create([
                    'kode' => "{$j}-{$perilaku}", 'nama' => ucfirst($perilaku)." {$j}", 'tipe' => $perilaku,
                    'kode_jenjang' => $j, 'kode_coa_pendapatan' => self::PEND,
                    'kode_coa_piutang' => $perilaku === 'registrasi' ? null : self::PIUT,
                    'kode_unit' => self::UNIT, 'status' => 'aktif',
                ]);
            }

            // Biaya masuk kini SELALU per jalur — tak ada lagi baris "Umum
            // (semua jalur)" sebagai cadangan.
            foreach (['registrasi' => '500000', 'uang_pangkal' => '25000000', 'perlengkapan' => '8000000'] as $perilaku => $nominal) {
                foreach (\App\Models\JalurPendaftaran::pluck('kode') as $kodeJalur) {
                    TarifBiaya::create([
                        'tahun_ajaran' => self::TA, 'kode_jenjang' => $j, 'kode_jalur' => $kodeJalur,
                        'perilaku' => $perilaku, 'nominal' => $nominal, 'bebas' => false,
                    ]);
                }
            }

            // Jalur Anak Karyawan: registrasinya BEBAS. Sengaja ditiru dari master
            // di lapangan — inilah jalur yang membuat calon tertahan di "Calon".
            TarifBiaya::updateOrCreate([
                'tahun_ajaran' => self::TA, 'kode_jenjang' => $j, 'kode_jalur' => '005',
                'perilaku' => 'registrasi',
            ], ['nominal' => null, 'bebas' => true]);
        }
    }

    public function test_ppsb_menghasilkan_20_wali_dan_60_calon_20_per_jenjang(): void
    {
        $this->artisan('dummy:isi', ['--bagian' => 'ppsb'])->assertExitCode(0);

        $this->assertSame(20, Wali::count());
        $this->assertSame(60, Santri::count());

        foreach (array_keys(self::JENJANG) as $j) {
            $this->assertSame(20, Santri::where('kode_jenjang', $j)->count(), "jatah jenjang {$j}");
        }

        // Sebaran tahapan — sama untuk ketiga jenjang, jadi tiap angka kelipatan 3.
        $this->assertSame([
            'calon' => 12, 'diseleksi' => 6, 'diterima' => 9, 'gagal_medcheck' => 3,
            'lolos_kesehatan' => 6, 'mengundurkan_diri' => 3, 'terbayar' => 9,
            'terverifikasi' => 6, 'tidak_lulus' => 6,
        ], Santri::query()->selectRaw('status, count(*) c')->groupBy('status')
            ->orderBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all());

        // Setiap wali menaungi minimal satu anak, dan ada yang lebih dari satu
        // (satu Dompet Wali per keluarga = kasus yang paling perlu diuji).
        $this->assertSame(0, Wali::whereDoesntHave('santri')->count());
        $this->assertGreaterThan(0, Wali::has('santri', '>', 1)->count());

        // Nama TIDAK boleh kembar: saat menguji dari layar, dua baris bernama
        // sama membuat mustahil tahu baris mana yang sedang dibuka.
        $this->assertSame(60, Santri::distinct()->count('nama'), 'nama calon harus unik');
    }

    /**
     * Tahapan tidak dipalsukan: yang berstatus di atas "calon" benar-benar
     * melewati pembayaran registrasi yang diverifikasi keuangan, dan itu
     * menerbitkan jurnal. Satu setoran sengaja ditinggal menggantung.
     */
    public function test_tahapan_ditempuh_lewat_pembayaran_dan_berjurnal(): void
    {
        $this->artisan('dummy:isi', ['--bagian' => 'ppsb'])->assertExitCode(0);

        $terverifikasi = PembayaranSantri::where('status', 'terverifikasi')->count();
        $this->assertSame(45, $terverifikasi, 'satu setoran per calon yang melewati tahap registrasi berbayar');
        $this->assertSame(3, PembayaranSantri::where('status', 'menunggu_verifikasi')->count());
        $this->assertSame($terverifikasi, JournalEntry::count(), 'satu jurnal per pembayaran terverifikasi');

        $this->assertSame(45, TagihanSantri::where('perilaku', 'registrasi')->where('status', 'lunas')->count());

        // Calon berjalur bebas registrasi tak punya tagihan registrasi sama sekali,
        // tetapi tahapnya TETAP MAJU — tanpa itu ia tertahan selamanya di "Calon".
        $bebas = Santri::where('jalur', '005')->get();
        $this->assertCount(3, $bebas);
        foreach ($bebas as $s) {
            $this->assertSame('terbayar', $s->status);
            $this->assertSame(0, TagihanSantri::where('id_santri', $s->id)->count());
        }
    }

    /** Yang lolos kesehatan sudah ditagih uang pangkal + perlengkapan. */
    public function test_lolos_kesehatan_sudah_ditagih_uang_pangkal_dan_perlengkapan(): void
    {
        $this->artisan('dummy:isi', ['--bagian' => 'ppsb'])->assertExitCode(0);

        $lolos = Santri::where('status', 'lolos_kesehatan')->pluck('id');
        $this->assertCount(6, $lolos);
        $this->assertSame(6, TagihanSantri::whereIn('id_santri', $lolos)->where('perilaku', 'uang_pangkal')->count());
        $this->assertSame(6, TagihanSantri::whereIn('id_santri', $lolos)->where('perilaku', 'perlengkapan')->count());
    }

    /**
     * Santri aktif: 60 SDTQ (10 per tingkat 1–6), 30 SMP & 30 SMA (10 per
     * tingkat 1–3). Semuanya lewat pemeta Impor Santri Lama, jadi TANPA jurnal.
     */
    public function test_kependidikan_menghasilkan_120_santri_aktif_10_per_tingkat(): void
    {
        $this->artisan('dummy:isi', ['--bagian' => 'kependidikan'])->assertExitCode(0);

        $this->assertSame(120, Santri::where('status', 'aktif')->count());

        foreach (self::JENJANG as $jenjang => $tingkatMaks) {
            for ($t = 1; $t <= $tingkatMaks; $t++) {
                $this->assertSame(10, Santri::where('status', 'aktif')
                    ->where('kode_jenjang', $jenjang)->where('tingkat', $t)->count(), "{$jenjang} tingkat {$t}");
            }
        }

        // NIS wajib ada & unik (santri lama memakai NIS aslinya), riwayat tingkat
        // wajib terisi supaya kenaikan tahun depan punya titik awal.
        $this->assertSame(0, Santri::where('status', 'aktif')->whereNull('nis')->count());
        $this->assertSame(120, Santri::whereNotNull('nis')->distinct()->count('nis'));
        $this->assertSame(120, Santri::distinct()->count('nama'), 'nama santri aktif harus unik');
        $this->assertSame(120, RiwayatTingkat::count());

        // Tunggakan warisan: bertanda sudah_akrual, dan TIDAK berjurnal.
        $tunggakan = TagihanSantri::where('sudah_akrual', true)->get();
        $this->assertGreaterThan(0, $tunggakan->count());
        $this->assertSame(0, JournalEntry::count(), 'impor data awal tidak boleh menerbitkan jurnal');
        $this->assertSame(
            ['daftar_ulang', 'lain', 'spp', 'uang_pangkal'],
            $tunggakan->pluck('perilaku')->unique()->sort()->values()->all(),
        );
    }

    /** Dijalankan dua kali tidak menggandakan apa pun. */
    public function test_aman_dijalankan_ulang(): void
    {
        $this->artisan('dummy:isi')->assertExitCode(0);
        // Kedua kumpulan memakai daftar nama keluarga yang berbeda, jadi calon &
        // santri aktif pun tak boleh bertabrakan namanya.
        $this->assertSame(180, Santri::distinct()->count('nama'));

        $wali = Wali::count();
        $santri = Santri::count();
        $tagihan = TagihanSantri::count();
        $jurnal = JournalEntry::count();

        $this->artisan('dummy:isi')->assertExitCode(0);

        $this->assertSame($wali, Wali::count());
        $this->assertSame($santri, Santri::count());
        $this->assertSame($tagihan, TagihanSantri::count());
        $this->assertSame($jurnal, JournalEntry::count());
    }
}
