<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\Pendaftaran;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\PendaftaranLanjutanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * AKRUAL SAAT KENAIKAN JENJANG (SDTQ→SMP, SMP→SMA).
 *
 * Uang pangkal & perlengkapan berpola cash basis SAMPAI santrinya benar-benar
 * masuk jenjangnya, lalu sisanya diakrualkan D Piutang / K Pendapatan.
 *
 * Dulu hanya jalur DAFTAR ULANG yang mengakru. Jalur kenaikan jenjang
 * menerbitkan tagihannya lalu langsung menyetel status `naik` tanpa jurnal apa
 * pun — sehingga santri yang naik jenjang tak pernah memunculkan piutang, dan
 * dua santri dengan kewajiban yang sama tercatat berbeda di buku besar semata
 * karena jalan masuknya berbeda.
 */
class AkrualKenaikanJenjangTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZAK';

    private const PEND = '4.ZZAK.PEND';

    private const PIUT = '1.ZZAK.PIUT';

    private const KAS = '1.ZZAK.KAS';

    private const UNIT = 'ZZAKU';

    private const TA = '2026/2027';

    private const TA_DEPAN = '2027/2028';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15');

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'AK']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet'], [self::KAS, 'Kas', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        \App\Models\BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        // Jalur setelah naik jenjang menunjuk dirinya sendiri: reguler tetap reguler.
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        JalurPendaftaran::where('kode', 'reguler')->update(['kode_jalur_lanjutan' => 'reguler']);
        foreach ([self::TA, self::TA_DEPAN] as $kode) {
            TahunAjaran::create(['kode' => $kode, 'status' => 'aktif', 'default_pendaftaran' => $kode === self::TA]);
        }

        // Rantai jenjang SDTQ → SMP → SMA, dibuat MUNDUR: `kode_jenjang_lanjutan`
        // berkunci asing ke jenjang, jadi tujuannya harus ada lebih dulu.
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3, 'kode_jenjang_lanjutan' => 'SMA']);
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6, 'kode_jenjang_lanjutan' => 'SMP']);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true])->id_pengguna;

        foreach (['SDTQ', 'SMP', 'SMA'] as $j) {
            $this->buatBiaya(['kode' => "REG-{$j}", 'nama' => "Registrasi {$j}", 'tipe' => 'registrasi', 'kode_jenjang' => $j,
                'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
            $this->buatBiaya(['kode' => "UP-{$j}", 'nama' => "Uang Pangkal {$j}", 'tipe' => 'uang_pangkal', 'kode_jenjang' => $j,
                'nominal' => '10000000', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
                'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
            $this->buatBiaya(['kode' => "PLK-{$j}", 'nama' => "Perlengkapan {$j}", 'tipe' => 'perlengkapan', 'kode_jenjang' => $j,
                'nominal' => '2000000', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
                'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
            // Sel tarif tahun DEPAN — tujuan kenaikan jenjang. Registrasi ikut:
            // membuka siklus lanjutan menerbitkan tagihan registrasinya juga.
            $this->pasangTarif(self::TA_DEPAN, $j, null, 'registrasi', '500000');
            $this->pasangTarif(self::TA_DEPAN, $j, null, 'uang_pangkal', '10000000');
            $this->pasangTarif(self::TA_DEPAN, $j, null, 'perlengkapan', '2000000');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Santri aktif di jenjang & tingkat AKHIR, siap naik jenjang. */
    private function santriSiapNaik(string $jenjang, int $tingkatAkhir, string $jalur = 'reguler'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => $jalur, 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tingkat' => $tingkatAkhir, 'tahun_ajaran_berjalan' => self::TA]);

        return $santri->refresh();
    }

    private function saldo(string $kodeCoa): string
    {
        return JournalLine::join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.status', 'aktif')->where('journal_lines.kode_coa', $kodeCoa)
            ->get(['journal_lines.debet', 'journal_lines.kredit'])
            ->reduce(fn ($t, $l) => Money::add($t, Money::sub($l->debet, $l->kredit)), '0');
    }

    /** Jalankan satu siklus kenaikan jenjang sampai dieksekusi. */
    private function naikkan(Santri $santri, string $keJenjang): Santri
    {
        $svc = new PendaftaranLanjutanService;
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA_DEPAN], $this->admin);

        $p->update(['status' => 'lolos_kesehatan']);
        $svc->eksekusiKenaikan($p->id, ['tingkat' => 1, 'nominal_uang_pangkal' => '10000000',
            'nominal_perlengkapan' => '2000000'], $this->admin);

        // Tagihan & akrualnya sudah terbit di atas; perpindahan jenjangnya sendiri
        // menunggu T.A tujuan dimulai. Yang diuji di berkas ini adalah AKRUALNYA,
        // tetapi jenjang santri ikut diperiksa — jadi jadwalnya dinyalakan di sini.
        Carbon::setTestNow(Carbon::parse(TahunAjaran::where('kode', self::TA_DEPAN)->value('tanggal_mulai')));
        (new \App\Services\Modules\KenaikanTingkatService)->terapkanYangJatuhTempo();
        Carbon::setTestNow('2026-09-15');

        return $santri->refresh();
    }

    /**
     * @return array{0:string,1:string} [sisa uang pangkal, sisa perlengkapan] jenjang tujuan
     */
    private function tagihanJenjang(Santri $s, string $jenjang): array
    {
        $up = TagihanSantri::where('id_santri', $s->id)->where('perilaku', 'uang_pangkal')
            ->where('kode_jenjang', $jenjang)->sole();
        $plk = TagihanSantri::where('id_santri', $s->id)->where('perilaku', 'perlengkapan')
            ->where('kode_jenjang', $jenjang)->sole();

        $this->assertTrue((bool) $up->sudah_akrual, "uang pangkal {$jenjang} harus diakrualkan");
        $this->assertTrue((bool) $plk->sudah_akrual, "perlengkapan {$jenjang} harus diakrualkan");

        return [$up->sisa, $plk->sisa];
    }

    /** SDTQ → SMP: piutangnya diakui saat kenaikan dieksekusi. */
    public function test_sdtq_ke_smp_mengakui_piutang(): void
    {
        $santri = $this->santriSiapNaik('SDTQ', 6);
        $this->assertSame(0.0, (float) $this->saldo(self::PIUT));

        $this->naikkan($santri, 'SMP');

        $this->tagihanJenjang($santri, 'SMP');
        // Uang pangkal 10jt + perlengkapan 2jt.
        $this->assertSame(12000000.0, (float) $this->saldo(self::PIUT));
        $this->assertSame(-12000000.0, (float) $this->saldo(self::PEND));
    }

    /** SMP → SMA: perlakuan yang sama persis. */
    public function test_smp_ke_sma_mengakui_piutang(): void
    {
        $santri = $this->santriSiapNaik('SMP', 3);

        $this->naikkan($santri, 'SMA');

        $this->tagihanJenjang($santri, 'SMA');
        $this->assertSame(12000000.0, (float) $this->saldo(self::PIUT));
        $this->assertSame('SMA', $santri->refresh()->kode_jenjang);
    }

    /**
     * Piutang di buku besar HARUS sama dengan sisa tagihan di subledger — itulah
     * gunanya akrual ini, dan itu pula yang dulu tak pernah cocok.
     */
    public function test_piutang_buku_besar_sama_dengan_sisa_subledger(): void
    {
        $santri = $this->santriSiapNaik('SMP', 3);
        $this->naikkan($santri, 'SMA');

        $sisaSubledger = TagihanSantri::where('id_santri', $santri->id)
            ->whereIn('perilaku', ['uang_pangkal', 'perlengkapan'])
            ->where('status', '!=', 'batal')
            ->get()->reduce(fn ($t, $x) => Money::add($t, $x->sisa), '0');

        $this->assertSame((float) $sisaSubledger, (float) $this->saldo(self::PIUT));
    }

    /** Jalur BEBAS uang pangkal: hanya perlengkapan yang diakru, tanpa galat. */
    public function test_jalur_bebas_uang_pangkal_hanya_mengakru_perlengkapan(): void
    {
        JalurPendaftaran::create(['kode' => 'karyawan', 'nama' => 'Anak Karyawan']);
        JalurPendaftaran::where('kode', 'karyawan')->update(['kode_jalur_lanjutan' => 'karyawan']);
        $this->pasangTarif(self::TA_DEPAN, 'SMA', 'karyawan', 'uang_pangkal', null, bebas: true);
        // Jalur ini lahir setelah tarif jenjang dipasang, jadi selnya sendiri
        // harus diisi: biaya masuk tak lagi punya baris cadangan "semua jalur".
        // Termasuk jenjang & T.A ASAL — registrasinya terbit saat santri dibuat.
        foreach ([[self::TA, 'SMP'], [self::TA_DEPAN, 'SMA']] as [$ta, $jenjang]) {
            $this->pasangTarif($ta, $jenjang, 'karyawan', 'registrasi', '500000');
            $this->pasangTarif($ta, $jenjang, 'karyawan', 'perlengkapan', '2000000');
        }

        // Jalur tujuan DITURUNKAN dari jalur santrinya (`kode_jalur_lanjutan`),
        // bukan dari apa yang dikirim ke buat() — jadi santrinya sendiri yang
        // harus berjalur karyawan.
        $santri = $this->santriSiapNaik('SMP', 3, 'karyawan');

        $svc = new PendaftaranLanjutanService;
        $p = $svc->buat($santri->id, ['tahun_ajaran' => self::TA_DEPAN], $this->admin);
        $p->update(['status' => 'lolos_kesehatan']);
        $svc->eksekusiKenaikan($p->id, ['tingkat' => 1, 'nominal_perlengkapan' => '2000000'], $this->admin);

        $this->assertSame(0, TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'uang_pangkal')->where('kode_jenjang', 'SMA')->count());

        $plk = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'perlengkapan')
            ->where('kode_jenjang', 'SMA')->sole();
        $this->assertTrue((bool) $plk->sudah_akrual);
        $this->assertSame(2000000.0, (float) $this->saldo(self::PIUT));
    }

    /** Perintah susulan membereskan baris lama, dan aman dijalankan ulang. */
    public function test_perintah_susulan_mengakrualkan_yang_tertinggal(): void
    {
        $santri = $this->santriSiapNaik('SMP', 3);
        $this->naikkan($santri, 'SMA');

        // Kembalikan ke keadaan LAMA: tagihan ada, jurnalnya tak pernah terbit.
        TagihanSantri::where('id_santri', $santri->id)->where('kode_jenjang', 'SMA')
            ->update(['sudah_akrual' => false]);
        \App\Models\JournalLine::query()->delete();
        \App\Models\JournalEntry::query()->delete();
        $this->assertSame(0.0, (float) $this->saldo(self::PIUT));

        $this->artisan('tagihan:akrualkan-tertinggal --terapkan')->assertSuccessful();

        $this->assertSame(12000000.0, (float) $this->saldo(self::PIUT));

        // Dijalankan ulang tidak menjurnal dua kali.
        $this->artisan('tagihan:akrualkan-tertinggal --terapkan')->assertSuccessful();
        $this->assertSame(12000000.0, (float) $this->saldo(self::PIUT));
    }

    /** Tanpa --terapkan, tak ada jurnal yang terbit. */
    public function test_perintah_tanpa_terapkan_hanya_menampilkan_rencana(): void
    {
        $santri = $this->santriSiapNaik('SMP', 3);
        $this->naikkan($santri, 'SMA');
        TagihanSantri::where('id_santri', $santri->id)->where('kode_jenjang', 'SMA')
            ->update(['sudah_akrual' => false]);
        \App\Models\JournalLine::query()->delete();
        \App\Models\JournalEntry::query()->delete();

        $this->artisan('tagihan:akrualkan-tertinggal')->assertSuccessful();

        $this->assertSame(0.0, (float) $this->saldo(self::PIUT));
        $this->assertFalse((bool) TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'perlengkapan')->where('kode_jenjang', 'SMA')->sole()->sudah_akrual);
    }
}
