<?php

namespace Tests\Feature;

use App\Models\Accrue;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\OpeningBalance;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\OpeningBalanceService;
use App\Services\Modules\PengajuanSaldoAwalService;
use App\Services\Modules\SaldoAwalTurunan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SALDO AWAL DOKUMEN — pintu manual per jenis, dan buku besar yang mengikuti.
 *
 * Yang dijaga di sini tiga hal yang kalau meleset merusak neraca tanpa bersuara:
 *
 *   1. Dokumen bertanda `saldo_awal` TIDAK menerbitkan jurnal apa pun.
 *   2. Jumlahnya muncul sendiri sebagai baris turunan di menu Saldo Awal, dan
 *      HANYA sisi neracanya — beban periode lalu tak boleh ikut.
 *   3. Akun yang sudah punya baris turunan TAK BOLEH diketik lagi. Itu satu-satunya
 *      cara angka yang sama masuk dua kali ke jurnal pembuka.
 */
class SaldoAwalDokumenTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZSA';

    private const KAS = '1.ZZSA.1';

    private const UANG_MUKA = '1.ZZSA.2';

    private const HUTANG = '2.ZZSA.1';

    private const HUTANG_LISTRIK = '2.ZZSA.2';

    private const EKUITAS = '3.ZZSA.1';

    private const BEBAN = '5.ZZSA.1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzsa_admin', 'nama' => 'Admin Uji', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        BusinessUnit::create(['kode_unit' => 'ZZSAU', 'nama_unit' => 'Unit Uji']);
        Bagian::create(['kode_bagian' => 'ZZSAB', 'nama_bagian' => 'Bagian Uji', 'status' => 'aktif']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Saldo Awal Uji']);

        foreach ([
            [self::KAS, 'Kas Uji', 'debet'],
            [self::UANG_MUKA, 'Uang Muka Belanja', 'debet'],
            [self::HUTANG, 'Hutang Usaha', 'kredit'],
            [self::HUTANG_LISTRIK, 'Hutang Listrik', 'kredit'],
            [self::EKUITAS, 'Ekuitas Awal', 'kredit'],
            [self::BEBAN, 'Beban Listrik', 'debet'],
        ] as [$kode, $nama, $sisi]) {
            CoaDetail::create(['kode_coa' => $kode, 'nama_coa' => $nama, 'kode_grup' => self::GRP, 'jenis_saldo' => $sisi, 'status' => 'aktif']);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'status' => 'aktif']);
        CompanySettings::create([
            'nama_perusahaan' => 'Uji',
            // Tanggal jurnal pembuka diambil dari sini, dan kolomnya NOT NULL.
            'periode_awal_pembukuan' => '2026-01-01',
            'kode_unit_neraca' => 'ZZSAU',
        ]);
    }

    private function turunan(): array
    {
        return (new SaldoAwalTurunan)->baris();
    }

    /** @return array<string,array<string,mixed>> kode_coa => baris */
    private function turunanPerAkun(): array
    {
        $out = [];
        foreach ($this->turunan() as $t) {
            $out[$t['kode_coa']] = $t;
        }

        return $out;
    }

    // ── ACCRUE ───────────────────────────────────────────────────────────────

    /**
     * Accrue saldo awal: tanpa jurnal, dan hanya sisi NERACA-nya yang diturunkan.
     * Bebannya milik periode lalu dan sudah melebur ke ekuitas awal — memasukkannya
     * berarti mengakui beban tahun lalu sebagai beban tahun ini.
     */
    public function test_accrue_saldo_awal_tanpa_jurnal_dan_hanya_sisi_neraca(): void
    {
        $this->actingAs($this->admin)->post(route('accrue.store'), [
            'tanggal' => '2026-01-01',
            'kode_coa_debet' => self::BEBAN,
            'kode_coa_kredit' => self::HUTANG_LISTRIK,
            'nominal' => '750000',
            'kode_unit' => 'ZZSAU',
            'kode_bagian' => 'ZZSAB',
            'keterangan' => 'Tagihan listrik Desember belum dibayar',
            'posting_jurnal' => '0',
        ])->assertRedirect();

        $accrue = Accrue::firstOrFail();
        $this->assertTrue($accrue->saldo_awal);
        $this->assertSame(0, JournalEntry::count(), 'saldo awal tidak pernah menjurnal');

        $per = $this->turunanPerAkun();
        $this->assertArrayHasKey(self::HUTANG_LISTRIK, $per);
        $this->assertSame('kredit', $per[self::HUTANG_LISTRIK]['jenis_saldo']);
        $this->assertSame(750000.0, (float) $per[self::HUTANG_LISTRIK]['saldo']);
        $this->assertArrayNotHasKey(self::BEBAN, $per, 'beban periode lalu tak boleh masuk jurnal pembuka');
    }

    /** Dengan centang menyala, perilakunya persis seperti dulu: jurnal terbit. */
    public function test_accrue_biasa_tetap_menjurnal_dan_tak_ikut_turunan(): void
    {
        $this->actingAs($this->admin)->post(route('accrue.store'), [
            'tanggal' => '2026-01-01',
            'kode_coa_debet' => self::BEBAN,
            'kode_coa_kredit' => self::HUTANG_LISTRIK,
            'nominal' => '500000',
            'kode_unit' => 'ZZSAU',
            'kode_bagian' => 'ZZSAB',
            'keterangan' => 'Akrual bulan berjalan',
            'posting_jurnal' => '1',
        ])->assertRedirect();

        $this->assertFalse(Accrue::firstOrFail()->saldo_awal);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame([], $this->turunan());
    }

    // ── UANG MUKA OPERASIONAL ────────────────────────────────────────────────

    public function test_uang_muka_saldo_awal_tanpa_jurnal_dan_kas_tak_berkurang(): void
    {
        $this->actingAs($this->admin)->post(route('operational_advance.store'), [
            'tanggal' => '2026-01-01',
            'kode_coa_uang_muka' => self::UANG_MUKA,
            'kode_rekening' => self::KAS,
            'kode_unit' => 'ZZSAU',
            'penerima' => 'Ust. Fulan',
            'nominal' => '2000000',
            'keterangan' => 'Uang muka belanja dapur',
            'posting_jurnal' => '0',
        ])->assertRedirect();

        $adv = OperationalAdvance::firstOrFail();
        $this->assertTrue($adv->saldo_awal);
        $this->assertSame('outstanding', $adv->status, 'tetap masuk pool agar bisa dipertanggungjawabkan');
        $this->assertSame(0, JournalEntry::count());

        $per = $this->turunanPerAkun();
        $this->assertSame('debet', $per[self::UANG_MUKA]['jenis_saldo']);
        $this->assertSame(2000000.0, (float) $per[self::UANG_MUKA]['saldo']);
    }

    // ── PENGAJUAN BELUM DIBAYAR ──────────────────────────────────────────────

    public function test_hutang_belum_dibayar_lahir_siap_dicairkan_tanpa_jurnal(): void
    {
        $this->catatHutang('LAMA-001', '5000000')->assertRedirect();

        $rec = PengajuanPembayaran::firstOrFail();
        $this->assertTrue($rec->saldo_awal);
        $this->assertSame('diposting', $rec->status, 'hanya status ini yang diterima Kas Keluar');
        $this->assertNull($rec->journal_entry_id);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(1, $rec->details()->count());

        $per = $this->turunanPerAkun();
        $this->assertSame('kredit', $per[self::HUTANG]['jenis_saldo']);
        $this->assertSame(5000000.0, (float) $per[self::HUTANG]['saldo']);
        $this->assertArrayNotHasKey(self::BEBAN, $per, 'akun beban pada rinciannya tak ikut ke jurnal pembuka');
    }

    public function test_hutang_saldo_awal_boleh_dihapus_selama_belum_dicairkan(): void
    {
        $this->catatHutang('LAMA-002', '1000000');
        $rec = PengajuanPembayaran::firstOrFail();
        $this->assertSame([], PengajuanSaldoAwalService::halangan($rec));

        $this->actingAs($this->admin)->delete(route('pengajuan_saldo_awal.destroy', $rec->id))->assertRedirect();
        $this->assertSame(0, PengajuanPembayaran::count());
        $this->assertSame(0, JournalEntry::count());
    }

    /**
     * Pencairan lewat Kas Keluar tidak meninggalkan baris di dokumen ini — yang
     * bergerak hanya `sisa_hutang`. Penjaganya karena itu membandingkan nominal
     * dengan sisa, bukan menghitung baris pembayaran.
     */
    public function test_hutang_yang_sudah_dicairkan_sebagian_terkunci(): void
    {
        $this->catatHutang('LAMA-003', '4000000');
        $rec = PengajuanPembayaran::firstOrFail();
        $rec->update(['sisa_hutang' => '1000000']);

        $this->assertNotSame([], PengajuanSaldoAwalService::halangan($rec->refresh()));

        $this->actingAs($this->admin)->delete(route('pengajuan_saldo_awal.destroy', $rec->id))
            ->assertSessionHas('error');
        $this->assertSame(1, PengajuanPembayaran::count());
    }

    /** Wewenangnya milik pemindah sistem, bukan setiap pengaju pembayaran. */
    public function test_tanpa_hak_impor_data_awal_ditolak(): void
    {
        $staf = User::create(['username' => 'zzsa_staf', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);

        $this->actingAs($staf)->post(route('pengajuan_saldo_awal.store'), [
            'nomor' => 'LAMA-009', 'tanggal' => '2026-01-01', 'kode_bagian' => 'ZZSAB',
            'kode_coa_hutang' => self::HUTANG, 'kode_coa_beban' => self::BEBAN,
            'kode_unit' => 'ZZSAU', 'nominal' => '1000000', 'keterangan' => 'coba',
        ])->assertForbidden();

        $this->assertSame(0, PengajuanPembayaran::count());
    }

    // ── MENU SALDO AWAL ──────────────────────────────────────────────────────

    /**
     * Penjaga anti-hitung-dua-kali: akun yang angkanya sudah datang sendiri dari
     * dokumen tak boleh diketik lagi sebagai baris manual.
     */
    public function test_akun_yang_sudah_punya_baris_turunan_tak_boleh_diketik(): void
    {
        $this->catatHutang('LAMA-004', '3000000');

        $this->actingAs($this->admin)->post(route('opening_balance.add'), [
            'kode_coa' => self::HUTANG, 'jenis_saldo' => 'kredit', 'saldo' => '3000000',
        ])->assertSessionHas('error');

        $this->assertSame(0, OpeningBalance::count());
    }

    /** Baris turunan ikut terbit sebagai baris jurnal pembuka yang sungguhan. */
    public function test_jurnal_pembuka_memuat_baris_turunan(): void
    {
        $this->catatHutang('LAMA-005', '3000000');

        // Penyeimbangnya diketik tangan, seperti biasa.
        $this->actingAs($this->admin)->post(route('opening_balance.add'), [
            'kode_coa' => self::KAS, 'jenis_saldo' => 'debet', 'saldo' => '3000000',
        ])->assertSessionHas('status');

        $state = (new OpeningBalanceService)->state();
        $this->assertTrue($state['summary']['balanced']);
        $this->assertSame(1, $state['summary']['countManual']);
        $this->assertSame(1, $state['summary']['countTurunan']);

        $this->actingAs($this->admin)->post(route('opening_balance.post'))->assertRedirect();

        $entry = JournalEntry::firstOrFail();
        $hutang = JournalLine::where('entry_id', $entry->id)->where('kode_coa', self::HUTANG)->firstOrFail();
        $this->assertSame(3000000.0, (float) $hutang->kredit, 'hutang turunan masuk jurnal tanpa diketik siapa pun');
    }

    /**
     * Layarnya benar-benar dibuka, bukan cuma servicenya dipanggil.
     *
     * Ditulis setelah controller sempat lupa meneruskan baris turunannya ke
     * view: servicenya benar, seluruh test hijau, dan halamannya tetap mati
     * membawa "Undefined variable". Yang menghitung dan yang menampilkan adalah
     * dua hal berbeda, dan keduanya perlu dijaga.
     */
    public function test_layar_saldo_awal_menampilkan_baris_turunan(): void
    {
        $this->catatHutang('LAMA-008', '2500000');

        $this->actingAs($this->admin)->get(route('opening_balance.index'))
            ->assertOk()
            ->assertSee('Hutang Usaha')
            ->assertSee('turunan')
            ->assertSee('Pengajuan belum dibayar');
    }

    /**
     * Sesudah finalisasi, dokumen saldo awal masih boleh bertambah — dan jurnal
     * yang terbit TIDAK berubah diam-diam. Selisihnya yang ditunjukkan.
     */
    public function test_dokumen_baru_sesudah_finalisasi_memunculkan_selisih(): void
    {
        $this->catatHutang('LAMA-006', '3000000');
        $this->actingAs($this->admin)->post(route('opening_balance.add'), [
            'kode_coa' => self::KAS, 'jenis_saldo' => 'debet', 'saldo' => '3000000',
        ]);
        $this->actingAs($this->admin)->post(route('opening_balance.post'));

        $this->assertSame([], (new OpeningBalanceService)->state()['selisihTerbit']);

        // Tunggakan warisan yang baru ketemu sebulan kemudian.
        $this->catatHutang('LAMA-007', '500000');

        $selisih = (new OpeningBalanceService)->state()['selisihTerbit'];
        $this->assertCount(1, $selisih);
        $this->assertSame(self::HUTANG, $selisih[0]['kode_coa']);
        $this->assertSame(-500000.0, (float) $selisih[0]['selisih'], 'hutang bertambah 500rb → netto turun 500rb');

        // Jurnalnya sendiri tak tersentuh sampai orang memutuskan menyusun ulang.
        $this->assertSame(1, JournalEntry::where('sumber_modul', 'SaldoAwal')->count());
    }

    /**
     * Kelima layar yang disentuh pekerjaan saldo awal benar-benar terbuka.
     *
     * Murah, dan menangkap kelas galat yang paling sering lolos dari test
     * service: variabel yang lupa diteruskan controller, komponen yang salah
     * nama prop, direktif Blade yang menempel tanpa pemisah. Ketiganya tak
     * terlihat sampai halamannya dirender.
     */
    public function test_layar_yang_disentuh_saldo_awal_terbuka_semua(): void
    {
        foreach ([
            'accrue.create',
            'operational_advance.create',
            'assets.create',
            'invoices.create',
            'pengajuan_saldo_awal.index',
        ] as $rute) {
            $this->actingAs($this->admin)->get(route($rute))->assertOk("layar {$rute} gagal dirender");
        }
    }

    private function catatHutang(string $nomor, string $nominal)
    {
        return $this->actingAs($this->admin)->post(route('pengajuan_saldo_awal.store'), [
            'nomor' => $nomor,
            'tanggal' => '2026-01-01',
            'kode_bagian' => 'ZZSAB',
            'kode_coa_hutang' => self::HUTANG,
            'kode_coa_beban' => self::BEBAN,
            'kode_unit' => 'ZZSAU',
            'nominal' => $nominal,
            'keterangan' => 'Sisa termin renovasi',
        ]);
    }
}
