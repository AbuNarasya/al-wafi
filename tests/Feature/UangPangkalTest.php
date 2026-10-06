<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\PembayaranSantri;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\AngsuranUangPangkalService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MelunasiRegistrasi;
use Tests\Concerns\MembuatGelombang;
use Tests\Concerns\MembuatTarif;
use Tests\Concerns\MengaktifkanSantri;
use Tests\TestCase;

/** Uang pangkal: tagihkan (potongan), daftar ulang (akrual), angsuran, evaluasi hangus. */
class UangPangkalTest extends TestCase
{
    use MelunasiRegistrasi;
    use MembuatGelombang;
    use MembuatTarif;
    use MengaktifkanSantri;
    use RefreshDatabase;

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
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => '2026/2027']);
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

    // ---- Potongan & tagihan yang tak berlaku lagi ----

    /** Tagihan uang pangkal (4jt, potongan 1jt) untuk satu calon yang sudah diterima. */
    private function tagihanBerpotongan(array $opsiGelombang = []): TagihanSantri
    {
        $this->buatPotonganGelombang('2026/2027', '1', $this->jenjangUji(), '1000000', $opsiGelombang);
        $santri = $this->buatSantriDiterima();

        return (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000'])['uang_pangkal'];
    }

    private function lewatiTenggat(TagihanSantri $tagihan): void
    {
        PotonganUangPangkal::where('id_tagihan', $tagihan->id)->update(['tenggat' => Carbon::now()->subDays(1)->toDateString()]);
    }

    /**
     * Uang pangkal dicatat cash basis selama calon: begitu calon mundur, tak ada
     * lagi tagihan apa pun — termasuk urusan potongan. Dulu potongannya tetap
     * `berlaku`, dan evaluasi sesudah tenggat membuatnya hangus: tagihan yang
     * sudah batal hidup lagi sebesar 1 juta.
     */
    public function test_calon_mundur_membatalkan_potongan_dan_tagihan_tak_hidup_lagi(): void
    {
        $tagihan = $this->tagihanBerpotongan();

        (new SantriService)->mengundurkanDiri($tagihan->id_santri, 'pindah sekolah', $this->admin);
        $this->assertSame('batal', PotonganUangPangkal::where('id_tagihan', $tagihan->id)->value('status'));

        $this->lewatiTenggat($tagihan);
        (new AngsuranUangPangkalService)->evaluasiPotonganSemua();

        $tagihan->refresh();
        $this->assertSame('batal', $tagihan->status, 'Tagihan yang sudah batal tak boleh hidup lagi.');
        $this->assertSame(0.0, (float) $tagihan->sisa);
    }

    /** Potongan lama yang tagihannya terlanjur batal (sebelum perbaikan) ikut dibereskan oleh evaluasi. */
    public function test_evaluasi_membatalkan_potongan_milik_tagihan_yang_sudah_batal(): void
    {
        $tagihan = $this->tagihanBerpotongan();
        $tagihan->update(['status' => 'batal', 'sisa' => '0']); // tanpa lewat jalur pembatalan
        $this->lewatiTenggat($tagihan);

        $hasil = (new AngsuranUangPangkalService)->evaluasiPotongan($tagihan->id);

        $this->assertSame('batal', $hasil['status']);
        $this->assertSame('batal', $tagihan->refresh()->status);
        $this->assertSame(4000000.0, (float) $tagihan->nominal, 'Nominal tak boleh bertambah.');
    }

    // ---- Potongan & akrual ----

    /** Aktivasi memutuskan potongan DULU; hangus di sini masih cash basis dan ikut terakrualkan. */
    public function test_aktivasi_memutuskan_potongan_sebelum_mengakrualkan(): void
    {
        $tagihan = $this->tagihanBerpotongan();
        $this->lewatiTenggat($tagihan);

        Santri::where('id', $tagihan->id_santri)->update(['status' => 'lolos_kesehatan']);
        $this->aktifkanSantri($tagihan->id_santri, $this->admin);

        $this->assertSame('hangus', PotonganUangPangkal::where('id_tagihan', $tagihan->id)->value('status'));
        // Akrualnya langsung 5jt (4jt + potongan yang hangus), satu jurnal.
        $akrual = JournalEntry::with('lines')->where('sumber_modul', 'PembayaranSantri')->where('id_sumber', (string) $tagihan->id)->firstOrFail();
        $this->assertSame(5000000.0, (float) $akrual->lines->firstWhere('kode_coa', self::PIUT_UP)->debet);
        $this->assertFalse(JournalEntry::where('sumber_modul', 'KoreksiTagihan')->exists(), 'Belum akrual saat hangus — tak perlu jurnal penyesuaian.');
    }

    /**
     * Potongan yang masih belum tenggat saat aktivasi, lalu hangus SESUDAHNYA,
     * menambah piutang. Dulu tanpa jurnal: piutang buku besar tertinggal 1 juta
     * dari buku pembantu, dan saat dilunasi bisa minus.
     */
    public function test_hangus_sesudah_akrual_menerbitkan_jurnal_penyesuaian(): void
    {
        $tagihan = $this->tagihanBerpotongan();
        Santri::where('id', $tagihan->id_santri)->update(['status' => 'lolos_kesehatan']);
        $this->aktifkanSantri($tagihan->id_santri, $this->admin);
        $this->assertSame('berlaku', PotonganUangPangkal::where('id_tagihan', $tagihan->id)->value('status'));

        $this->lewatiTenggat($tagihan);
        (new AngsuranUangPangkalService)->evaluasiPotongan($tagihan->id, $this->admin);

        $this->assertSame(5000000.0, (float) $tagihan->refresh()->sisa);
        // Buku besar = buku pembantu: piutang 4jt (akrual) + 1jt (penyesuaian).
        $piutang = JournalLine::where('kode_coa', self::PIUT_UP)->selectRaw('SUM(debet) - SUM(kredit) as v')->value('v');
        $this->assertSame(5000000.0, (float) $piutang);
        $this->assertSame(1000000.0, (float) JournalLine::where('kode_coa', self::PEND_UP)
            ->whereHas('entry', fn ($q) => $q->where('sumber_modul', 'KoreksiTagihan'))->sum('kredit'));
    }

    // ---- Syarat per gelombang & evaluasi otomatis ----

    /** Syarat 50% dan jeda +30 hari dulu tertanam di kode; kini isian master Gelombang. */
    public function test_syarat_dan_jeda_diambil_dari_gelombang(): void
    {
        $tagihan = $this->tagihanBerpotongan(['syarat_persen_potongan' => 40, 'jeda_termin_hangus_hari' => 10]);

        $potongan = PotonganUangPangkal::where('id_tagihan', $tagihan->id)->firstOrFail();
        $this->assertSame(40, $potongan->syarat_persen);
        $this->assertSame(10, $potongan->jeda_hangus_hari);

        $angsuran = new AngsuranUangPangkalService;
        $angsuran->buatRencana($tagihan->id_santri, [
            'disepakati_pada' => '2026-07-01',
            'termin' => [['nominal' => '4000000', 'jatuh_tempo' => '2026-08-01']],
        ], $this->admin);
        $this->lewatiTenggat($tagihan);
        $angsuran->evaluasiPotongan($tagihan->id);

        $termin = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')
            ->firstOrFail()->termin()->orderByDesc('urutan')->firstOrFail();
        $this->assertSame('2026-08-11', Carbon::parse($termin->jatuh_tempo)->toDateString(), 'Termin terakhir + 10 hari, bukan +30.');
    }

    /** Pembayaran yang memenuhi syarat langsung mengunci potongan, tanpa menunggu tombol Evaluasi. */
    public function test_verifikasi_pembayaran_langsung_mengunci_potongan(): void
    {
        CoaDetail::create(['kode_coa' => '1.ZZUP.KAS', 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => '1.ZZUP.KAS', 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        User::where('id_pengguna', $this->admin)->update(['tim_keuangan' => true]);
        $tagihan = $this->tagihanBerpotongan();

        $bayar = PembayaranSantri::create([
            'nomor' => 'UJI-UP-1', 'id_santri' => $tagihan->id_santri, 'id_tagihan' => $tagihan->id,
            'tanggal' => Carbon::now()->toDateString(), 'nominal' => '2000000', 'kode_rekening' => '1.ZZUP.KAS',
            'status' => 'menunggu_verifikasi', 'dicatat_oleh' => $this->admin,
        ]);
        (new PembayaranSantriService)->verifikasi($bayar->id, $this->admin);

        // 2jt = 50% dari 4jt, dibayar sebelum tenggat.
        $this->assertSame('earned', PotonganUangPangkal::where('id_tagihan', $tagihan->id)->value('status'));
    }

    public function test_perintah_terjadwal_memutuskan_potongan_yang_tenggatnya_lewat(): void
    {
        $tagihan = $this->tagihanBerpotongan();
        $this->lewatiTenggat($tagihan);

        $this->artisan('potongan:evaluasi')->assertExitCode(0);

        $this->assertSame('hangus', PotonganUangPangkal::where('id_tagihan', $tagihan->id)->value('status'));
        $this->assertTrue(collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command ?? '', 'potongan:evaluasi')), 'Harus terjadwal harian.');
    }
}
