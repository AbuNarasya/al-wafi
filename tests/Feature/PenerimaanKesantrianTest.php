<?php

namespace Tests\Feature;

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
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use App\Services\Ppsb\DompetPolicy;
use App\Services\Reports\PenerimaanKesantrianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Laporan penerimaan kesantrian per periode.
 *
 * Titik beratnya: setoran jatuh di BULAN SETORANNYA, bukan bulan tagihannya.
 * SPP Juli yang dibayar pada September adalah penerimaan September — kalau
 * keduanya tertukar, laporan ini akan bertengkar dengan buku besar setiap kali
 * ada tunggakan yang terlunasi.
 */
class PenerimaanKesantrianTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZPK';

    private const PEND = '4.ZZPK.PEND';

    private const PIUT = '1.ZZPK.PIUT';

    private const KAS = '1.ZZPK.KAS';

    private const UNIT = 'ZZPKU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Penerimaan']);
        foreach ([
            [self::PEND, 'Pendapatan SPP', 'kredit'],
            [self::PIUT, 'Piutang Santri', 'debet'],
            [self::KAS, 'Kas', 'debet'],
            [DompetPolicy::COA_TITIPAN['wali'], 'Titipan Dompet Wali', 'kredit'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);

        $this->admin = User::create([
            'username' => 'admpk', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '250000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'berulang' => true]);
    }

    private function santriAktif(string $nama = 'Ahmad'): Santri
    {
        $wali = (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Budi',
            'telepon_ayah' => '08'.random_int(100000, 999999),
        ]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tingkat' => 1]);

        return $santri->refresh();
    }

    /** Catat + verifikasi satu setoran; hanya yang terverifikasi yang dilaporkan. */
    private function bayar(Santri $santri, TagihanSantri $tagihan, string $tanggal, string $nominal): void
    {
        $p = (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $tagihan->id,
            'tanggal' => $tanggal, 'nominal' => $nominal, 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');

        (new PembayaranSantriService)->verifikasi($p->id, $this->admin);
    }

    private function laporan(string $from = '2026-07-01', string $to = '2026-09-30', ...$sisa): array
    {
        return (new PenerimaanKesantrianService)->laporan($from, $to, ...$sisa);
    }

    public function test_setoran_jatuh_di_bulan_setorannya_bukan_bulan_tagihannya(): void
    {
        $santri = $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $tagihan = TagihanSantri::where('kode_jenis', 'SPP-SD')->firstOrFail();

        // Tagihan SPP Juli, dilunasi September.
        $this->bayar($santri, $tagihan, '2026-09-10', '250000');

        $d = $this->laporan();
        $baris = collect($d['baris'])->firstWhere('kode_jenis', 'SPP-SD');

        $this->assertSame('0.00', $baris['per_bulan']['2026-07']);
        $this->assertSame('250000.00', $baris['per_bulan']['2026-09']);
        $this->assertSame('250000.00', $baris['total']);
        $this->assertSame('250000.00', $d['total']);
    }

    public function test_hanya_setoran_terverifikasi_yang_dihitung(): void
    {
        $santri = $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $tagihan = TagihanSantri::where('kode_jenis', 'SPP-SD')->firstOrFail();

        // Dicatat tapi TIDAK diverifikasi — belum jadi penerimaan.
        (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $tagihan->id,
            'tanggal' => '2026-08-01', 'nominal' => '100000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');

        $d = $this->laporan();

        $this->assertSame('0.00', $d['total']);
        $this->assertSame([], $d['baris']);
    }

    public function test_cocok_dengan_jurnal_pembayaran_santri(): void
    {
        $santri = $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $tagihan = TagihanSantri::where('kode_jenis', 'SPP-SD')->firstOrFail();

        $this->bayar($santri, $tagihan, '2026-07-20', '150000');
        $this->bayar($santri, $tagihan, '2026-08-05', '100000');

        $d = $this->laporan();

        $this->assertSame('250000.00', $d['total']);
        $this->assertSame('250000.00', $d['pembanding']['buku_besar']);
        $this->assertTrue($d['pembanding']['cocok']);
        $this->assertFalse($d['pembanding']['dimatikan']);
    }

    public function test_perbandingan_buku_besar_dimatikan_saat_disaring(): void
    {
        $santri = $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $this->bayar($santri, TagihanSantri::where('kode_jenis', 'SPP-SD')->firstOrFail(), '2026-07-20', '150000');

        $d = $this->laporan('2026-07-01', '2026-09-30', 'SD');

        $this->assertTrue($d['pembanding']['dimatikan']);
        $this->assertNull($d['pembanding']['buku_besar']);
    }

    public function test_penyaring_jenjang_menyisihkan_jenjang_lain(): void
    {
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);

        $santri = $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $this->bayar($santri, TagihanSantri::where('kode_jenis', 'SPP-SD')->firstOrFail(), '2026-07-20', '150000');

        $this->assertSame('150000.00', $this->laporan('2026-07-01', '2026-09-30', 'SD')['total']);
        $this->assertSame('0.00', $this->laporan('2026-07-01', '2026-09-30', 'SMP')['total']);
    }

    public function test_kolom_bulan_mengikuti_rentang_tanggal(): void
    {
        $d = $this->laporan('2026-07-01', '2026-09-30');

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($d['bulan'], 'kunci'));
    }

    public function test_halaman_terbuka_dan_mencetak_barisnya(): void
    {
        $santri = $this->santriAktif();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $this->bayar($santri, TagihanSantri::where('kode_jenis', 'SPP-SD')->firstOrFail(), '2026-07-20', '150000');

        $this->actingAs(User::find($this->admin))
            ->get(route('penerimaan_kesantrian.index', ['from' => '2026-07-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Penerimaan Kesantrian per Periode')
            ->assertSee('SPP SD')
            ->assertSee('Cocok dengan buku besar')
            ->assertDontSee('Belum ada setoran terverifikasi pada rentang ini');
    }
}
