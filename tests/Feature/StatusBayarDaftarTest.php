<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\RekapPembayaranService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Status pembayaran mutakhir di DAFTAR (calon santri & pemilih rekap).
 *
 * Yang dikunci: setoran yang sudah dicatat tapi belum diverifikasi keuangan
 * TIDAK mengurangi `tagihan.sisa`, jadi tanpa perlakuan khusus santri yang baru
 * membayar tampak "belum bayar". Status "menunggu" harus menang, tanpa pernah
 * ikut dihitung sebagai uang terbayar.
 */
class StatusBayarDaftarTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZSB';
    private const KAS = '1.ZZSB.KAS';
    private const PEND = '4.ZZSB.REG';
    private const UNIT = 'ZZSBU';
    private const TA = '2026/2027';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'SB']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan Registrasi', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        (new JenisBiayaService)->create([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '1000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function calon(string $nama): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);

        return (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ]);
    }

    private function bayar(Santri $santri, string $nominal)
    {
        return (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $santri->tagihan()->first()->id,
            'tanggal' => now()->toDateString(), 'nominal' => $nominal, 'kode_rekening' => self::KAS,
            'metode' => 'tunai',
        ], (int) $this->admin->id_pengguna, 'ppsb');
    }

    /** Sengaja BUKAN status(): nama itu final milik PHPUnit\Framework\TestCase. */
    private function statusBayar(Santri $santri): string
    {
        return (new RekapPembayaranService)->ringkasMassal([$santri->id])[$santri->id]['status'];
    }

    public function test_status_mengikuti_keadaan_pembayaran(): void
    {
        $belum = $this->calon('Belum');
        $this->assertSame('belum', $this->statusBayar($belum));

        // Dicatat tapi belum diverifikasi → "menunggu", bukan "belum".
        $menunggu = $this->calon('Menunggu');
        $this->bayar($menunggu, '400000');
        $this->assertSame('menunggu', $this->statusBayar($menunggu));

        // Diverifikasi sebagian → "sebagian".
        $sebagian = $this->calon('Sebagian');
        $bayarSebagian = $this->bayar($sebagian, '400000');
        (new PembayaranSantriService)->verifikasi($bayarSebagian->id, (int) $this->admin->id_pengguna);
        $this->assertSame('sebagian', $this->statusBayar($sebagian));

        // Lunas penuh.
        $lunas = $this->calon('Lunas');
        $bayarLunas = $this->bayar($lunas, '1000000');
        (new PembayaranSantriService)->verifikasi($bayarLunas->id, (int) $this->admin->id_pengguna);
        $this->assertSame('lunas', $this->statusBayar($lunas));
    }

    public function test_nominal_menunggu_tidak_dihitung_sebagai_terbayar(): void
    {
        $santri = $this->calon('Menunggu');
        $this->bayar($santri, '400000');

        $r = (new RekapPembayaranService)->ringkasMassal([$santri->id])[$santri->id];
        $this->assertSame('400000.00', $r['menunggu']);
        $this->assertSame('0.00', $r['terbayar'], 'Uang yang belum diverifikasi tak boleh diakui sebagai terbayar.');
        $this->assertSame('1000000.00', $r['sisa'], 'Sisa tagihan juga belum boleh berkurang.');
    }

    public function test_ringkas_massal_satu_query_untuk_banyak_santri(): void
    {
        $a = $this->calon('A');
        $b = $this->calon('B');
        $this->bayar($b, '400000');

        \DB::enableQueryLog();
        $hasil = (new RekapPembayaranService)->ringkasMassal([$a->id, $b->id]);
        $jumlahQuery = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertCount(2, $hasil);
        $this->assertSame(2, $jumlahQuery, 'Harus tetap 2 query agregat berapa pun jumlah santrinya (bukan N+1).');
    }

    public function test_kolom_pembayaran_tampil_di_daftar_dan_rekap(): void
    {
        $santri = $this->calon('Menunggu');
        $this->bayar($santri, '400000');

        $this->actingAs($this->admin)->get('/ppsb/calon-santri')->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('Menunggu verifikasi');

        $this->actingAs($this->admin)->get('/rekap-pembayaran')->assertOk()
            ->assertSee('Menunggu verifikasi');
    }

    public function test_filter_status_bayar_menyaring_di_server(): void
    {
        $lunas = $this->calon('SiLunas');
        $bayar = $this->bayar($lunas, '1000000');
        (new PembayaranSantriService)->verifikasi($bayar->id, (int) $this->admin->id_pengguna);
        $this->calon('SiBelum');
        $menunggu = $this->calon('SiMenunggu');
        $this->bayar($menunggu, '250000');

        $this->actingAs($this->admin)->get('/ppsb/calon-santri?bayar=menunggu')->assertOk()
            ->assertSee('SiMenunggu')->assertDontSee('SiLunas')->assertDontSee('SiBelum');

        $this->actingAs($this->admin)->get('/ppsb/calon-santri?bayar=lunas')->assertOk()
            ->assertSee('SiLunas')->assertDontSee('SiMenunggu');

        $this->actingAs($this->admin)->get('/ppsb/calon-santri?bayar=belum')->assertOk()
            ->assertSee('SiBelum')->assertDontSee('SiMenunggu');
    }

    /** Detail santri menunjukkan setoran yang belum diverifikasi per tagihan. */
    public function test_detail_santri_menampilkan_nominal_menunggu(): void
    {
        $santri = $this->calon('Ahmad');
        $this->bayar($santri, '400000');

        $this->actingAs($this->admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('menunggu verifikasi')
            ->assertSee('sudah disetor, menunggu keuangan');
    }
}
