<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\AngsuranUangPangkalService;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pengunduran diri SANTRI AKTIF: sisa uang pangkal dihapuskan + akrual dibalik sebesar sisa. */
class SantriAktifMundurTest extends TestCase
{
    use \Tests\Concerns\MengaktifkanSantri;
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZMD';
    private const KAS = '1.ZZMD.KAS';
    private const PEND_REG = '4.ZZMD.REG';
    private const PEND_UP = '4.ZZMD.UP';
    private const PIUT_UP = '1.ZZMD.PIUT';
    private const UNIT = 'ZZMDU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'MD']);
        foreach ([
            [self::KAS, 'Kas', 'debet'], [self::PEND_REG, 'Pend Reg', 'kredit'],
            [self::PEND_UP, 'Pend UP', 'kredit'], [self::PIUT_UP, 'Piutang UP', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND_REG, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_coa_pendapatan' => self::PEND_UP, 'kode_coa_piutang' => self::PIUT_UP, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    /** Santri aktif (sudah daftar ulang) dengan uang pangkal 20 juta; $bayar dibayar SEBELUM daftar ulang. */
    private function santriAktif(string $bayarSebelum = '0'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'gelombang' => 1]);
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);
        $svc->tagihkanUangPangkal($santri->id, ['nominal' => '20000000']);
        $svc->medcheck($santri->id, ['lolos' => true]);

        if (Money::gtZero(Money::of($bayarSebelum))) {
            $this->bayarUp($santri, $bayarSebelum);
        }
        $this->aktifkanSantri($santri->id, $this->admin);

        return $santri->refresh();
    }

    private function tagihanUp(Santri $santri): TagihanSantri
    {
        return TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'UP')->firstOrFail();
    }

    private function bayarUp(Santri $santri, string $nominal): void
    {
        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $this->tagihanUp($santri)->id,
            'tanggal' => now()->toDateString(), 'nominal' => $nominal, 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');
        $svc->verifikasi($p->id, $this->admin);
    }

    /** Saldo berjalan satu akun dari seluruh jurnal aktif. */
    private function saldo(string $kodeCoa): string
    {
        $rows = JournalLine::join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.status', 'aktif')->where('journal_lines.kode_coa', $kodeCoa)
            ->get(['journal_lines.debet', 'journal_lines.kredit']);

        return $rows->reduce(fn ($t, $l) => Money::add($t, Money::sub($l->debet, $l->kredit)), '0');
    }

    public function test_mundur_menghapus_sisa_dan_membalik_akrual_sebesar_sisa(): void
    {
        $santri = $this->santriAktif();
        $this->assertSame(20000000.0, (float) $this->saldo(self::PIUT_UP), 'akrual daftar ulang membentuk piutang 20jt');

        // Bayar 5 juta SETELAH daftar ulang → piutang tinggal 15 juta.
        $this->bayarUp($santri, '5000000');
        $this->assertSame(15000000.0, (float) $this->saldo(self::PIUT_UP));

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah domisili', $this->admin);

        $this->assertSame('keluar', $santri->refresh()->status);
        $tagihan = $this->tagihanUp($santri);
        $this->assertSame('batal', $tagihan->status);
        $this->assertSame(0.0, (float) $tagihan->sisa);

        // Piutang nol, dan pendapatan tinggal sebesar yang benar-benar diterima (5 juta).
        $this->assertSame(0.0, (float) $this->saldo(self::PIUT_UP), 'piutang habis, tidak minus');
        $this->assertSame(-5000000.0, (float) $this->saldo(self::PEND_UP), 'pendapatan tersisa 5jt (kredit)');

        $pembalik = JournalEntry::where('keterangan', 'like', 'Pembatalan sisa uang pangkal%')->first();
        $this->assertNotNull($pembalik);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'santri_aktif_mengundurkan_diri']);
    }

    public function test_tanpa_pembayaran_setelah_akrual_pembalik_sama_dengan_akrual_penuh(): void
    {
        $santri = $this->santriAktif();

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);

        $this->assertSame(0.0, (float) $this->saldo(self::PIUT_UP));
        $this->assertSame(0.0, (float) $this->saldo(self::PEND_UP), 'pendapatan uang pangkal kembali nol');
    }

    public function test_lunas_sebelum_daftar_ulang_tidak_menerbitkan_jurnal_pembalik(): void
    {
        $santri = $this->santriAktif('20000000'); // lunas sebelum daftar ulang → tak ada akrual
        $this->assertSame(0.0, (float) $this->saldo(self::PIUT_UP));

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);

        $this->assertSame('keluar', $santri->refresh()->status);
        $this->assertNull(JournalEntry::where('keterangan', 'like', 'Pembatalan sisa uang pangkal%')->first());
        $this->assertSame(-20000000.0, (float) $this->saldo(self::PEND_UP), 'pendapatan yang sudah diterima tidak diusik');
    }

    public function test_tolak_bila_ada_pembayaran_menunggu_verifikasi(): void
    {
        $santri = $this->santriAktif();
        (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $this->tagihanUp($santri)->id,
            'tanggal' => now()->toDateString(), 'nominal' => '1000000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/menunggu verifikasi/i');
        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);
    }

    public function test_rencana_angsuran_aktif_dinonaktifkan(): void
    {
        $santri = $this->santriAktif();
        (new AngsuranUangPangkalService)->buatRencana($santri->id, [
            'disepakati_pada' => now()->toDateString(),
            'termin' => [
                ['urutan' => 1, 'nominal' => '10000000', 'jatuh_tempo' => now()->addMonth()->toDateString()],
                ['urutan' => 2, 'nominal' => '10000000', 'jatuh_tempo' => now()->addMonths(2)->toDateString()],
            ],
        ], $this->admin);

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah domisili', $this->admin);

        $this->assertFalse(RencanaAngsuranUangPangkal::where('id_tagihan', $this->tagihanUp($santri)->id)->where('status', 'aktif')->exists());
    }

    public function test_calon_belum_aktif_tetap_memakai_alur_lama(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08123123']);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Calon', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);

        (new SantriService)->mengundurkanDiri($santri->id, 'batal daftar', $this->admin);

        $this->assertSame('mengundurkan_diri', $santri->refresh()->status);
        $this->assertNull(JournalEntry::where('keterangan', 'like', 'Pembatalan sisa uang pangkal%')->first());
    }

    public function test_santri_sudah_keluar_tidak_bisa_mundur_lagi(): void
    {
        $santri = $this->santriAktif();
        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah berakhir/i');
        (new SantriService)->mengundurkanDiri($santri->id, 'lagi', $this->admin);
    }
}
