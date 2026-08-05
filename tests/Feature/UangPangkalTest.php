<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\PotonganGelombang;
use App\Models\PotonganUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\AngsuranUangPangkalService;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Uang pangkal: tagihkan (potongan), daftar ulang (akrual), angsuran, evaluasi hangus. */
class UangPangkalTest extends TestCase
{
    use \Tests\Concerns\MelunasiRegistrasi;
    use \Tests\Concerns\MembuatGelombang;
    use \Tests\Concerns\MengaktifkanSantri;
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZUP';
    private const PEND_REG = '4.ZZUP.REG';
    private const PEND_UP = '4.ZZUP.UP';
    private const PIUT_UP = '1.ZZUP.UP';
    private const UNIT = 'ZZUNIT';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'UP']);
        foreach ([[self::PEND_REG, 'Pend Reg', 'kredit'], [self::PEND_UP, 'Pend UP', 'kredit'], [self::PIUT_UP, 'Piutang UP', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        \App\Models\Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        \App\Models\TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        \App\Models\JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => '2026/2027']);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

        // Jenjang dibuat SEBELUM tarif: fixture tarif tanpa jenjang mencerminkan
        // selnya ke tiap jenjang yang sudah ada saat itu.
        $this->jenjangUji();
        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND_REG, 'kode_unit' => self::UNIT, 'tahun_ajaran' => '2026/2027']);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_coa_pendapatan' => self::PEND_UP, 'kode_coa_piutang' => self::PIUT_UP, 'kode_unit' => self::UNIT, 'tahun_ajaran' => '2026/2027']);
    }

    private function buatSantriDiterima(): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812'.rand(1000, 9999)]);
        // Jenjang WAJIB sejak potongan jadi matriks: tak ada lagi sel "semua
        // jenjang", jadi santri tanpa jenjang tak pernah punya sel yang cocok.
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'gelombang' => '1', 'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler', 'kode_jenjang' => $this->jenjangUji()]);
        $santri->update(['status' => 'diterima']);
        // Potongan gelombang kini DIPEROLEH dengan membayar registrasi.
        $this->lunasiRegistrasi($santri->id);

        return $santri->refresh();
    }

    public function test_tagihkan_uang_pangkal_dengan_potongan(): void
    {
        $this->buatPotonganGelombang('2026/2027', '1', $this->jenjangUji(), '1000000');
        $santri = $this->buatSantriDiterima();

        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000'])['uang_pangkal'];
        // Efektif = 5jt - 1jt potongan = 4jt.
        $this->assertSame(4000000.0, (float) $tagihan->nominal);
        $potongan = PotonganUangPangkal::where('id_tagihan', $tagihan->id)->first();
        $this->assertNotNull($potongan);
        $this->assertSame(1000000.0, (float) $potongan->potongan);
        $this->assertSame('berlaku', $potongan->status);
    }

    public function test_daftar_ulang_akrual_dan_nis(): void
    {
        $santri = $this->buatSantriDiterima();
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000']); // tanpa potongan
        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'UP')->first();

        // Menuju aktif: diterima → lolos_kesehatan → daftar ulang.
        $santri->update(['status' => 'lolos_kesehatan']);
        $this->aktifkanSantri($santri->id, $this->admin);

        $santri->refresh();
        $this->assertSame('aktif', $santri->status);
        // NIS SENGAJA belum terbit di sini — nomornya berurut menurut abjad satu
        // angkatan, jadi diterbitkan massal lewat modul Generate NIS.
        $this->assertNull($santri->nis);
        $this->assertTrue((bool) $tagihan->refresh()->sudah_akrual);
        // Akrual sisa 5jt: D Piutang UP / K Pendapatan UP.
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'PembayaranSantri')->where('id_sumber', (string) $tagihan->id)->first();
        $this->assertSame(5000000.0, (float) $entry->lines->firstWhere('kode_coa', self::PIUT_UP)->debet);
    }

    public function test_angsuran_dan_potongan_hangus(): void
    {
        $this->buatPotonganGelombang('2026/2027', '1', $this->jenjangUji(), '1000000');
        $santri = $this->buatSantriDiterima();
        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000'])['uang_pangkal']; // efektif 4jt

        $angsuran = new AngsuranUangPangkalService;
        $rencana = $angsuran->buatRencana($santri->id, [
            'disepakati_pada' => '2026-07-01',
            'termin' => [
                ['nominal' => '2000000', 'jatuh_tempo' => '2026-08-01'],
                ['nominal' => '2000000', 'jatuh_tempo' => '2026-09-01'],
            ],
        ], $this->admin);
        $this->assertCount(2, $rencana->termin);

        // Potongan hangus: tenggat sudah lewat & belum bayar 50%.
        PotonganUangPangkal::where('id_tagihan', $tagihan->id)->update(['tenggat' => Carbon::now()->subDays(1)->toDateString()]);
        $hasil = $angsuran->evaluasiPotongan($tagihan->id);

        $this->assertSame('hangus', $hasil['status']);
        // Potongan 1jt dikembalikan → nominal 4jt + 1jt = 5jt.
        $this->assertSame(5000000.0, (float) $tagihan->refresh()->nominal);
    }
}
