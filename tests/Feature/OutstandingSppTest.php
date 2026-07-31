<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\OutstandingSppService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\RekapPembayaranService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Daftar Outstanding SPP: tagihan SPP yang sudah terbit tetapi belum tertutup,
 * beserta koreksi nominalnya.
 *
 * Yang paling mahal bila salah adalah KOREKSInya: SPP diakrualkan sejak terbit
 * (D Piutang / K Pendapatan), jadi menimpa nominalnya tanpa jurnal penyesuaian
 * akan membuat piutang di buku besar tak lagi sama dengan jumlah sisa tagihan.
 */
class OutstandingSppTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZOS';

    private const PEND = '4.ZZOS.PEND';

    private const PIUT = '1.ZZOS.PIUT';

    private const KAS = '1.ZZOS.KAS';

    private const UNIT = 'ZZOSU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'OS']);
        foreach ([
            [self::PEND, 'Pendapatan SPP', 'kredit'], [self::PIUT, 'Piutang SPP', 'debet'], [self::KAS, 'Kas', 'debet'],
            // Akun titipan Dompet Wali dipaku di DompetPolicy, jadi kodenya harus
            // benar-benar ada sebelum top-up bisa berjurnal.
            [\App\Services\Ppsb\DompetPolicy::COA_TITIPAN['wali'], 'Titipan Dompet Wali', 'kredit'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
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
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tingkat' => 1]);

        // Tagihan registrasi yang terbit otomatis saat mendaftar sengaja
        // DIBIARKAN menggantung — auto-debet memang tak boleh menyentuhnya, dan
        // itu ikut teruji di sini tanpa perlu disiapkan khusus.
        return $santri->refresh();
    }

    /** Terbitkan SPP satu periode, kembalikan tagihan santri yang diminta. */
    private function terbitkan(string $periode = '2026-07'): void
    {
        (new SppService)->generate(['periode' => $periode, 'tanggal' => '2026-07-01'], $this->admin);
    }

    private function tagihanSpp(Santri $s): TagihanSantri
    {
        return TagihanSantri::where('id_santri', $s->id)->where('perilaku', 'spp')->orderByDesc('id')->firstOrFail();
    }

    /** Saldo berjalan satu akun dari seluruh jurnal aktif. */
    private function saldo(string $kodeCoa): string
    {
        return JournalLine::join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.status', 'aktif')->where('journal_lines.kode_coa', $kodeCoa)
            ->get(['journal_lines.debet', 'journal_lines.kredit'])
            ->reduce(fn ($t, $l) => Money::add($t, Money::sub($l->debet, $l->kredit)), '0');
    }

    /** Yang baru terbit & belum dibayar memang menggantung di sini. */
    public function test_tagihan_yang_baru_terbit_masuk_daftar(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();

        $daftar = (new OutstandingSppService)->daftar();

        $this->assertCount(1, $daftar);
        $this->assertSame($santri->id, $daftar[0]['id_santri']);
        $this->assertSame('2026-07', $daftar[0]['periode']);
        $this->assertSame(250000.0, (float) $daftar[0]['sisa']);
        $this->assertSame(0.0, (float) $daftar[0]['terbayar']);
    }

    /**
     * KASUS INTI: begitu tagihannya lunas, barisnya HILANG dari daftar — dan
     * rekap pembayaran santri ikut menunjukkan pelunasannya. Keduanya membaca
     * tabel yang sama, jadi tak ada salinan yang bisa ketinggalan zaman.
     */
    public function test_lunas_hilang_dari_daftar_dan_terbaca_di_rekap(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $t->id, 'tanggal' => '2026-07-05',
            'nominal' => '250000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');

        // Sebelum diverifikasi ia MASIH menggantung — uangnya belum diakui.
        $daftar = (new OutstandingSppService)->daftar();
        $this->assertCount(1, $daftar);
        $this->assertSame(250000.0, (float) $daftar[0]['menunggu']);

        $svc->verifikasi($p->id, $this->admin);

        $this->assertSame([], (new OutstandingSppService)->daftar());

        $rekap = (new RekapPembayaranService)->rekap($santri->id);
        $spp = collect($rekap['tagihan'])->firstWhere('tipe', 'spp');
        $this->assertSame('lunas', $spp['status']);
        $this->assertSame(250000.0, (float) $spp['terbayar']);
        $this->assertSame(0.0, (float) $spp['sisa']);
    }

    /** Koreksi NAIK: piutang & pendapatan bertambah sebesar selisihnya saja. */
    public function test_koreksi_naik_menerbitkan_jurnal_penyesuaian(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        $this->assertSame(250000.0, (float) $this->saldo(self::PIUT));

        (new OutstandingSppService)->koreksi($t->id, [
            'nominal' => '400000', 'alasan' => 'salah ketik nol',
        ], $this->admin);

        $t->refresh();
        $this->assertSame(400000.0, (float) $t->nominal);
        $this->assertSame(400000.0, (float) $t->sisa);
        $this->assertSame('belum_bayar', $t->status);

        // Selisih 150rb, bukan 400rb: yang keliru besarannya, bukan kejadiannya.
        $this->assertSame(400000.0, (float) $this->saldo(self::PIUT));
        $this->assertSame(-400000.0, (float) $this->saldo(self::PEND));
    }

    /**
     * Koreksi TURUN pada tagihan yang sudah dibayar sebagian: sisanya menyusut,
     * jurnalnya berbalik arah, dan yang sudah dibayar tidak diusik.
     */
    public function test_koreksi_turun_menyesuaikan_sisa_dan_membalik_jurnal(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $t->id, 'tanggal' => '2026-07-05',
            'nominal' => '100000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');
        $svc->verifikasi($p->id, $this->admin);

        (new OutstandingSppService)->koreksi($t->id, [
            'nominal' => '150000', 'alasan' => 'tarif jenjangnya salah',
        ], $this->admin);

        $t->refresh();
        $this->assertSame(150000.0, (float) $t->nominal);
        $this->assertSame(50000.0, (float) $t->sisa);
        $this->assertSame('sebagian', $t->status);

        // Piutang = 250rb (akrual) − 100rb (bayar) − 100rb (koreksi turun) = 50rb,
        // persis sama dengan sisa tagihannya. Itulah gunanya jurnal penyesuaian.
        $this->assertSame(50000.0, (float) $this->saldo(self::PIUT));
    }

    /** Dikoreksi menjadi NOL = santrinya dibebaskan; tagihannya lunas & keluar dari daftar. */
    public function test_koreksi_menjadi_nol_membebaskan_dan_mengeluarkan_dari_daftar(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        (new OutstandingSppService)->koreksi($t->id, [
            'nominal' => '0', 'alasan' => 'anak karyawan, seharusnya bebas',
        ], $this->admin);

        $t->refresh();
        $this->assertSame(0.0, (float) $t->nominal);
        $this->assertSame('lunas', $t->status);
        $this->assertSame([], (new OutstandingSppService)->daftar());

        // Akrualnya dibalik penuh — tak ada piutang yang tersisa menggantung.
        $this->assertSame(0.0, (float) $this->saldo(self::PIUT));
    }

    /** Tak boleh diturunkan di bawah yang sudah telanjur dibayar. */
    public function test_menolak_nominal_di_bawah_yang_sudah_dibayar(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $t->id, 'tanggal' => '2026-07-05',
            'nominal' => '200000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');
        $svc->verifikasi($p->id, $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/lebih kecil dari yang sudah dibayar/');
        (new OutstandingSppService)->koreksi($t->id, ['nominal' => '100000', 'alasan' => 'x'], $this->admin);
    }

    /** Setoran yang belum diverifikasi menahan koreksi — angkanya masih bisa berubah. */
    public function test_pembayaran_menunggu_verifikasi_menahan_koreksi(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $t->id, 'tanggal' => '2026-07-05',
            'nominal' => '50000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'kesantrian');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/menunggu verifikasi keuangan/');
        (new OutstandingSppService)->koreksi($t->id, ['nominal' => '300000', 'alasan' => 'x'], $this->admin);
    }

    /** Alur HTTP: menunya ada, halamannya tampil, dan koreksinya tersimpan. */
    public function test_alur_http_daftar_dan_koreksi(): void
    {
        $santri = $this->santriAktif('Fatimah');
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);
        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('outstanding_spp.index'))->assertOk()
            ->assertSee('Daftar Outstanding SPP')
            ->assertSee('Fatimah')
            ->assertSee('Edit tagihan');

        $this->actingAs($admin)
            ->put(route('outstanding_spp.koreksi', $t->id), ['nominal' => '275000', 'alasan' => 'penyesuaian tarif'])
            ->assertRedirect();

        $this->assertSame(275000.0, (float) $t->refresh()->nominal);

        // Sudah dikoreksi & belum dibayar → masih menggantung, dengan angka baru.
        $this->actingAs($admin)->get(route('outstanding_spp.index'))->assertOk()
            ->assertSee('275.000');
    }

    /** Isi dompet wali sebesar $nominal, lengkap dengan verifikasinya. */
    private function isiDompet(Santri $santri, string $nominal): void
    {
        $svc = new \App\Services\Modules\DompetService;
        $m = $svc->topUp([
            'id_wali' => $santri->id_wali, 'nominal' => $nominal,
            'tanggal' => '2026-07-02', 'kode_rekening' => self::KAS,
        ], $this->admin);
        $svc->verifikasiTopUp($m->id, $this->admin);
    }

    /**
     * SPP TIDAK BISA DICICIL dari dompet: saldo yang kurang dibiarkan utuh dan
     * tagihannya tetap menggantung PENUH — bukan dipotong separuh.
     */
    public function test_saldo_dompet_kurang_tidak_memotong_spp_sebagian(): void
    {
        $santri = $this->santriAktif();
        $santri->wali->update(['auto_debet' => true]);
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        $this->isiDompet($santri, '100000'); // kurang dari 250rb

        $t->refresh();
        $this->assertSame(250000.0, (float) $t->sisa, 'SPP tak boleh terpotong sebagian');
        $this->assertSame('belum_bayar', $t->status);
        $this->assertSame(100000.0, (float) $santri->wali->refresh()->dompet->saldo, 'saldo harus utuh');

        // Dan ia tetap terbaca sebagai tunggakan penuh di daftar Outstanding.
        $daftar = (new OutstandingSppService)->daftar();
        $this->assertCount(1, $daftar);
        $this->assertSame(250000.0, (float) $daftar[0]['sisa']);
    }

    /**
     * Begitu dompet diisi sampai CUKUP, tagihannya langsung terpotong penuh —
     * tanpa menunggu jadwal harian berikutnya.
     */
    public function test_dompet_diisi_sampai_cukup_langsung_melunasi_penuh(): void
    {
        $santri = $this->santriAktif();
        $santri->wali->update(['auto_debet' => true]);
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);

        $this->isiDompet($santri, '100000');
        $this->assertSame(250000.0, (float) $t->refresh()->sisa);

        // Diisi lagi 200rb → saldo 300rb, cukup untuk melunasi 250rb.
        $this->isiDompet($santri, '200000');

        $t->refresh();
        $this->assertSame(0.0, (float) $t->sisa);
        $this->assertSame('lunas', $t->status);
        $this->assertSame(50000.0, (float) $santri->wali->refresh()->dompet->saldo, 'sisa saldo = 300rb − 250rb');

        // Keluar dari daftar Outstanding dengan sendirinya.
        $this->assertSame([], (new OutstandingSppService)->daftar());
    }

    /**
     * REGISTRASI tak pernah ikut auto-debet, berapa pun saldonya.
     *
     * Biaya itu milik tahap pendaftaran: pelunasannya yang memajukan tahap calon,
     * jadi ia harus disetor di meja PPSB — bukan dipotong diam-diam oleh proses
     * latar yang tak dilihat petugasnya.
     */
    public function test_registrasi_tidak_pernah_dipotong_auto_debet(): void
    {
        $santri = $this->santriAktif();
        $santri->wali->update(['auto_debet' => true]);

        $reg = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')->firstOrFail();
        $this->assertSame('belum_bayar', $reg->status, 'prasyarat: registrasinya memang belum dibayar');

        // Saldo jauh melebihi tagihan registrasinya.
        $this->isiDompet($santri, '2000000');

        $reg->refresh();
        $this->assertSame(500000.0, (float) $reg->sisa, 'registrasi tak boleh tersentuh auto-debet');
        $this->assertSame('belum_bayar', $reg->status);
        $this->assertSame(2000000.0, (float) $santri->wali->refresh()->dompet->saldo, 'saldo harus utuh');
    }

    /** Aturan yang sama berlaku untuk pembayaran dompet MANUAL, bukan hanya auto-debet. */
    public function test_pembayaran_dompet_manual_sebagian_ditolak(): void
    {
        $santri = $this->santriAktif();
        $this->terbitkan();
        $t = $this->tagihanSpp($santri);
        $this->isiDompet($santri, '500000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak bisa dicicil dari Dompet Wali/');
        (new PembayaranSantriService)->bayarDariDompet([
            'id_tagihan' => $t->id, 'nominal' => '100000', 'tanggal' => '2026-07-05',
        ], $this->admin);
    }

    /**
     * Tagihan SELAIN SPP tetap boleh dicicil dari dompet — uang pangkal justru
     * punya modul Angsurannya sendiri. Aturan "tak bisa dicicil" hanya milik SPP.
     */
    public function test_tagihan_bukan_spp_masih_boleh_dicicil_dari_dompet(): void
    {
        $this->buatBiaya(['kode' => 'LAIN', 'nama' => 'Seragam', 'tipe' => 'lain', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);

        $santri = $this->santriAktif();
        $this->isiDompet($santri, '500000');

        (new \App\Services\Modules\TagihanLainService)->terbitkan([
            'id_santri' => [$santri->id], 'kode_jenis' => 'LAIN', 'nominal' => '500000',
            'tanggal' => '2026-07-01', 'keterangan' => 'uji cicil',
        ], $this->admin);
        $lain = TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'LAIN')->firstOrFail();

        (new PembayaranSantriService)->bayarDariDompet([
            'id_tagihan' => $lain->id, 'nominal' => '100000', 'tanggal' => '2026-07-05',
        ], $this->admin);

        $this->assertSame(400000.0, (float) $lain->refresh()->sisa);
        $this->assertSame('sebagian', $lain->status);
    }

    /**
     * Ringkasan dipecah per jenjang, bukan hanya totalnya.
     *
     * Angka total sendirian tak memberi tahu ke mana harus menagih; jenjang
     * adalah satuan kerja wali kelas. Urutannya mengikuti master (SD lalu SMP)
     * supaya warna kartunya tetap sama dari halaman ke halaman.
     */
    public function test_ringkasan_dipecah_per_jenjang(): void
    {
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        $this->buatBiaya(['kode' => 'SPP-SMP', 'nama' => 'SPP SMP', 'tipe' => 'spp', 'nominal' => '400000',
            'kode_jenjang' => 'SMP', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'berulang' => true]);

        $this->santriAktif();                 // SD — 250rb
        $this->santriAktif();                 // SD — 250rb
        $smp = $this->santriAktif();
        $smp->update(['kode_jenjang' => 'SMP']);
        $this->terbitkan();

        $svc = new OutstandingSppService;
        $ring = $svc->ringkasan($svc->daftar());

        $this->assertSame(900000.0, (float) $ring['sisa'], 'total = 250rb + 250rb + 400rb');

        $per = $ring['per_jenjang'];
        $this->assertSame(['Sekolah Dasar', 'SMP'], array_column($per, 'nama'));

        $this->assertSame(500000.0, (float) $per[0]['sisa']);
        $this->assertSame(2, $per[0]['baris']);
        $this->assertSame(2, $per[0]['santri']);

        $this->assertSame(400000.0, (float) $per[1]['sisa']);
        $this->assertSame(1, $per[1]['baris']);

        // Kartunya benar-benar sampai ke layar, dan bisa diklik untuk menyaring.
        $this->actingAs(User::find($this->admin))
            ->get(route('outstanding_spp.index'))->assertOk()
            ->assertSee('Outstanding per Jenjang')
            ->assertSee('500.000')
            ->assertSee('400.000');
    }

    /** Satu jenjang saja → rinciannya mubazir, jadi tak ditampilkan. */
    public function test_satu_jenjang_tidak_menampilkan_rincian(): void
    {
        $this->santriAktif();
        $this->terbitkan();

        $this->actingAs(User::find($this->admin))
            ->get(route('outstanding_spp.index'))->assertOk()
            ->assertDontSee('Outstanding per Jenjang');
    }

    /** Menunya berdiri di sub-grup Kontrol milik KEPENDIDIKAN. */
    public function test_menu_terdaftar_di_sub_grup_kontrol(): void
    {
        $item = collect(\App\Support\Navigation::ITEMS)
            ->firstWhere('url', '/kesantrian/outstanding-spp');

        $this->assertNotNull($item, 'menu Outstanding SPP belum terdaftar');
        $this->assertSame('KEPENDIDIKAN', $item['group']);
        $this->assertSame('Kontrol', $item['sub']);
        $this->assertContains('Kontrol', \App\Support\Navigation::SUB_ORDER['KEPENDIDIKAN']);

        // Modul hak aksesnya sendiri, bukan menumpang `spp`.
        $this->assertContains('outstanding-spp', collect(\App\Support\ModulRegistry::MODUL)->pluck('kode')->all());
    }
}
