<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
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
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Export\DatasetRegistry;
use App\Support\Referensi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Calon yang MENGUNDURKAN DIRI: tagihannya ditutup, dan ia pindah ke daftarnya
 * sendiri.
 *
 * Latar aslinya: `mengundurkanDiri()` dulu hanya mengubah status santrinya.
 * Tagihan registrasi tetap berdiri sebagai "Belum bayar", sehingga calon yang
 * sudah pamit masih ditawarkan di pemilih pembayaran — dan tunggakannya ikut
 * terhitung, padahal tak ada jasa yang pernah diberikan.
 */
class CalonMengundurkanDiriTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZMD';

    private const PEND = '4.ZZMD.PEND';

    private const PIUT = '1.ZZMD.PIUT';

    private const KAS = '1.ZZMD.KAS';

    private const UNIT = 'ZZMDU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'MD']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet'], [self::KAS, 'Kas', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'REG', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1, 'jumlah_tingkat' => 3]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        foreach ([['REG-SMP', 'registrasi', '1000000'], ['UP-SMP', 'uang_pangkal', '20000000'], ['PLK-SMP', 'perlengkapan', '3000000']] as [$kode, $tipe, $nominal]) {
            $this->buatBiaya([
                'kode' => $kode, 'nama' => $kode, 'tipe' => $tipe, 'kode_jenjang' => 'SMP',
                'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
                'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'nominal' => $nominal,
            ]);
        }
    }

    /** Calon baru — tagihan registrasi terbit otomatis saat didaftarkan. */
    private function calon(): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);

        return (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'REG', 'kode_jenjang' => 'SMP', 'gelombang' => 1,
        ])->refresh();
    }

    /**
     * Calon yang sudah lolos kesehatan — tahap paling awal yang boleh ditagih
     * uang pangkal. Registrasinya sengaja TIDAK dibayar: yang diuji justru
     * tagihan yang masih menggantung saat ia mundur.
     */
    private function calonLolosKesehatan(): Santri
    {
        $svc = new SantriService;
        $santri = $this->calon();
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);
        $svc->medcheck($santri->id, ['lolos' => true, 'dokumen_lengkap' => true]);

        return $santri->refresh();
    }

    private function tagihan(Santri $s, string $perilaku): ?TagihanSantri
    {
        return TagihanSantri::where('id_santri', $s->id)->where('perilaku', $perilaku)->orderByDesc('id')->first();
    }

    /** Bayar penuh/sebagian sebuah tagihan, lalu verifikasi keuangan. */
    private function bayar(Santri $s, TagihanSantri $t, string $nominal): void
    {
        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $s->id, 'id_tagihan' => $t->id, 'tanggal' => now()->toDateString(),
            'nominal' => $nominal, 'kode_rekening' => self::KAS, 'metode' => 'transfer',
        ], $this->admin, 'ppsb');
        $svc->verifikasi($p->id, $this->admin);
    }

    /** BELUM DIBAYAR → tagihannya hilang: sisa nol, batal, dan jejaknya "Dibatalkan". */
    public function test_registrasi_belum_dibayar_dibatalkan(): void
    {
        $santri = $this->calon();
        $reg = $this->tagihan($santri, 'registrasi');
        $this->assertSame('belum_bayar', $reg->status);

        (new SantriService)->mengundurkanDiri($santri->id, 'Pindah kota', $this->admin);

        $reg->refresh();
        $this->assertSame('batal', $reg->status);
        $this->assertSame('0.00', $reg->sisa);
        $this->assertStringContainsString('Dibatalkan — mengundurkan diri', $reg->keterangan);
        $this->assertSame('mengundurkan_diri', $santri->refresh()->status);
    }

    /**
     * Uang pangkal & perlengkapan ikut ditutup — keduanya terbit dari satu form
     * dan sama-sama melekat pada penerimaan yang batal terjadi.
     */
    public function test_uang_pangkal_dan_perlengkapan_ikut_dibatalkan(): void
    {
        $santri = $this->calonLolosKesehatan();
        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '20000000', 'nominal_perlengkapan' => '3000000',
        ]);

        (new SantriService)->mengundurkanDiri($santri->id, 'Diterima di pesantren lain', $this->admin);

        foreach (['registrasi', 'uang_pangkal', 'perlengkapan'] as $perilaku) {
            $t = $this->tagihan($santri, $perilaku);
            $this->assertSame('batal', $t->status, "tagihan {$perilaku} harus ditutup");
            $this->assertSame('0.00', $t->sisa, "sisa tagihan {$perilaku} harus nol");
        }
    }

    /**
     * SUDAH ADA PEMBAYARAN → sisanya dihapus, yang telanjur dibayar HANGUS.
     * Uangnya tetap menjadi penerimaan pesantren: registrasi & uang pangkal calon
     * diakui saat uang diterima, jadi jurnalnya sudah benar sejak awal dan tak
     * ada yang perlu dibalik.
     */
    public function test_yang_sudah_dibayar_sebagian_hangus_bukan_hilang(): void
    {
        $santri = $this->calonLolosKesehatan();
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '20000000']);
        $up = $this->tagihan($santri, 'uang_pangkal');
        $this->bayar($santri, $up, '5000000');
        $this->assertSame('sebagian', $up->refresh()->status);

        (new SantriService)->mengundurkanDiri($santri->id, 'Berhenti di tengah jalan', $this->admin);

        $up->refresh();
        $this->assertSame('batal', $up->status);
        $this->assertSame('0.00', $up->sisa);
        $this->assertStringContainsString('Hangus — mengundurkan diri', $up->keterangan);
        $this->assertStringContainsString('5000000', $up->keterangan);

        // Pembayarannya TIDAK dihapus — itu uang yang benar-benar diterima.
        $this->assertDatabaseHas('pembayaran_santri', ['id_tagihan' => $up->id, 'status' => 'terverifikasi']);
    }

    /** Tagihan LUNAS tak disentuh: barisnya jejak kuitansi, bukan tagihan menggantung. */
    public function test_tagihan_lunas_tidak_ikut_dibatalkan(): void
    {
        $santri = $this->calon();
        $reg = $this->tagihan($santri, 'registrasi');
        $this->bayar($santri, $reg, '1000000');
        $this->assertSame('lunas', $reg->refresh()->status);

        (new SantriService)->mengundurkanDiri($santri->id, 'Mundur setelah membayar', $this->admin);

        $this->assertSame('lunas', $reg->refresh()->status);
        $this->assertStringNotContainsString('mengundurkan diri', (string) $reg->keterangan);
    }

    /**
     * Pembayaran yang masih menunggu verifikasi menahan pengunduran diri: sisa
     * yang dihapuskan tak boleh angka yang masih bisa berubah.
     */
    public function test_pembayaran_menunggu_verifikasi_menahan_pengunduran_diri(): void
    {
        $santri = $this->calon();
        $reg = $this->tagihan($santri, 'registrasi');
        (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $reg->id, 'tanggal' => now()->toDateString(),
            'nominal' => '400000', 'kode_rekening' => self::KAS, 'metode' => 'transfer',
        ], $this->admin, 'ppsb');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/menunggu verifikasi keuangan/');
        (new SantriService)->mengundurkanDiri($santri->id, 'Mundur', $this->admin);
    }

    /** Setelah mundur ia tak boleh lagi ditawarkan di pemilih santri mana pun. */
    public function test_hilang_dari_pemilih_santri_dan_pemilih_tagihan(): void
    {
        $santri = $this->calon();
        $this->assertArrayHasKey($santri->id, Referensi::santri());

        (new SantriService)->mengundurkanDiri($santri->id, 'Mundur', $this->admin);

        $this->assertArrayNotHasKey($santri->id, Referensi::santri());

        // Pemilih di layar Pembayaran PPSB dirakit dari tagihan yang masih hidup.
        $html = $this->actingAs(User::find($this->admin))
            ->get(route('pembayaran_ppsb.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString($santri->no_pendaftaran, $html);
    }

    /** Daftarnya pindah: keluar dari Calon Santri, masuk ke daftar tersendiri. */
    public function test_pindah_dari_daftar_calon_ke_daftar_sendiri(): void
    {
        $santri = $this->calon();
        (new SantriService)->mengundurkanDiri($santri->id, 'Mundur', $this->admin);
        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('santri.calon'))->assertOk()
            ->assertDontSee($santri->no_pendaftaran);

        $this->actingAs($admin)->get(route('santri.mundur'))->assertOk()
            ->assertSee($santri->no_pendaftaran)
            ->assertSee('Calon Mengundurkan Diri');
    }

    /** Ikut punya berkas export sendiri, sejajar Alumni & Santri Keluar. */
    public function test_punya_dataset_export_sendiri(): void
    {
        $santri = $this->calon();
        (new SantriService)->mengundurkanDiri($santri->id, 'Mundur', $this->admin);

        $this->assertContains('calon-mundur', collect(DatasetRegistry::datasets())->pluck('key')->all());

        $baris = (new DatasetRegistry)->rows('calon-mundur');
        $this->assertCount(1, $baris);
        $this->assertSame($santri->no_pendaftaran, $baris[0]['No. Pendaftaran']);
        $this->assertSame('Mengundurkan Diri', $baris[0]['Status']);
        // Tagihannya sudah ditutup, jadi ia tak lagi tampak menunggak.
        $this->assertSame(0, (int) $baris[0]['Sisa Tagihan']);
    }
}
