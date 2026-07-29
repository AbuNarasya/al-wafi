<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\Karyawan;
use App\Models\Level;
use App\Models\PinjamanKaryawan;
use App\Models\User;
use App\Services\Modules\PinjamanKaryawanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pinjaman karyawan: pencairan, jadwal termin, dan DUA jalur pelunasan.
 *
 * Yang dijaga terutama jurnalnya — khususnya bahwa potong gaji TIDAK menyentuh
 * kas sama sekali, karena di situlah kekeliruan paling mudah terjadi dan paling
 * sulit terlihat di laporan.
 */
class PinjamanKaryawanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPK';
    private const PIUTANG = '1.ZZPK.1';
    private const KAS = '1.ZZPK.9';
    private const BEBAN_GAJI = '5.ZZPK.1';

    private PinjamanKaryawanService $svc;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PinjamanKaryawanService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->uid = User::create([
            'username' => 'zzpk_adm', 'nama' => 'Admin PK', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ])->id_pengguna;

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PK Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Karyawan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::BEBAN_GAJI, 'nama_coa' => 'Beban Gaji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        \App\Models\Bagian::create(['kode_bagian' => 'ZZBAG', 'nama_bagian' => 'Bagian PK', 'level' => 3]);
        // Bagian karyawan menentukan bagian mana yang memikul beban gajinya saat
        // cicilan dipotong dari gaji.
        Karyawan::create(['kode' => 'K001', 'nama' => 'Ust. Ahmad', 'kode_bagian' => 'ZZBAG', 'status' => 'aktif']);
    }

    private function buat(array $ganti = []): PinjamanKaryawan
    {
        return $this->svc->create(array_merge([
            'kode_karyawan' => 'K001', 'tanggal' => '2026-09-01', 'pokok' => '6000000',
            'kode_coa_piutang' => self::PIUTANG, 'kode_rekening' => self::KAS,
            'posting_pencairan' => true,
        ], $ganti), $this->uid);
    }

    /** @return array<string,array{d:float,k:float}> total debet/kredit per akun */
    private function mutasi(): array
    {
        $hasil = [];
        foreach (\App\Models\JournalLine::all() as $l) {
            $hasil[$l->kode_coa] ??= ['d' => 0.0, 'k' => 0.0];
            $hasil[$l->kode_coa]['d'] += (float) $l->debet;
            $hasil[$l->kode_coa]['k'] += (float) $l->kredit;
        }

        return $hasil;
    }

    public function test_pencairan_menjurnal_piutang_dan_kas(): void
    {
        $p = $this->buat();

        $this->assertSame('aktif', $p->status);
        $this->assertSame(6000000.0, (float) $p->sisa);
        $this->assertStringStartsWith('PKJ-', $p->nomor);

        $m = $this->mutasi();
        $this->assertSame(6000000.0, $m[self::PIUTANG]['d']);
        $this->assertSame(6000000.0, $m[self::KAS]['k']);
    }

    /** Saldo awal pindahan: uangnya cair bertahun lalu, tak boleh dicatat keluar hari ini. */
    public function test_tanpa_posting_pencairan_tidak_ada_jurnal(): void
    {
        $p = $this->buat(['posting_pencairan' => false]);

        $this->assertSame(0, JournalEntry::count());
        $this->assertNull($p->journal_entry_id);
        $this->assertSame(6000000.0, (float) $p->sisa);
    }

    public function test_jumlah_termin_harus_sama_dengan_pokok(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/harus sama dengan pokok/');
        $this->buat(['termin' => [
            ['nominal' => '2000000', 'jatuh_tempo' => '2026-10-01'],
            ['nominal' => '2000000', 'jatuh_tempo' => '2026-11-01'],
        ]]); // 4jt ≠ 6jt
    }

    public function test_termin_tersimpan_berurutan(): void
    {
        $p = $this->buat(['termin' => [
            ['nominal' => '3000000', 'jatuh_tempo' => '2026-10-01'],
            ['nominal' => '3000000', 'jatuh_tempo' => '2026-11-01'],
        ]]);

        $this->assertSame([1, 2], $p->termin()->orderBy('urutan')->pluck('urutan')->all());
        $this->assertSame(6000000.0, (float) $p->termin()->sum('nominal'));
    }

    public function test_cicilan_tunai_menambah_kas(): void
    {
        $p = $this->buat();
        $this->svc->bayar($p->id, ['tanggal' => '2026-10-01', 'nominal' => '1000000', 'cara' => 'tunai', 'kode_rekening' => self::KAS], $this->uid);

        $p->refresh();
        $this->assertSame(1000000.0, (float) $p->terbayar);
        $this->assertSame(5000000.0, (float) $p->sisa);

        // Pencairan 6jt keluar, cicilan 1jt masuk → kas debet 1jt, kredit 6jt.
        $m = $this->mutasi();
        $this->assertSame(1000000.0, $m[self::KAS]['d']);
        $this->assertSame(1000000.0, $m[self::PIUTANG]['k']);
    }

    /** Inti fiturnya: potong gaji tidak menyentuh kas sama sekali. */
    public function test_potong_gaji_tidak_menyentuh_kas(): void
    {
        $p = $this->buat(['posting_pencairan' => false]); // pinjaman lama, tanpa jurnal pencairan
        $this->svc->bayar($p->id, [
            'tanggal' => '2026-10-25', 'nominal' => '1000000',
            'cara' => 'potong_gaji', 'kode_coa_lawan' => self::BEBAN_GAJI,
        ], $this->uid);

        $m = $this->mutasi();
        $this->assertSame(1000000.0, $m[self::BEBAN_GAJI]['d']);
        $this->assertSame(1000000.0, $m[self::PIUTANG]['k']);
        $this->assertArrayNotHasKey(self::KAS, $m, 'potong gaji tidak boleh menghasilkan mutasi kas');

        $this->assertSame(5000000.0, (float) $p->refresh()->sisa);
    }

    /**
     * Gabungan yang sebenarnya terjadi tiap bulan: gaji bruto 10jt, potongan 1jt,
     * kas keluar 9jt. Beban gaji harus utuh 10jt, kas berkurang 9jt.
     */
    public function test_gabungan_potong_gaji_dan_gaji_netto_menghasilkan_beban_utuh(): void
    {
        $p = $this->buat(['posting_pencairan' => false]);
        $this->svc->bayar($p->id, [
            'tanggal' => '2026-10-25', 'nominal' => '1000000',
            'cara' => 'potong_gaji', 'kode_coa_lawan' => self::BEBAN_GAJI,
        ], $this->uid);

        // Pembayaran gaji NETTO — di dunia nyata lewat Kas Keluar.
        \App\Services\Ledger\PostingService::postJournal([
            'referensi' => 'GAJI-1', 'tanggal' => '2026-10-25', 'sumber_modul' => 'JurnalUmum',
            'id_sumber' => 'GAJI-1', 'id_pengguna' => $this->uid, 'keterangan' => 'Gaji Oktober (netto)',
            'lines' => [
                ['kode_coa' => self::BEBAN_GAJI, 'debet' => '9000000', 'kredit' => '0', 'kode_bagian' => 'ZZBAG'],
                ['kode_coa' => self::KAS, 'debet' => '0', 'kredit' => '9000000'],
            ],
        ]);

        $m = $this->mutasi();
        $this->assertSame(10000000.0, $m[self::BEBAN_GAJI]['d'], 'beban gaji harus utuh bruto');
        $this->assertSame(9000000.0, $m[self::KAS]['k'], 'kas berkurang sebesar netto saja');
        $this->assertSame(1000000.0, $m[self::PIUTANG]['k']);
    }

    public function test_lunas_saat_terbayar_penuh_dan_tak_bisa_dibayar_lagi(): void
    {
        $p = $this->buat(['posting_pencairan' => false]);
        $this->svc->bayar($p->id, ['tanggal' => '2026-10-01', 'nominal' => '6000000', 'cara' => 'tunai', 'kode_rekening' => self::KAS], $this->uid);

        $this->assertSame('lunas', $p->refresh()->status);
        $this->assertSame(0.0, (float) $p->sisa);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/berstatus "lunas"/');
        $this->svc->bayar($p->id, ['tanggal' => '2026-11-01', 'nominal' => '1000', 'cara' => 'tunai', 'kode_rekening' => self::KAS], $this->uid);
    }

    public function test_nominal_melebihi_sisa_ditolak(): void
    {
        $p = $this->buat(['posting_pencairan' => false]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa/');
        $this->svc->bayar($p->id, ['tanggal' => '2026-10-01', 'nominal' => '6000001', 'cara' => 'tunai', 'kode_rekening' => self::KAS], $this->uid);
    }

    public function test_potong_gaji_wajib_menyebut_akun_lawan(): void
    {
        $p = $this->buat(['posting_pencairan' => false]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/beban gaji/i');
        $this->svc->bayar($p->id, ['tanggal' => '2026-10-01', 'nominal' => '1000', 'cara' => 'potong_gaji'], $this->uid);
    }

    /** Beban gaji harus jatuh ke bagian karyawannya; tanpa itu jurnalnya ditolak. */
    public function test_potong_gaji_menandai_bagian_karyawan(): void
    {
        $p = $this->buat(['posting_pencairan' => false]);
        $this->svc->bayar($p->id, [
            'tanggal' => '2026-10-25', 'nominal' => '500000',
            'cara' => 'potong_gaji', 'kode_coa_lawan' => self::BEBAN_GAJI,
        ], $this->uid);

        $baris = \App\Models\JournalLine::where('kode_coa', self::BEBAN_GAJI)->firstOrFail();
        $this->assertSame('ZZBAG', $baris->kode_bagian);

        // Karyawan tanpa bagian → ditolak dengan pesan menuntun, bukan error SQL.
        Karyawan::create(['kode' => 'K002', 'nama' => 'Tanpa Bagian', 'status' => 'aktif']);
        $q = $this->svc->create([
            'kode_karyawan' => 'K002', 'tanggal' => '2026-09-01', 'pokok' => '1000000',
            'kode_coa_piutang' => self::PIUTANG,
        ], $this->uid);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/bagiannya wajib diketahui/');
        $this->svc->bayar($q->id, [
            'tanggal' => '2026-10-25', 'nominal' => '100000',
            'cara' => 'potong_gaji', 'kode_coa_lawan' => self::BEBAN_GAJI,
        ], $this->uid);
    }

    /** Alur lengkap lewat halaman: buat pinjaman → catat cicilan potong gaji. */
    public function test_alur_http_buat_dan_bayar(): void
    {
        $admin = User::find($this->uid);
        $this->actingAs($admin)->get('/karyawan')->assertOk()->assertSee('Ust. Ahmad');
        $this->actingAs($admin)->get('/pinjaman-karyawan/buat')->assertOk()->assertSee('Jadwal Termin');

        $this->actingAs($admin)->post('/pinjaman-karyawan', [
            'kode_karyawan' => 'K001', 'tanggal' => '2026-09-01', 'pokok' => '6000000',
            'kode_coa_piutang' => self::PIUTANG, 'kode_rekening' => self::KAS, 'posting_pencairan' => '1',
            'termin' => [
                ['nominal' => '3000000', 'jatuh_tempo' => '2026-10-01', 'keterangan' => ''],
                ['nominal' => '3000000', 'jatuh_tempo' => '2026-11-01', 'keterangan' => ''],
                ['nominal' => '', 'jatuh_tempo' => '', 'keterangan' => ''], // baris kosong diabaikan
            ],
        ])->assertRedirect();

        $p = PinjamanKaryawan::firstOrFail();
        $this->assertSame(2, $p->termin()->count());

        $this->actingAs($admin)->get("/pinjaman-karyawan/{$p->id}")->assertOk()
            ->assertSee($p->nomor)->assertSee('Potong gaji');

        $this->actingAs($admin)->post("/pinjaman-karyawan/{$p->id}/bayar", [
            'tanggal' => '2026-10-25', 'nominal' => '3000000',
            'cara' => 'potong_gaji', 'kode_coa_lawan' => self::BEBAN_GAJI,
        ])->assertRedirect();

        $this->assertSame(3000000.0, (float) $p->refresh()->sisa);
    }

    public function test_karyawan_nonaktif_tak_bisa_dipinjami(): void
    {
        Karyawan::whereKey('K001')->update(['status' => 'nonaktif']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/nonaktif/');
        $this->buat();
    }
}
