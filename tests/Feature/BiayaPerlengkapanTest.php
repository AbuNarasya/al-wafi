<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\PotonganGelombang;
use App\Models\PotonganUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Services\Modules\AngsuranUangPangkalService;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\PpsbDashboardService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BIAYA PERLENGKAPAN — terbit bersama uang pangkal, tetapi berdiri sendiri.
 *
 * Yang dijaga test ini bukan sekadar "tagihannya terbit", melainkan tiga hal
 * yang mudah rusak diam-diam bila keduanya dilebur:
 *   1. potongan gelombang TIDAK menyentuh perlengkapan — termasuk ambang 50%
 *      yang menentukan potongan itu terkunci atau hangus;
 *   2. pendapatannya masuk akun sendiri, lewat jurnal akrual sendiri;
 *   3. jadwal terminnya terpisah dan tak saling menimpa.
 */
class BiayaPerlengkapanTest extends TestCase
{
    use \Tests\Concerns\MengaktifkanSantri;
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZPL';

    private const PEND_REG = '4.ZZPL.REG';

    private const PEND_UP = '4.ZZPL.UP';

    private const PIUT_UP = '1.ZZPL.UP';

    private const PEND_PL = '4.ZZPL.PL';

    private const PIUT_PL = '1.ZZPL.PL';

    private const KAS = '1.ZZPL.KAS';

    private const UNIT = 'ZZUNIT';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Perlengkapan Uji']);
        foreach ([
            [self::PEND_REG, 'Pend Reg', 'kredit'], [self::PEND_UP, 'Pend UP', 'kredit'],
            [self::PIUT_UP, 'Piutang UP', 'debet'], [self::PEND_PL, 'Pend Perlengkapan', 'kredit'],
            [self::PIUT_PL, 'Piutang Perlengkapan', 'debet'], [self::KAS, 'Kas', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        \App\Models\BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        \App\Models\Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        \App\Models\TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        \App\Models\JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND_REG, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_coa_pendapatan' => self::PEND_UP, 'kode_coa_piutang' => self::PIUT_UP, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    /** Master perlengkapan dibuat terpisah: sebagian test menguji ketiadaannya. */
    private function masterPerlengkapan(string $nominal = '2000000'): void
    {
        $this->buatBiaya([
            'kode' => 'PLK', 'nama' => 'Biaya Perlengkapan', 'tipe' => 'perlengkapan', 'nominal' => $nominal,
            'kode_coa_pendapatan' => self::PEND_PL, 'kode_coa_piutang' => self::PIUT_PL,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function buatSantriDiterima(): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812'.rand(100000, 999999)]);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'gelombang' => 1, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);
        $santri->update(['status' => 'diterima']);

        return $santri->refresh();
    }

    private function bayar(int $idSantri, int $idTagihan, string $nominal): void
    {
        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $idSantri, 'id_tagihan' => $idTagihan, 'tanggal' => now()->toDateString(),
            'nominal' => $nominal, 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');
        $svc->verifikasi($p->id, $this->admin);
    }

    public function test_terbit_dua_tagihan_dan_potongan_hanya_memotong_uang_pangkal(): void
    {
        PotonganGelombang::create(['tahun_ajaran' => self::TA, 'gelombang' => 1, 'potongan' => '1000000', 'masa_berlaku_hari' => 7, 'aktif' => true]);
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();

        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        // Uang pangkal terpotong; perlengkapan UTUH.
        $this->assertSame(4000000.0, (float) $hasil['uang_pangkal']->nominal);
        $this->assertSame(2000000.0, (float) $hasil['perlengkapan']->nominal);
        $this->assertSame('PLK', $hasil['perlengkapan']->kode_jenis);

        // Baris potongan hanya melekat pada tagihan uang pangkal.
        $this->assertSame(1, PotonganUangPangkal::count());
        $this->assertSame($hasil['uang_pangkal']->id, PotonganUangPangkal::first()->id_tagihan);

        // Penagihan belum berjurnal sama sekali (tetap seperti sebelumnya).
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_ambang_50_persen_tetap_dihitung_dari_uang_pangkal_saja(): void
    {
        PotonganGelombang::create(['tahun_ajaran' => self::TA, 'gelombang' => 1, 'potongan' => '1000000', 'masa_berlaku_hari' => 7, 'aktif' => true]);
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        // Uang pangkal efektif 4jt → ambangnya 2jt. Kalau perlengkapan ikut
        // dilebur, ambangnya jadi 3jt dan pembayaran ini tak akan cukup.
        $angsuran = new AngsuranUangPangkalService;
        $ambang = $angsuran->daftarPenjadwalan()[0]['ambang_potongan'];
        $this->assertSame(2000000.0, $ambang);

        // Menyetor PERSIS angka yang ditawarkan form harus benar-benar mengunci
        // potongannya — inilah yang mengikat tawaran itu pada evaluasiPotongan().
        $this->bayar($santri->id, $hasil['uang_pangkal']->id, (string) $ambang);

        $this->assertSame('earned', $angsuran->evaluasiPotongan($hasil['uang_pangkal']->id)['status']);
    }

    public function test_perlengkapan_boleh_dikosongkan(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();

        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000']);

        $this->assertNull($hasil['perlengkapan']);
        $this->assertSame(0, TagihanSantri::whereIn('kode_jenis', ['PLK'])->count());
    }

    public function test_nominal_perlengkapan_tanpa_master_ditolak_dengan_pesan_menuntun(): void
    {
        $santri = $this->buatSantriDiterima(); // master perlengkapan sengaja tidak dibuat

        try {
            (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000', 'nominal_perlengkapan' => '2000000']);
            $this->fail('Seharusnya ditolak karena master perlengkapan belum ada.');
        } catch (AppException $e) {
            $this->assertStringContainsString('Perlengkapan', $e->getMessage());
        }

        // Ditolak sebelum apa pun tertulis — uang pangkalnya pun tidak terbit.
        $this->assertSame(0, TagihanSantri::where('kode_jenis', 'UP')->count());
    }

    public function test_daftar_ulang_menerbitkan_dua_jurnal_akrual_berpasangan_sendiri(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        $santri->update(['status' => 'lolos_kesehatan']);
        $this->aktifkanSantri($santri->id, $this->admin);

        $this->assertTrue((bool) $hasil['uang_pangkal']->refresh()->sudah_akrual);
        $this->assertTrue((bool) $hasil['perlengkapan']->refresh()->sudah_akrual);

        // Dua jurnal terpisah, masing-masing memakai pasangan akunnya sendiri —
        // bukan satu jurnal berisi empat baris dengan satu unit bisnis.
        $up = JournalEntry::with('lines')->where('id_sumber', (string) $hasil['uang_pangkal']->id)->firstOrFail();
        $pl = JournalEntry::with('lines')->where('id_sumber', (string) $hasil['perlengkapan']->id)->firstOrFail();
        $this->assertNotSame($up->id, $pl->id);
        $this->assertSame(5000000.0, (float) $up->lines->firstWhere('kode_coa', self::PIUT_UP)->debet);
        $this->assertSame(5000000.0, (float) $up->lines->firstWhere('kode_coa', self::PEND_UP)->kredit);
        $this->assertSame(2000000.0, (float) $pl->lines->firstWhere('kode_coa', self::PIUT_PL)->debet);
        $this->assertSame(2000000.0, (float) $pl->lines->firstWhere('kode_coa', self::PEND_PL)->kredit);
    }

    public function test_mengundurkan_diri_membalik_sisa_keduanya(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);
        $santri->update(['status' => 'lolos_kesehatan']);
        $this->aktifkanSantri($santri->id, $this->admin);

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah domisili', $this->admin);

        $this->assertSame('keluar', $santri->refresh()->status);
        foreach (['uang_pangkal', 'perlengkapan'] as $k) {
            $this->assertSame('batal', $hasil[$k]->refresh()->status);
            $this->assertSame(0.0, (float) $hasil[$k]->sisa);
        }
        // Piutang keduanya kembali nol.
        $this->assertSame(0.0, $this->saldo(self::PIUT_UP));
        $this->assertSame(0.0, $this->saldo(self::PIUT_PL));
    }

    public function test_dua_jadwal_termin_berdiri_sendiri(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        $angsuran = new AngsuranUangPangkalService;
        $angsuran->buatRencana($santri->id, [
            'disepakati_pada' => '2026-07-01',
            'termin' => [
                ['nominal' => '3000000', 'jatuh_tempo' => '2026-08-01'],
                ['nominal' => '2000000', 'jatuh_tempo' => '2026-09-01'],
            ],
        ], $this->admin);
        // Perlengkapan menyusul SETELAH termin uang pangkal terakhir.
        $angsuran->buatRencana($santri->id, [
            'komponen' => 'perlengkapan',
            'disepakati_pada' => '2026-07-01',
            'termin' => [
                ['nominal' => '1000000', 'jatuh_tempo' => '2026-10-01'],
                ['nominal' => '1000000', 'jatuh_tempo' => '2026-11-01'],
            ],
        ], $this->admin);

        // Masing-masing melekat pada tagihannya sendiri, bukan saling menimpa.
        $this->assertSame(2, \App\Models\RencanaAngsuranUangPangkal::where('status', 'aktif')->count());

        // Tanpa potongan gelombang, form tak menawarkan apa pun untuk termin pertama.
        $tanpaPotongan = (new AngsuranUangPangkalService)->daftarPenjadwalan();
        $this->assertSame([], $tanpaPotongan, 'keduanya sudah terjadwal, hilang dari daftar');

        // DAFTAR: satu baris per santri, angkanya gabungan kedua komponen.
        $daftar = $angsuran->list();
        $this->assertCount(1, $daftar, 'dua rencana satu santri tampil sebagai satu baris');
        $this->assertSame(7000000.0, (float) $daftar[0]['total'], '5jt uang pangkal + 2jt perlengkapan');
        $this->assertSame(0.0, (float) $daftar[0]['terbayar']);
        $this->assertSame(7000000.0, (float) $daftar[0]['sisa']);
        $this->assertSame('belum_bayar', $daftar[0]['status_tagihan']);
        $this->assertSame(4, $daftar[0]['jumlah_termin'], '2 termin uang pangkal + 2 termin perlengkapan');
        $this->assertSame('Uang Pangkal + Biaya Perlengkapan', $daftar[0]['label_komponen']);
        // Rinciannya tetap terbawa, dan termin berikutnya diambil yang paling dekat.
        $this->assertSame(5000000.0, (float) $daftar[0]['komponen']['uang_pangkal']['total']);
        $this->assertSame(2000000.0, (float) $daftar[0]['komponen']['perlengkapan']['total']);
        $this->assertSame('uang_pangkal', $daftar[0]['termin_berikut']['komponen']);
        $this->assertSame('2026-08-01', Carbon::parse($daftar[0]['termin_berikut']['jatuh_tempo'])->toDateString());

        // Setelah uang pangkal lunas, termin berikutnya berpindah ke perlengkapan.
        $this->bayar($santri->id, $hasil['uang_pangkal']->id, '5000000');
        $lanjut = $angsuran->list();
        $this->assertSame('perlengkapan', $lanjut[0]['termin_berikut']['komponen']);
        $this->assertSame('sebagian', $lanjut[0]['status_tagihan'], 'status gabungan, bukan salinan salah satu tagihan');
        $this->assertSame(5000000.0, (float) $lanjut[0]['terbayar']);

        $detail = $angsuran->detailSantri($santri->id);
        $this->assertSame(5000000.0, (float) $detail['komponen']['uang_pangkal']['total']);
        $this->assertSame(2000000.0, (float) $detail['komponen']['perlengkapan']['total']);
        $this->assertCount(2, $detail['komponen']['perlengkapan']['rencana_aktif']['termin']);
        $this->assertNull($detail['komponen']['perlengkapan']['potongan'], 'perlengkapan tak pernah punya baris potongan');

        // Jumlah termin tetap wajib sama dengan tagihannya masing-masing.
        try {
            $angsuran->buatRencana($santri->id, [
                'komponen' => 'perlengkapan', 'disepakati_pada' => '2026-07-01',
                'termin' => [['nominal' => '5000000', 'jatuh_tempo' => '2026-10-01']],
            ], $this->admin);
            $this->fail('Termin yang tak sama dengan tagihan seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('harus sama dengan total tagihannya', $e->getMessage());
        }
    }

    /** Uang pangkal harus selesai lebih dulu — dari sisi mana pun jadwal itu disimpan. */
    public function test_termin_perlengkapan_tak_boleh_mendahului_uang_pangkal(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        $angsuran = new AngsuranUangPangkalService;
        $angsuran->buatRencana($santri->id, [
            'disepakati_pada' => '2026-07-01',
            'termin' => [
                ['nominal' => '3000000', 'jatuh_tempo' => '2026-08-01'],
                ['nominal' => '2000000', 'jatuh_tempo' => '2026-09-01'],
            ],
        ], $this->admin);

        // (a) perlengkapan yang mulai sebelum uang pangkal selesai → ditolak.
        try {
            $angsuran->buatRencana($santri->id, [
                'komponen' => 'perlengkapan', 'disepakati_pada' => '2026-07-01',
                'termin' => [['nominal' => '2000000', 'jatuh_tempo' => '2026-08-15']],
            ], $this->admin);
            $this->fail('Perlengkapan yang mendahului uang pangkal seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('selesai lebih dulu', $e->getMessage());
        }

        // Jatuh tempo yang sama persis pun belum "lebih dulu".
        try {
            $angsuran->buatRencana($santri->id, [
                'komponen' => 'perlengkapan', 'disepakati_pada' => '2026-07-01',
                'termin' => [['nominal' => '2000000', 'jatuh_tempo' => '2026-09-01']],
            ], $this->admin);
            $this->fail('Tanggal yang sama seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('selesai lebih dulu', $e->getMessage());
        }

        $angsuran->buatRencana($santri->id, [
            'komponen' => 'perlengkapan', 'disepakati_pada' => '2026-07-01',
            'termin' => [['nominal' => '2000000', 'jatuh_tempo' => '2026-10-01']],
        ], $this->admin);

        // (b) sisi sebaliknya: uang pangkal di-renegosiasi melewati perlengkapan.
        try {
            $angsuran->renegosiasi($santri->id, [
                'disepakati_pada' => '2026-07-02', 'alasan' => 'diperpanjang',
                'termin' => [['nominal' => '5000000', 'jatuh_tempo' => '2026-12-01']],
            ], $this->admin);
            $this->fail('Uang pangkal yang melewati perlengkapan seharusnya ditolak.');
        } catch (AppException $e) {
            $this->assertStringContainsString('selesai lebih dulu', $e->getMessage());
        }
    }

    /**
     * Satu kiriman form menjadwalkan keduanya sekaligus, dan jatuh tempo termin
     * pertama uang pangkal ditawarkan mengikuti tenggat potongan gelombang.
     */
    public function test_satu_form_menjadwalkan_kedua_komponen(): void
    {
        PotonganGelombang::create(['tahun_ajaran' => self::TA, 'gelombang' => 1, 'potongan' => '1000000', 'masa_berlaku_hari' => 7, 'aktif' => true]);
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);
        $admin = User::find($this->admin);
        $tenggat = Carbon::now()->startOfDay()->addDays(7)->toDateString();

        // Dropdown memuat SATU baris untuk santri ini, lengkap dengan keadaan
        // kedua komponennya + tenggat potongan yang mengisi termin pertama.
        $daftar = (new AngsuranUangPangkalService)->daftarPenjadwalan();
        $this->assertCount(1, $daftar);
        $this->assertSame($tenggat, $daftar[0]['tenggat_potongan']);
        $this->assertSame(4000000.0, $daftar[0]['komponen']['uang_pangkal']['total']); // sudah dipotong
        // Nominal termin pertama yang ditawarkan = ambang syarat potongan, dihitung
        // dengan rumus yang sama persis dengan evaluasiPotongan(): 50% × 4jt.
        $this->assertSame(50, $daftar[0]['syarat_persen']);
        $this->assertSame(2000000.0, $daftar[0]['ambang_potongan']);
        // Bahan pencarian & angka outstanding di pemilih santri.
        $this->assertNotNull($daftar[0]['no_pendaftaran']);
        $this->assertArrayHasKey('nis', $daftar[0]);
        $this->assertSame(4000000.0, $daftar[0]['komponen']['uang_pangkal']['sisa']);

        $this->actingAs($admin)->get(route('angsuran_uang_pangkal.create'))->assertOk()
            ->assertSee('Ketik nomor pendaftaran, NIS, atau nama…', false)
            ->assertSee('Total yang dijadwalkan');
        $this->assertSame(2000000.0, $daftar[0]['komponen']['perlengkapan']['total']);
        $this->assertFalse($daftar[0]['komponen']['uang_pangkal']['punya_rencana']);

        $this->actingAs($admin)->post(route('angsuran_uang_pangkal.store'), [
            'id_santri' => $santri->id,
            'disepakati_pada' => '2026-07-29',
            'termin_uang_pangkal' => [
                ['nominal' => '2000000', 'jatuh_tempo' => $tenggat],
                ['nominal' => '2000000', 'jatuh_tempo' => '2026-09-01'],
            ],
            'termin_perlengkapan' => [
                ['nominal' => '2000000', 'jatuh_tempo' => '2026-10-01'],
            ],
        ])->assertRedirect(route('angsuran_uang_pangkal.index'))->assertSessionHas('status');

        $this->assertSame(2, \App\Models\RencanaAngsuranUangPangkal::where('status', 'aktif')->count());

        // Santri yang sudah terjadwal keduanya tak muncul lagi di dropdown.
        $this->assertSame([], (new AngsuranUangPangkalService)->daftarPenjadwalan());
    }

    /** Kiriman yang melanggar urutan tidak menyimpan apa pun — termasuk uang pangkalnya. */
    public function test_kiriman_gabungan_yang_melanggar_urutan_batal_seluruhnya(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        $this->actingAs(User::find($this->admin))->post(route('angsuran_uang_pangkal.store'), [
            'id_santri' => $santri->id,
            'disepakati_pada' => '2026-07-29',
            'termin_uang_pangkal' => [['nominal' => '5000000', 'jatuh_tempo' => '2026-09-01']],
            'termin_perlengkapan' => [['nominal' => '2000000', 'jatuh_tempo' => '2026-08-01']],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, \App\Models\RencanaAngsuranUangPangkal::count(), 'uang pangkal ikut batal, tak tersimpan separuh');
    }

    public function test_pembayaran_perlengkapan_urusan_modul_ppsb_bukan_kesantrian(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        $svc = new PembayaranSantriService;
        try {
            $svc->catat([
                'id_santri' => $santri->id, 'id_tagihan' => $hasil['perlengkapan']->id,
                'tanggal' => now()->toDateString(), 'nominal' => '500000', 'kode_rekening' => self::KAS,
            ], $this->admin, 'kesantrian');
            $this->fail('Modul Kesantrian seharusnya menolak tagihan perlengkapan.');
        } catch (AppException $e) {
            $this->assertStringContainsString('PPSB', $e->getMessage());
        }

        // Lewat PPSB diterima, dan pembayarannya mengurangi sisa perlengkapan saja.
        $this->bayar($santri->id, $hasil['perlengkapan']->id, '500000');
        $this->assertSame(1500000.0, (float) $hasil['perlengkapan']->refresh()->sisa);
        $this->assertSame(5000000.0, (float) $hasil['uang_pangkal']->refresh()->sisa);
    }

    public function test_dashboard_ppsb_memisahkan_penerimaan_perlengkapan(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);
        $this->bayar($santri->id, $hasil['uang_pangkal']->id, '1000000');
        $this->bayar($santri->id, $hasil['perlengkapan']->id, '750000');

        $penerimaan = (new PpsbDashboardService)->penerimaan(self::TA);

        $this->assertSame(1000000.0, (float) $penerimaan['uang_pangkal']);
        $this->assertSame(750000.0, (float) $penerimaan['perlengkapan']);
        // Registrasi 500rb terbit otomatis saat mendaftar & belum dibayar.
        $this->assertSame(1750000.0, (float) $penerimaan['total'], 'total menjumlahkan ketiga komponen');
    }

    public function test_koreksi_nominal_perlengkapan_menonaktifkan_jadwalnya(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);
        (new AngsuranUangPangkalService)->buatRencana($santri->id, [
            'komponen' => 'perlengkapan', 'disepakati_pada' => '2026-07-01',
            'termin' => [['nominal' => '2000000', 'jatuh_tempo' => '2026-08-01']],
        ], $this->admin);

        $tagihan = (new SantriService)->koreksiNominalPerlengkapan($santri->id, [
            'nominal' => '2500000', 'alasan' => 'salah ketik',
        ], $this->admin);

        $this->assertSame(2500000.0, (float) $tagihan->nominal);
        $this->assertSame(2500000.0, (float) $tagihan->sisa);
        // Σ termin tak lagi sama dengan tagihan → jadwal lama harus disusun ulang.
        $this->assertSame(0, \App\Models\RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->count());
        // Uang pangkal tak ikut tersentuh.
        $this->assertSame(5000000.0, (float) $hasil['uang_pangkal']->refresh()->nominal);
    }

    public function test_koreksi_perlengkapan_ditolak_setelah_akrual(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000', 'nominal_perlengkapan' => '2000000']);
        $santri->update(['status' => 'lolos_kesehatan']);
        $this->aktifkanSantri($santri->id, $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('sudah diakrualkan');
        (new SantriService)->koreksiNominalPerlengkapan($santri->id, ['nominal' => '2500000', 'alasan' => 'telat'], $this->admin);
    }

    public function test_perlengkapan_tak_bisa_ditagihkan_dua_kali(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000', 'nominal_perlengkapan' => '2000000']);

        // Penjaga uang pangkal yang lebih dulu menyalak (keduanya sudah terbit).
        $this->expectException(AppException::class);
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '5000000', 'nominal_perlengkapan' => '2000000']);
    }

    /**
     * TEMUAN UJI MANUAL (2026-07-30): form "Tagihkan Uang Pangkal & Perlengkapan"
     * tak mau hilang.
     *
     * Penjaganya dulu "santri ini punya tagihan uang pangkal?". Bagi calon
     * berjalur BEBAS uang pangkal jawabannya SELAMANYA tidak — tagihan itu tak
     * pernah terbit — sehingga formnya terus muncul walau perlengkapannya sudah
     * ada. Diganti `SantriController::bisaDitagihkan()` yang menjawab "apa yang
     * MASIH bisa diterbitkan", per komponen.
     */
    public function test_form_penagihan_hilang_setelah_semua_komponen_terbit(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('Tagihkan Uang Pangkal', false);

        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ]);

        // Keduanya sudah terbit → tak ada lagi yang bisa diterbitkan.
        $this->actingAs($admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertDontSee('Tagihkan Uang Pangkal', false);
    }

    /**
     * Sisa SATU komponen tetap bisa diterbitkan.
     *
     * Tiga tempat harus sejalan, dan test ini menjaga ketiganya sekaligus:
     * (1) syarat tampil form, (2) `nominal` wajib/tidak di validasi controller,
     * (3) `komponen` yang dioper ke service — tanpa (3), menerbitkan
     * perlengkapan untuk santri yang uang pangkalnya sudah terbit ditolak 409.
     */
    public function test_sisa_satu_komponen_masih_bisa_diterbitkan(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $admin = User::find($this->admin);

        // Uang pangkalnya saja yang terbit lebih dulu.
        (new SantriService)->tagihkanUangPangkal($santri->id, [
            'komponen' => ['uang_pangkal'], 'nominal' => '5000000',
        ]);

        // Formnya TETAP muncul — perlengkapannya belum terbit — tetapi isian
        // nominal uang pangkalnya sudah tiada.
        $this->actingAs($admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('Tagihkan Uang Pangkal', false)
            ->assertSee('Uang pangkal sudah ditagihkan', false)
            ->assertSee('name="nominal_perlengkapan"', false);

        // Dan kirimannya diterima TANPA `nominal`: penjaga 409 tak boleh
        // menyalak, karena yang diminta hanya komponen yang masih terbuka.
        $this->actingAs($admin)->post(
            route('santri.aksi', ['id' => $santri->id, 'aksi' => 'tagih-uang-pangkal']),
            ['nominal_perlengkapan' => '2000000'],
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2000000.0, (float) TagihanSantri::where('id_santri', $santri->id)
            ->where('perilaku', 'perlengkapan')->sole()->nominal);

        // Sesudah keduanya ada, formnya baru hilang.
        $this->actingAs($admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertDontSee('Tagihkan Uang Pangkal', false);
    }

    /**
     * Alur HTTP: form penagihan memuat isian perlengkapan (terisi default dari
     * master), satu kiriman menerbitkan dua tagihan, dan halaman angsuran
     * menampilkan kedua komponennya.
     */
    public function test_alur_http_menagih_lalu_melihat_dua_jadwal(): void
    {
        $this->masterPerlengkapan();
        $santri = $this->buatSantriDiterima();
        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('name="nominal_perlengkapan"', false)
            // Berpemisah ribuan di layar, angka mentah di hidden (<x-input-rupiah>).
            ->assertSee('value="2.000.000"', false)
            ->assertSee('name="nominal_perlengkapan" value="2000000"', false)
            ->assertSee('tidak dipotong');

        $this->actingAs($admin)->post(route('santri.aksi', ['id' => $santri->id, 'aksi' => 'tagih-uang-pangkal']), [
            'nominal' => '5000000', 'nominal_perlengkapan' => '2000000',
        ])->assertRedirect();

        $this->assertSame(2, TagihanSantri::where('id_santri', $santri->id)->whereIn('kode_jenis', ['UP', 'PLK'])->count());

        // Blok koreksi perlengkapan kini muncul di halaman santri.
        $this->actingAs($admin)->get(route('santri.show', $santri->id))->assertOk()
            ->assertSee('Koreksi Perlengkapan');

        // Halaman angsuran memuat dua bagian, meski jadwalnya belum dibuat.
        $this->actingAs($admin)->get(route('angsuran_uang_pangkal.show', $santri->id))->assertOk()
            ->assertSee('Uang Pangkal')
            ->assertSee('Biaya Perlengkapan')
            ->assertSee('Belum ada rencana angsuran aktif untuk biaya perlengkapan');

        // Form rencana baru: satu pilihan santri, dua tabel termin.
        $this->actingAs($admin)->get(route('angsuran_uang_pangkal.create'))->assertOk()
            ->assertSee('Termin Uang Pangkal')
            ->assertSee('Termin Biaya Perlengkapan')
            ->assertSee('name="id_santri"', false);
    }

    /** Saldo berjalan satu akun dari seluruh jurnal aktif. */
    private function saldo(string $kodeCoa): float
    {
        return (float) \App\Models\JournalLine::join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.status', 'aktif')->where('journal_lines.kode_coa', $kodeCoa)
            ->get(['journal_lines.debet', 'journal_lines.kredit'])
            ->reduce(fn ($t, $l) => \App\Support\Money::add($t, \App\Support\Money::sub($l->debet, $l->kredit)), '0');
    }
}
