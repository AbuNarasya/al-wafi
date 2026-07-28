<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\JournalEntry;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\DompetService;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Akuntansi PPSB: pembayaran santri → jurnal, SPP akrual, dompet topup & pindah. */
class PpsbAkuntansiTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZAK';
    private const KAS = 'ZZAK.KAS';
    private const PEND_REG = '4.ZZAK.REG';
    private const PEND_SPP = '4.ZZAK.SPP';
    private const PIUT_SPP = '1.ZZAK.SPP';
    private const UNIT = 'ZZUNIT';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'AK']);
        foreach ([
            [self::KAS, 'Kas', 'debet'], [self::PEND_REG, 'Pendapatan Registrasi', 'kredit'],
            [self::PEND_SPP, 'Pendapatan SPP', 'kredit'], [self::PIUT_SPP, 'Piutang SPP', 'debet'],
            ['2.1.01.009', 'Titipan Dompet Wali', 'kredit'], ['2.1.01.006', 'Titipan Uang Saku', 'kredit'],
            ['2.1.01.007', 'Titipan Tabungan', 'kredit'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'bank']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        \App\Models\Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true])->id_pengguna;
        \App\Models\TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        \App\Models\JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => '2026/2027']);
        \App\Models\Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);

        (new JenisBiayaService)->create(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND_REG, 'kode_unit' => self::UNIT, 'tahun_ajaran' => '2026/2027']);
    }

    private function buatSantri(bool $aktif = false, ?string $jenjang = null): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812'.rand(1000, 9999)]);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kode_jenjang' => $jenjang, 'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler']);
        if ($aktif) {
            $santri->update(['status' => 'aktif']);
        }

        return $santri->refresh();
    }

    public function test_pembayaran_registrasi_verifikasi_jurnal_dan_terbayar(): void
    {
        $santri = $this->buatSantri();
        $tagihan = TagihanSantri::where('id_santri', $santri->id)->first();
        $svc = new PembayaranSantriService;

        $bayar = $svc->catat(['id_santri' => $santri->id, 'id_tagihan' => $tagihan->id, 'tanggal' => '2026-07-10', 'nominal' => '500000', 'kode_rekening' => self::KAS], $this->admin, 'ppsb');
        $this->assertSame('menunggu_verifikasi', $bayar->status);
        $this->assertSame(0, JournalEntry::where('sumber_modul', 'PembayaranSantri')->count()); // belum berjurnal

        $svc->verifikasi($bayar->id, $this->admin);
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'PembayaranSantri')->where('id_sumber', (string) $bayar->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(500000.0, (float) $entry->lines->firstWhere('kode_coa', self::KAS)->debet);
        $this->assertSame(500000.0, (float) $entry->lines->firstWhere('kode_coa', self::PEND_REG)->kredit);

        $this->assertSame('lunas', $tagihan->refresh()->status);
        $this->assertSame('terbayar', $santri->refresh()->status); // registrasi lunas → maju tahap
    }

    public function test_spp_generate_akrual(): void
    {
        $santri = $this->buatSantri(aktif: true, jenjang: 'SD');
        (new JenisBiayaService)->create(['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '300000', 'kode_coa_pendapatan' => self::PEND_SPP, 'kode_coa_piutang' => self::PIUT_SPP, 'kode_unit' => self::UNIT, 'berulang' => true, 'tahun_ajaran' => '2026/2027', 'kode_jenjang' => 'SD']);

        $hasil = (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $this->assertSame(1, $hasil['terbit']);

        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'SPP-SD')->first();
        $this->assertNotNull($tagihan);
        $this->assertTrue((bool) $tagihan->sudah_akrual);
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'TagihanSpp')->first();
        $this->assertSame(300000.0, (float) $entry->lines->firstWhere('kode_coa', self::PIUT_SPP)->debet);
        $this->assertSame(300000.0, (float) $entry->lines->firstWhere('kode_coa', self::PEND_SPP)->kredit);
    }

    public function test_dompet_topup_verifikasi_lalu_distribusi(): void
    {
        $santri = $this->buatSantri(aktif: true);
        $wali = Wali::find($santri->id_wali);
        $svc = new DompetService;

        $topup = $svc->topUp(['id_wali' => $wali->id, 'tanggal' => '2026-07-05', 'nominal' => '200000', 'kode_rekening' => self::KAS], $this->admin);
        $svc->verifikasiTopUp($topup->id, $this->admin);
        $dompetWali = \App\Models\DompetWali::where('id_wali', $wali->id)->first();
        $this->assertSame(200000.0, (float) $dompetWali->saldo);

        // Jurnal topup: D Kas / K Titipan Wali.
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'MutasiDompet')->where('id_sumber', (string) $topup->id)->first();
        $this->assertSame(200000.0, (float) $entry->lines->firstWhere('kode_coa', self::KAS)->debet);
        $this->assertSame(200000.0, (float) $entry->lines->firstWhere('kode_coa', '2.1.01.009')->kredit);

        // Distribusi Wali → Santri (uang jajan).
        $svc->pindah(['dari' => 'wali', 'ke' => 'santri', 'id_wali' => $wali->id, 'id_santri' => $santri->id, 'tanggal' => '2026-07-06', 'nominal' => '50000'], $this->admin);
        $this->assertSame(150000.0, (float) $dompetWali->refresh()->saldo);
        $this->assertSame(50000.0, (float) \App\Models\DompetSantri::where('id_santri', $santri->id)->first()->saldo);
    }

    public function test_bayar_tagihan_dari_dompet_wali(): void
    {
        $santri = $this->buatSantri(aktif: true, jenjang: 'SD');
        (new JenisBiayaService)->create(['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '300000', 'kode_coa_pendapatan' => self::PEND_SPP, 'kode_coa_piutang' => self::PIUT_SPP, 'kode_unit' => self::UNIT, 'berulang' => true, 'tahun_ajaran' => '2026/2027', 'kode_jenjang' => 'SD']);
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);
        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'SPP-SD')->first();

        // Isi dompet wali.
        $svc = new DompetService;
        $topup = $svc->topUp(['id_wali' => $santri->id_wali, 'tanggal' => '2026-07-05', 'nominal' => '300000', 'kode_rekening' => self::KAS], $this->admin);
        $svc->verifikasiTopUp($topup->id, $this->admin);

        // Bayar SPP dari dompet (tanpa verifikasi ulang). Akrual → kredit piutang.
        (new PembayaranSantriService)->bayarDariDompet(['id_tagihan' => $tagihan->id, 'tanggal' => '2026-07-10', 'nominal' => '300000'], $this->admin);
        $this->assertSame('lunas', $tagihan->refresh()->status);
        $this->assertSame(0.0, (float) \App\Models\DompetWali::where('id_wali', $santri->id_wali)->first()->saldo);
    }
}
