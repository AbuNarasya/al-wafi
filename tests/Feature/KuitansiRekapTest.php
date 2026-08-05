<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\RekapPembayaranService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Terbilang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kuitansi pembayaran (gerbang status) + rekap pembayaran per santri + terbilang. */
class KuitansiRekapTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZKR';
    private const KAS = '1.ZZKR.KAS';
    private const PEND = '4.ZZKR.REG';
    private const UNIT = 'ZZKRU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'KR']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan Registrasi', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true])->id_pengguna;

        // Jenjang dibuat SEBELUM tarif — fixture tarif tanpa jenjang mencerminkan
        // selnya ke jenjang yang sudah ada saat itu, dan santri kini wajib berjenjang.
        $this->jenjangUji();
        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function santriDenganTagihan(): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08777']);

        return (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $this->jenjangUji(),
        ]);
    }

    private function bayar(Santri $santri, string $nominal): PembayaranSantri
    {
        return (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $santri->tagihan()->first()->id,
            'tanggal' => now()->toDateString(), 'nominal' => $nominal, 'kode_rekening' => self::KAS,
            'metode' => 'tunai',
        ], $this->admin, 'ppsb');
    }

    public function test_kuitansi_hanya_untuk_pembayaran_terverifikasi(): void
    {
        $this->actingAs(User::find($this->admin));
        $santri = $this->santriDenganTagihan();
        $bayar = $this->bayar($santri, '200000');

        // Belum diverifikasi → ditolak.
        $this->get(route('pembayaran_ppsb.kuitansi', $bayar->id))->assertForbidden();

        (new PembayaranSantriService)->verifikasi($bayar->id, $this->admin);

        $res = $this->get(route('pembayaran_ppsb.kuitansi', $bayar->id))->assertOk();
        $res->assertSee($bayar->nomor);
        $res->assertSee('Ahmad');
        $res->assertSee('dua ratus ribu rupiah');
        $res->assertSee('Kuitansi Pembayaran');
    }

    public function test_rekap_menghitung_hanya_pembayaran_terverifikasi(): void
    {
        $santri = $this->santriDenganTagihan();
        $terverifikasi = $this->bayar($santri, '200000');
        (new PembayaranSantriService)->verifikasi($terverifikasi->id, $this->admin);
        $this->bayar($santri, '50000'); // menunggu verifikasi

        $rekap = (new RekapPembayaranService)->rekap($santri->id);

        $this->assertSame('500000.00', $rekap['ringkasan']['tagihan']);
        $this->assertSame('200000.00', $rekap['ringkasan']['terbayar']);
        $this->assertSame('300000.00', $rekap['ringkasan']['sisa']);
        $this->assertSame('50000.00', $rekap['ringkasan']['menunggu']);
        $this->assertSame(1, $rekap['ringkasan']['jumlah_pembayaran']);
        $this->assertCount(1, $rekap['tagihan']);
        $this->assertSame('registrasi', $rekap['tagihan'][0]['tipe']);
        $this->assertSame('200000.00', $rekap['tagihan'][0]['terbayar']);
        $this->assertCount(2, $rekap['pembayaran']);
    }

    public function test_halaman_rekap_dan_cetak_tampil(): void
    {
        $this->actingAs(User::find($this->admin));
        $santri = $this->santriDenganTagihan();
        $bayar = $this->bayar($santri, '500000');
        (new PembayaranSantriService)->verifikasi($bayar->id, $this->admin);

        $this->get(route('rekap_pembayaran.index'))->assertOk()->assertSee('Ahmad');
        $this->get(route('rekap_pembayaran.show', $santri->id))->assertOk()
            ->assertSee('Riwayat Pembayaran')->assertSee($bayar->nomor);
        $this->get(route('rekap_pembayaran.cetak', $santri->id))->assertOk()
            ->assertSee('Rekap Pembayaran Santri');
    }

    public function test_terbilang(): void
    {
        $this->assertSame('nol', Terbilang::dari(0));
        $this->assertSame('seribu', Terbilang::dari(1000));
        $this->assertSame('seratus lima puluh ribu', Terbilang::dari(150000));
        $this->assertSame('dua ratus ribu', Terbilang::dari('200000.00'));
        $this->assertSame('satu juta lima ratus ribu', Terbilang::dari(1500000));
        $this->assertSame('dua belas juta tiga ratus empat puluh lima ribu enam ratus tujuh puluh delapan', Terbilang::dari(12345678));
        $this->assertSame('lima ratus ribu rupiah', Terbilang::rupiah(500000));
    }
}
