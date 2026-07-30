<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurNonaktif;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\TagihanMassalService;
use App\Services\Modules\TarifService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DAFTAR ULANG tahunan sebagai komponen biaya penuh + penonaktifan jalur per
 * (tahun ajaran, jenjang).
 *
 * Yang dijaga di sini:
 *  • daftar ulang punya tarif, diterbitkan massal, dan diakui AKRUAL saat terbit;
 *  • sekali per (santri, jenjang, T.A) — indeks unik ikut menjaga;
 *  • tarifnya TIDAK dibedakan per jalur (sama seperti SPP);
 *  • T.A TAGIHAN berbeda dari ANGKATAN — inilah yang membuat daftar ulang tahun
 *    kedua tidak menabrak tagihan tahun pertama.
 */
class DaftarUlangTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZDU';
    private const PEND = '4.ZZDU.PEND';
    private const PIUT = '1.ZZDU.PIUT';
    private const UNIT = 'ZZDUU';
    private const TA = '2026/2027';

    private const TA2 = '2027/2028';

    private const TA3 = '2028/2029';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'DU']);
        foreach ([[self::PEND, 'Pendapatan DU', 'kredit'], [self::PIUT, 'Piutang DU', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => self::TA2, 'status' => 'aktif']);
        TahunAjaran::create(['kode' => self::TA3, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        foreach ([['REG', 'Reguler'], ['OSS', 'One Stop Schooling']] as [$k, $n]) {
            JalurPendaftaran::create(['kode' => $k, 'nama' => $n]);
        }
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        $svc = new JenisBiayaService;
        $svc->create(['kode' => 'REG-SMP', 'nama' => 'Registrasi SMP', 'tipe' => 'registrasi', 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT]);
        $svc->create(['kode' => 'DU-SMP', 'nama' => 'Daftar Ulang SMP', 'tipe' => 'daftar_ulang', 'kode_jenjang' => 'SMP',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT]);

        $tarif = new TarifService;
        // Biaya masuk lewat matriks jalur…
        $tarif->simpan(self::TA, 'SMP', ['-' => ['registrasi' => ['nominal' => '500000']]]);
        // T.A kedua juga perlu tarif registrasi: fixture santri angkatan 2027/2028
        // tetap menerbitkan tagihan registrasi saat didaftarkan.
        $tarif->simpan(self::TA2, 'SMP', ['-' => ['registrasi' => ['nominal' => '600000']]]);
        // …dan daftar ulang lewat jalurnya sendiri, PER KENAIKAN TINGKAT, disimpan
        // pada tingkat TUJUAN. SMP bertingkat 3 → sel pada tingkat 2 (kenaikan 1→2)
        // dan 3 (kenaikan 2→3). Fixture santri sudah di tingkat 2, jadi sel 2 dipakai.
        $tarif->simpanUmum(self::TA, 'SMP', ['daftar_ulang' => [2 => ['nominal' => '2000000'], 3 => ['nominal' => '2400000']]]);
        $tarif->simpanUmum(self::TA2, 'SMP', ['daftar_ulang' => [2 => ['nominal' => '2500000'], 3 => ['nominal' => '2900000']]]);
        $tarif->simpanUmum(self::TA3, 'SMP', ['daftar_ulang' => [2 => ['nominal' => '3000000'], 3 => ['nominal' => '3400000']]]);
    }

    private function santriAktif(string $nama = 'Ahmad', string $angkatan = self::TA): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L', 'gelombang' => 1,
            // Tingkat 2 = sudah pernah naik. Daftar ulang ditagih SESUDAH kenaikan,
            // jadi santri di tingkat 1 belum punya daftar ulang apa pun.
            'tahun_ajaran' => $angkatan, 'jalur' => 'REG', 'kode_jenjang' => 'SMP', 'tingkat' => 2,
        ]);
        $santri->update(['status' => 'aktif']);

        return $santri->refresh();
    }

    // ---- Perilaku & master ----

    public function test_tipe_daftar_ulang_lama_ikut_berpindah_perilaku(): void
    {
        // Migrasi memindahkan tipe berperilaku "lain" yang bernama Daftar Ulang.
        $lain = TipeBiaya::create(['kode' => 'DU9', 'nama' => 'Daftar Ulang Lama', 'perilaku' => 'lain', 'urutan' => 9, 'status' => 'aktif']);
        TipeBiaya::lupakan();

        $this->assertSame('lain', $lain->perilaku, 'yang dibuat SESUDAH migrasi tetap apa adanya');
        $this->assertSame('daftar_ulang', TipeBiaya::perilakuDari('daftar_ulang'));
        $this->assertContains('daftar_ulang', array_keys(TipeBiaya::PERILAKU));
    }

    /** Daftar ulang & SPP tak boleh menyelinap lewat matriks jalur. */
    public function test_daftar_ulang_ditolak_di_matriks_jalur(): void
    {
        $svc = new TarifService;

        foreach (['REG', '-'] as $kunciJalur) {
            try {
                $svc->simpan(self::TA, 'SMP', [$kunciJalur => ['daftar_ulang' => ['nominal' => '1000000']]]);
                $this->fail("kiriman lewat jalur \"{$kunciJalur}\" seharusnya ditolak");
            } catch (AppException $e) {
                $this->assertStringContainsString('Biaya santri aktif', $e->getMessage());
            }
        }

        // Sebaliknya juga dijaga: biaya masuk tak boleh lewat jalur biaya santri aktif.
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('matriks jalur');
        $svc->simpanUmum(self::TA, 'SMP', ['uang_pangkal' => ['nominal' => '1000000']]);
    }

    /** Matriks jalur hanya memuat BIAYA MASUK; daftar ulang & SPP punya panel sendiri. */
    public function test_grid_memisahkan_biaya_masuk_dari_biaya_santri_aktif(): void
    {
        $grid = (new TarifService)->grid(self::TA, 'SMP');

        $selJalur = array_keys($grid['jalur'][0]['sel']);
        $this->assertSame(['registrasi', 'uang_pangkal', 'perlengkapan'], $selJalur);
        $this->assertArrayNotHasKey('daftar_ulang', $grid['jalur'][0]['sel']);
        $this->assertArrayNotHasKey('spp', $grid['jalur'][0]['sel']);

        // SPP satu angka; daftar ulang satu angka per KENAIKAN, disimpan pada
        // tingkat TUJUAN. SMP bertingkat 3 → sel 2 & 3, bukan 1 & 2.
        $this->assertSame([2, 3], $grid['tingkat_kenaikan']);
        $this->assertArrayHasKey('spp', $grid['umum']);
        $this->assertSame([2, 3], array_keys($grid['umum']['daftar_ulang']));
        $this->assertSame('2000000.00', $grid['umum']['daftar_ulang'][2]['nominal']);
        $this->assertSame('2400000.00', $grid['umum']['daftar_ulang'][3]['nominal']);
    }

    /** SDTQ bertingkat 6 → 5 kenaikan, bersel pada tingkat tujuan 2…6. */
    public function test_sel_kenaikan_satu_kurang_dari_jumlah_tingkat(): void
    {
        $svc = new TarifService;

        $this->assertSame([2, 3, 4, 5, 6], TarifService::tingkatKenaikan('SDTQ'));
        $this->assertSame([2, 3], TarifService::tingkatKenaikan('SMP'));
        $this->assertSame([2, 3, 4, 5, 6], array_keys($svc->grid(self::TA, 'SDTQ')['umum']['daftar_ulang']));

        // Tingkat 1 bukan hasil kenaikan → tak bisa diberi tarif daftar ulang.
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Tingkat 1 bukan hasil kenaikan');
        $svc->simpanUmum(self::TA, 'SDTQ', ['daftar_ulang' => [1 => ['nominal' => '1000000']]]);
    }

    /**
     * DUA GOLONGAN yang tak ditagih daftar ulang: santri pada TAHUN MASUKNYA
     * (baru maupun pindahan), dan santri yang masih di TINGKAT 1 — belum pernah
     * naik. Sebaliknya santri di tingkat TERAKHIR justru DITAGIH: ia baru saja
     * naik ke tingkat itu.
     */
    public function test_tahun_masuk_dan_tingkat_satu_dilewati(): void
    {
        $baru = $this->santriAktif('Baru Masuk', self::TA);
        $tingkatSatu = $this->santriAktif('Belum Naik', self::TA);
        $tingkatSatu->update(['tingkat' => 1]);
        $akhir = $this->santriAktif('Tingkat Akhir', self::TA);
        $akhir->update(['tingkat' => 3]); // tingkat terakhir SMP — baru naik 2→3

        // Ditagih untuk T.A berikutnya, bukan tahun masuknya.
        $hasil = (new TagihanMassalService)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'SMP']);
        $baris = collect($hasil['baris'])->keyBy('id');

        $this->assertSame('terbit', $baris[$baru->id]['daftar_ulang']['keputusan'], 'sudah di tingkat 2');
        $this->assertSame('terbit', $baris[$akhir->id]['daftar_ulang']['keputusan'], 'tingkat terakhir tetap ditagih');
        $this->assertSame('2900000.00', $baris[$akhir->id]['daftar_ulang']['nominal'], 'tarif kenaikan 2→3');
        $this->assertSame('dilewati', $baris[$tingkatSatu->id]['daftar_ulang']['keputusan']);
        $this->assertStringContainsString('tingkat 1', $baris[$tingkatSatu->id]['daftar_ulang']['alasan']);

        // Untuk TAHUN MASUKNYA sendiri, semuanya dilewati.
        $masuk = (new TagihanMassalService)->pratinjau(['tahun_ajaran' => self::TA, 'kode_jenjang' => 'SMP']);
        $this->assertSame('dilewati', collect($masuk['baris'])->firstWhere('id', $baru->id)['daftar_ulang']['keputusan']);
        $this->assertStringContainsString('tahun MASUK', collect($masuk['baris'])->firstWhere('id', $baru->id)['daftar_ulang']['alasan']);

        // Dan penerbitannya pun ditolak, bukan hanya disembunyikan di pratinjau.
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tahun MASUK');
        (new TagihanMassalService)->terbitkan(self::TA, [$baru->id => '2000000'], $this->admin->id_pengguna);
    }

    /** Tarif daftar ulang dipilih menurut TINGKAT TUJUAN, bukan satu angka per jenjang. */
    public function test_tarif_daftar_ulang_mengikuti_tingkat(): void
    {
        $svc = new TarifService;

        $this->assertSame('2000000.00', $svc->cari('daftar_ulang', self::TA, 'SMP', null, 2)['nominal']);
        $this->assertSame('2400000.00', $svc->cari('daftar_ulang', self::TA, 'SMP', null, 3)['nominal']);
        // Tingkat 1 tak punya sel — ia bukan hasil kenaikan.
        $this->assertSame('kosong', $svc->cari('daftar_ulang', self::TA, 'SMP', null, 1)['status']);
        // Tingkat santri yang belum terisi juga dikatakan terus terang.
        $tanpa = $svc->cari('daftar_ulang', self::TA, 'SMP', null, null);
        $this->assertSame('kosong', $tanpa['status']);
        $this->assertStringContainsString('tingkat santri ini belum terisi', $tanpa['label']);
    }

    public function test_santri_tanpa_tingkat_terhalang_di_pratinjau(): void
    {
        $santri = $this->santriAktif();
        $santri->update(['tingkat' => null]);

        $hasil = (new TagihanMassalService)->pratinjau(['tahun_ajaran' => self::TA2, 'kode_jenjang' => 'SMP']);

        $this->assertSame('terhalang', $hasil['baris'][0]['daftar_ulang']['keputusan']);
        $this->assertStringContainsString('tingkat santri ini belum terisi', $hasil['baris'][0]['daftar_ulang']['alasan']);
    }

    /** Modul massal tinggal daftar ulang — uang pangkal, perlengkapan, & SPP tak ada di sini. */
    public function test_massal_hanya_melayani_daftar_ulang(): void
    {
        $this->assertSame(['daftar_ulang'], array_keys(TagihanMassalService::KOMPONEN));
        $this->assertSame(['aktif'], TagihanMassalService::STATUS);

        // Halamannya pun tak lagi menawarkan saringan jalur/gelombang.
        $this->actingAs($this->admin)->get(route('tagihan_massal.index'))->assertOk()
            ->assertSee('Daftar Ulang')
            ->assertDontSee('name="jalur[]"', false)
            ->assertDontSee('name="gelombang"', false)
            ->assertDontSee('name="komponen[]"', false);
    }

    // ---- Penerbitan massal & akrual ----

    public function test_terbit_massal_dengan_akrual_satu_jurnal_per_jenis(): void
    {
        $a = $this->santriAktif('A');
        $b = $this->santriAktif('B');

        // Ditagih untuk T.A BERIKUTNYA — pada tahun masuknya sendiri tak ada daftar ulang.
        $hasil = (new TagihanMassalService)->terbitkan(self::TA2, [
            $a->id => '2000000',
            $b->id => '1500000', // ditimpa petugas
        ], $this->admin->id_pengguna);

        $this->assertSame(2, $hasil['terbit']);
        $this->assertSame('3500000.00', $hasil['total']);

        $tagihan = TagihanSantri::where('perilaku', 'daftar_ulang')->get();
        $this->assertCount(2, $tagihan);
        $this->assertTrue($tagihan->every(fn ($t) => (bool) $t->sudah_akrual), 'daftar ulang diakui saat terbit');
        $this->assertTrue($tagihan->every(fn ($t) => $t->tahun_ajaran === self::TA2 && $t->kode_jenjang === 'SMP'));

        // SATU jurnal untuk seluruh batch, bukan satu per santri.
        $jurnal = JournalEntry::where('referensi', 'like', 'DUL%')->get();
        $this->assertCount(1, $jurnal);
        $this->assertSame(3500000.0, (float) $jurnal->first()->lines->sum('debet'));
    }

    public function test_daftar_ulang_hanya_sekali_per_tahun_ajaran(): void
    {
        $santri = $this->santriAktif();
        $svc = new TagihanMassalService;
        $svc->terbitkan(self::TA2, [$santri->id => '2500000'], $this->admin->id_pengguna);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $svc->terbitkan(self::TA2, [$santri->id => '2500000'], $this->admin->id_pengguna);
    }

    /**
     * INTI pemisahan T.A tagihan dari angkatan: santri angkatan 2026/2027 tetap
     * bisa ditagih daftar ulang 2027/2028. Sebelum dipisah, tagihan kedua akan
     * tercap tahun angkatan lagi lalu ditolak indeks unik.
     */
    public function test_tahun_kedua_bisa_ditagih_walau_angkatannya_tetap(): void
    {
        $santri = $this->santriAktif();
        $svc = new TagihanMassalService;
        $svc->terbitkan(self::TA2, [$santri->id => '2500000'], $this->admin->id_pengguna);
        $svc->terbitkan(self::TA3, [$santri->id => '3000000'], $this->admin->id_pengguna);

        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'daftar_ulang')
            ->orderBy('tahun_ajaran')->get();
        $this->assertCount(2, $tagihan);
        $this->assertSame([self::TA2, self::TA3], $tagihan->pluck('tahun_ajaran')->all());
        $this->assertSame(self::TA, $santri->refresh()->tahun_ajaran, 'angkatan santri tidak ikut berubah');
    }

    public function test_pratinjau_mengusulkan_tarif_tahun_tagihan_bukan_angkatan(): void
    {
        $santri = $this->santriAktif('Ahmad', self::TA);

        $hasil = (new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'SMP',
        ]);

        $this->assertCount(1, $hasil['baris']);
        $this->assertSame('terbit', $hasil['baris'][0]['daftar_ulang']['keputusan']);
        $this->assertSame('2500000.00', $hasil['baris'][0]['daftar_ulang']['nominal'], 'tarif T.A tagihan yang dipakai');
        $this->assertSame(self::TA, $hasil['baris'][0]['angkatan']);
        $this->assertSame($santri->id, $hasil['baris'][0]['id']);
    }

    public function test_saringan_angkatan_memilah_santri(): void
    {
        $this->santriAktif('Angkatan Lama', self::TA);
        $this->santriAktif('Angkatan Baru', self::TA2);

        $semua = (new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'SMP',
        ]);
        $disaring = (new TagihanMassalService)->pratinjau([
            'tahun_ajaran' => self::TA2, 'kode_jenjang' => 'SMP', 'angkatan' => self::TA2,
        ]);

        $this->assertCount(2, $semua['baris']);
        $this->assertCount(1, $disaring['baris']);
        $this->assertSame('Angkatan Baru', $disaring['baris'][0]['nama']);
    }

    public function test_pembayaran_daftar_ulang_masuk_lingkup_kependidikan(): void
    {
        $santri = $this->santriAktif();
        (new TagihanMassalService)->terbitkan(self::TA2, [$santri->id => '2500000'], $this->admin->id_pengguna);

        // Tagihan daftar ulang harus muncul di form pembayaran KEPENDIDIKAN…
        $this->actingAs($this->admin)->get(route('pembayaran_kesantrian.create'))
            ->assertOk()->assertSee('Daftar Ulang SMP');
        // …dan TIDAK di PPSB, yang hanya mengurus calon.
        $this->actingAs($this->admin)->get(route('pembayaran_ppsb.create'))
            ->assertOk()->assertDontSee('Daftar Ulang SMP');
    }

    // ---- Jalur nonaktif per (T.A, jenjang) ----

    public function test_jalur_nonaktif_hilang_dari_grid_dan_bisa_diaktifkan_lagi(): void
    {
        $svc = new TarifService;
        $svc->nonaktifkanJalur(self::TA, 'SDTQ', 'OSS');

        $grid = $svc->grid(self::TA, 'SDTQ');
        $this->assertNotContains('OSS', array_column($grid['jalur'], 'kode'));
        $this->assertSame(['OSS'], array_column($grid['nonaktif'], 'kode'));
        // Jenjang lain tak terpengaruh — penonaktifan ini per (T.A, jenjang).
        $this->assertContains('OSS', array_column($svc->grid(self::TA, 'SMP')['jalur'], 'kode'));

        $svc->aktifkanJalur(self::TA, 'SDTQ', 'OSS');
        $this->assertContains('OSS', array_column($svc->grid(self::TA, 'SDTQ')['jalur'], 'kode'));
    }

    public function test_jalur_nonaktif_ditolak_saat_mendaftarkan_calon(): void
    {
        (new TarifService)->nonaktifkanJalur(self::TA, 'SMP', 'OSS');
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812345']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('tidak berlaku untuk jenjang SMP');
        (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'gelombang' => 1,
            'tahun_ajaran' => self::TA, 'jalur' => 'OSS', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
        ]);
    }

    public function test_jalur_yang_masih_dipakai_santri_tidak_bisa_dinonaktifkan(): void
    {
        $this->santriAktif();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Masih ada 1 santri');
        (new TarifService)->nonaktifkanJalur(self::TA, 'SMP', 'REG');
    }

    public function test_penonaktifan_ikut_tersalin_ke_tahun_ajaran_berikutnya(): void
    {
        $svc = new TarifService;
        $svc->nonaktifkanJalur(self::TA, 'SDTQ', 'OSS');
        // SDTQ perlu punya sel tarif agar penyalinannya tidak ditolak "tak ada tarif".
        $svc->simpanUmum(self::TA, 'SDTQ', ['daftar_ulang' => [2 => ['nominal' => '1000000']]]);

        $hasil = $svc->salin(self::TA, self::TA2, 'SDTQ');

        $this->assertSame(1, $hasil['jalur_ditutup']);
        $this->assertSame(['OSS'], JalurNonaktif::kodeUntuk(self::TA2, 'SDTQ'));
    }

    public function test_dropdown_jalur_membawa_peta_penonaktifan(): void
    {
        (new TarifService)->nonaktifkanJalur(self::TA, 'SDTQ', 'OSS');

        $this->actingAs($this->admin)->get(route('santri.create'))->assertOk()
            // Seluruh option tetap dirender server (bisa diperiksa & tetap jalan
            // tanpa JavaScript); Alpine yang menyembunyikan yang tak berlaku.
            ->assertSee('value="OSS"', false)
            ->assertSee('>One Stop Schooling</option>', false)
            ->assertSee('x-bind:disabled="tutup.includes(\'OSS\')"', false);
    }

    public function test_halaman_tarif_memuat_kolom_daftar_ulang_dan_tombol_jalur(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tarif.index', ['ta' => self::TA, 'jenjang' => 'SMP']))
            ->assertOk()
            ->assertSee('Daftar Ulang')
            // Daftar ulang & SPP punya panelnya sendiri di LUAR matriks jalur,
            // dan daftar ulang satu isian per TINGKAT.
            ->assertSee('Biaya santri aktif')
            ->assertSee('name="umum[spp][nominal]"', false)
            ->assertSee('name="umum[daftar_ulang][2][nominal]"', false)
            ->assertSee('name="umum[daftar_ulang][3][nominal]"', false)
            ->assertDontSee('name="umum[daftar_ulang][1][nominal]"', false)
            ->assertSee('Jalur yang berlaku di SMP');

        $this->actingAs($this->admin)->post(route('tarif.jalur'), [
            'tahun_ajaran' => self::TA, 'kode_jenjang' => 'SDTQ', 'kode_jalur' => 'OSS', 'aksi' => 'nonaktifkan',
        ])->assertRedirect();

        $this->assertSame(['OSS'], JalurNonaktif::kodeUntuk(self::TA, 'SDTQ'));
    }
}
