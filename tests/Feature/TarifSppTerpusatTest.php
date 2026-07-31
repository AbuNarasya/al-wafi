<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TarifBiaya;
use App\Models\User;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/** Tarif SPP terpusat di master Jenis Biaya (tabel tarif_spp dibuang). */
class TarifSppTerpusatTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZTS';

    private const PEND = '4.ZZTS.SPP';

    private const PIUT = '1.ZZTS.SPP';

    private const UNIT = 'ZZTSU';

    private const TA = '2026/2027';

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'TS']);
        foreach ([[self::PEND, 'Pendapatan SPP', 'kredit'], [self::PIUT, 'Piutang SPP', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2]);
        User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true]);

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    private function buatSpp(string $kode, ?string $nominal, ?string $jenjang, string $status = 'aktif'): void
    {
        $this->buatBiaya([
            'kode' => $kode, 'nama' => 'SPP', 'tipe' => 'spp', 'nominal' => $nominal, 'kode_jenjang' => $jenjang,
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT,
            'tahun_ajaran' => self::TA, 'berulang' => true, 'status' => $status,
        ]);
    }

    private function santriAktif(string $jenjang): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif']);

        return $santri->refresh();
    }

    public function test_tarif_diambil_per_jenjang_lalu_fallback_umum(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatSpp('SPP-SMP', '400000', 'SMP');
        $svc = new SppService;

        // Jenjang punya tarif sendiri.
        $smp = $svc->nominalSppSantri($this->santriAktif('SMP')->id);
        $this->assertSame('400000.00', $smp['nominal']);
        $this->assertSame('SPP-SMP', $smp['kode_jenis']);
        $this->assertSame('jenjang', $smp['asal']);

        // Jenjang tanpa tarif sendiri → jatuh ke UMUM.
        $sd = $svc->nominalSppSantri($this->santriAktif('SD')->id);
        $this->assertSame('250000.00', $sd['nominal']);
        $this->assertSame('SPP-UMUM', $sd['kode_jenis']);
    }

    public function test_nominal_khusus_santri_mengalahkan_tarif_jenjang(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $santri = $this->santriAktif('SD');
        $santri->update(['nominal_spp' => '100000', 'keterangan_spp' => 'beasiswa sebagian']);

        $hasil = (new SppService)->nominalSppSantri($santri->id);

        $this->assertSame('100000.00', $hasil['nominal']);
        $this->assertSame('khusus', $hasil['asal']);
        $this->assertSame('SPP-UMUM', $hasil['kode_jenis']); // jenis tetap dari master
        $this->assertSame('beasiswa sebagian', $hasil['keterangan']);
    }

    /**
     * `ringkasMassal()` (kolom SPP di daftar santri) HARUS sepakat dengan
     * `nominalSppSantri()` (yang benar-benar menagih). Keduanya menyalin aturan
     * yang sama; kalau salah satu bergeser, angka di daftar akan menipu petugas.
     */
    public function test_ringkas_massal_sepakat_dengan_nominal_per_santri(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatSpp('SPP-SMP', '400000', 'SMP');

        $biasa = $this->santriAktif('SD');
        $smp = $this->santriAktif('SMP');
        $khusus = $this->santriAktif('SD');
        $khusus->update(['nominal_spp' => '0', 'keterangan_spp' => 'anak karyawan']);

        $svc = new SppService;
        $ringkas = $svc->ringkasMassal(Santri::whereIn('id', [$biasa->id, $smp->id, $khusus->id])->get());

        $this->assertSame('250000.00', $ringkas[$biasa->id]['nominal']);
        $this->assertSame('tarif', $ringkas[$biasa->id]['status']);
        $this->assertSame('400000.00', $ringkas[$smp->id]['nominal']);

        // Nominal khusus (termasuk NOL — beasiswa penuh) menang atas tarif jenjang.
        $this->assertSame('khusus', $ringkas[$khusus->id]['status']);
        $this->assertSame('0.00', $ringkas[$khusus->id]['nominal']);
        $this->assertSame('anak karyawan', $ringkas[$khusus->id]['keterangan']);

        // Angkanya sama dengan yang dipakai menagih.
        foreach ([$biasa, $smp, $khusus] as $s) {
            $this->assertSame($svc->nominalSppSantri($s->id)['nominal'], $ringkas[$s->id]['nominal'],
                "angka daftar & angka penagihan harus sama untuk santri {$s->id}");
        }
    }

    /** Sel bertanda Bebas & sel yang belum diisi dibedakan di kolom daftar. */
    public function test_ringkas_massal_membedakan_bebas_dari_belum_diisi(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $belum = $this->santriAktif('SMP'); // SMP tak punya sel sendiri…
        // …dan SPP dicocokkan PERSIS per jenjang, jadi selnya memang belum ada.
        TarifBiaya::where('perilaku', 'spp')->where('kode_jenjang', 'SMP')->delete();

        $bebasSantri = $this->santriAktif('SD');
        TarifBiaya::where('perilaku', 'spp')->where('kode_jenjang', 'SD')
            ->update(['nominal' => null, 'bebas' => true]);

        $ringkas = (new SppService)->ringkasMassal(Santri::whereIn('id', [$belum->id, $bebasSantri->id])->get());

        $this->assertSame('kosong', $ringkas[$belum->id]['status']);
        $this->assertNull($ringkas[$belum->id]['nominal']);
        $this->assertSame('bebas', $ringkas[$bebasSantri->id]['status']);
        $this->assertNull($ringkas[$bebasSantri->id]['nominal']);
    }

    public function test_tarif_spp_ganda_untuk_jenjang_sama_ditolak(): void
    {
        $this->buatSpp('SPP-SD', '300000', 'SD');

        try {
            $this->buatSpp('SPP-SD-2', '350000', 'SD');
            $this->fail('harus 409');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('sudah ada di "SPP-SD"', $e->getMessage());
        }

        // Baris UMUM juga hanya boleh satu…
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->expectException(AppException::class);
        $this->buatSpp('SPP-UMUM-2', '260000', null);
    }

    public function test_baris_nonaktif_tidak_menghalangi_dan_tidak_dipakai(): void
    {
        $this->buatSpp('SPP-SD-LAMA', '200000', 'SD', 'nonaktif');
        $this->buatSpp('SPP-SD', '300000', 'SD'); // tak bentrok dengan yang nonaktif

        $hasil = (new SppService)->nominalSppSantri($this->santriAktif('SD')->id);
        $this->assertSame('SPP-SD', $hasil['kode_jenis']);
        $this->assertSame('300000.00', $hasil['nominal']);
    }

    public function test_tanpa_tarif_memberi_pesan_yang_menuntun(): void
    {
        $santri = $this->santriAktif('SD');

        try {
            (new SppService)->nominalSppSantri($santri->id);
            $this->fail('harus 422');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
            // Pesannya harus menunjuk tempat yang benar: baris akunnya di Jenis
            // Biaya, angkanya di menu Tarif.
            $this->assertStringContainsString('Jenis Biaya', $e->getMessage());
            $this->assertStringContainsString('Tarif', $e->getMessage());
        }
    }

    public function test_halaman_spp_tak_lagi_punya_seksi_tarif(): void
    {
        $this->actingAs(User::first())
            ->get(route('spp.index'))
            ->assertOk()
            ->assertDontSee('Tarif SPP per Jenjang')
            ->assertSee('Terbitkan Tagihan SPP')
            ->assertSee('diatur di'); // penunjuk ke master Jenis Biaya
    }

    /**
     * Santri kini dikirim di BADAN kiriman (`id_santri`), bukan di path — dropdown
     * santrinya sudah menjadi dropdown-yang-bisa-dicari, yang nilainya ada di
     * input hidden dan tak bisa dipakai merangkai URL dari sisi Alpine.
     */
    public function test_nominal_khusus_disimpan_lewat_isian_id_santri(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $santri = $this->santriAktif('SD');

        $this->actingAs(User::first())
            ->put(route('spp.nominal_khusus'), [
                'id_santri' => $santri->id, 'nominal_spp' => '0', 'keterangan_spp' => 'anak karyawan',
            ])->assertRedirect();

        $santri->refresh();
        $this->assertSame('0.00', $santri->nominal_spp);
        $this->assertSame('anak karyawan', $santri->keterangan_spp);

        // Mengosongkan nominal = kembali ke tarif jenjang.
        $this->actingAs(User::first())
            ->put(route('spp.nominal_khusus'), ['id_santri' => $santri->id, 'nominal_spp' => ''])
            ->assertRedirect();
        $this->assertNull($santri->refresh()->nominal_spp);

        // Tanpa santri, kirimannya ditolak validasi — bukan menyunting santri acak.
        $this->actingAs(User::first())
            ->put(route('spp.nominal_khusus'), ['nominal_spp' => '5000'])
            ->assertSessionHasErrors('id_santri');
    }

    /**
     * Rekap di atas pratinjau: berapa rupiah yang akan terbit di tiap jenjang.
     *
     * Yang "tanpa tarif" ikut dihitung TERPISAH — tanpa itu, jenjang yang
     * sebagian selnya belum diisi tampak bertotal kecil tanpa sebab yang
     * terlihat, dan petugas menerbitkan tagihan sambil mengira sudah lengkap.
     */
    public function test_rekap_pratinjau_dikelompokkan_per_jenjang(): void
    {
        $this->buatSpp('SPP-SD', '250000', 'SD');
        $this->buatSpp('SPP-SMP', '400000', 'SMP');
        // SMA dibuat setelah setUp, jadi sel registrasinya belum ikut tercermin —
        // tanpa itu calonnya tak bisa didaftarkan sama sekali.
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 3]);
        $this->pasangTarif(self::TA, 'SMA', null, 'registrasi', '500000');

        $this->santriAktif('SD');
        $bebas = $this->santriAktif('SD');
        $bebas->update(['nominal_spp' => '0', 'keterangan_spp' => 'anak karyawan']);
        $this->santriAktif('SMP');
        $this->santriAktif('SMA'); // belum punya jenis biaya SPP → tertahan

        $rekap = $this->actingAs(User::first())
            ->get(route('spp.index', ['periode' => '2026-07']))
            ->assertOk()
            ->assertSee('Total Tagihan per Jenjang')
            ->viewData('rekapJenjang');

        // Urutannya mengikuti master Jenjang (urutan 1,2,3), bukan abjad kode.
        $this->assertSame(['Sekolah Dasar', 'SMP', 'SMA'], array_column($rekap, 'nama'));

        // SD: satu bertarif 250rb + satu bebas (nol) — keduanya siap, totalnya 250rb.
        $this->assertSame(2, $rekap[0]['siap']);
        $this->assertSame(250000.0, (float) $rekap[0]['total']);

        $this->assertSame(1, $rekap[1]['siap']);
        $this->assertSame(400000.0, (float) $rekap[1]['total']);

        // SMA tak menyumbang rupiah, tetapi santrinya tetap terhitung & disebut.
        $this->assertSame(0, $rekap[2]['siap']);
        $this->assertSame(0.0, (float) $rekap[2]['total']);
        $this->assertSame(1, $rekap[2]['tanpa_tarif']);
    }

    /**
     * Baris pratinjau membawa identitas lengkap dan urutannya JENJANG → NIS.
     *
     * Nama saja tak cukup membedakan santri yang namanya mirip, dan urutan
     * jenjang-dulu itu yang membuat angka di rekap atas bisa ditelusuri ke
     * barisnya. Yang belum ber-NIS (hasil impor lama) ditaruh di belakang
     * kelompoknya — string kosong akan mengalahkan angka mana pun bila diurut apa adanya.
     */
    public function test_pratinjau_membawa_nis_jenjang_tingkat_dan_diurutkan(): void
    {
        $this->buatSpp('SPP-SD', '250000', 'SD');
        $this->buatSpp('SPP-SMP', '400000', 'SMP');

        // Sengaja didaftarkan terbalik: SMP ber-NIS terkecil justru dibuat pertama.
        $this->santriAktif('SMP')->update(['nis' => '260001', 'tingkat' => 2]);
        $this->santriAktif('SD')->update(['nis' => '260009', 'tingkat' => 6]);
        $this->santriAktif('SD')->update(['nis' => '260005', 'tingkat' => 1]);
        $this->santriAktif('SD')->update(['nis' => null, 'tingkat' => 3]);

        $baris = (new SppService)->pratinjau('2026-07');

        $this->assertSame(['260005', '260009', null, '260001'], array_column($baris, 'nis'));
        $this->assertSame(['Sekolah Dasar', 'Sekolah Dasar', 'Sekolah Dasar', 'SMP'], array_column($baris, 'jenjang'));
        $this->assertSame([1, 6, 3, 2], array_column($baris, 'tingkat'));

        // Kolomnya benar-benar sampai ke layar, bukan hanya ada di data.
        $this->actingAs(User::first())
            ->get(route('spp.index', ['periode' => '2026-07']))->assertOk()
            ->assertSee('NIS')
            ->assertSee('260005')
            ->assertSee('Tingkat 6');
    }

    /** Yang sudah pernah terbit dihitung tersendiri, tidak menambah total. */
    public function test_rekap_memisahkan_yang_tagihannya_sudah_ada(): void
    {
        $this->buatSpp('SPP-SD', '250000', 'SD');
        $this->santriAktif('SD');
        $this->santriAktif('SD');

        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], User::first()->id_pengguna);

        $rekap = $this->actingAs(User::first())
            ->get(route('spp.index', ['periode' => '2026-07']))
            ->assertOk()
            ->viewData('rekapJenjang');

        $this->assertSame(0, $rekap[0]['siap']);
        $this->assertSame(2, $rekap[0]['sudah_ada']);
        $this->assertSame(0.0, (float) $rekap[0]['total']);
    }

    /**
     * Santri bebas SPP (nominal khusus NOL) tak boleh menggagalkan penerbitan.
     *
     * Penerbitan mengelompokkan santri per JENIS biaya, dan satu jenis = satu
     * jenjang. Kalau seluruh santri sebuah jenjang bebas, subtotalnya nol dan
     * jurnal D 0 / K 0 ditolak PostingService — dulu itu membatalkan SELURUH
     * transaksi, jadi jenjang lain yang sehat pun ikut gagal terbit.
     */
    public function test_santri_bebas_spp_tidak_menggagalkan_penerbitan_jenjang_lain(): void
    {
        $this->buatSpp('SPP-SD', '250000', 'SD');
        $this->buatSpp('SPP-SMP', '400000', 'SMP');

        $bayar = $this->santriAktif('SMP');
        $bebas = $this->santriAktif('SD');
        $bebas->update(['nominal_spp' => '0', 'keterangan_spp' => 'anak karyawan']);

        $hasil = (new SppService)->generate(
            ['periode' => '2026-07', 'tanggal' => '2026-07-01'],
            User::first()->id_pengguna,
        );

        $this->assertSame(2, $hasil['terbit']);
        $this->assertSame('400000.00', $hasil['total']);

        // Yang membayar: tagihan normal, berjurnal akrual.
        $tBayar = TagihanSantri::where('id_santri', $bayar->id)->where('periode', '2026-07')->sole();
        $this->assertSame('belum_bayar', $tBayar->status);
        $this->assertTrue((bool) $tBayar->sudah_akrual);

        // Yang bebas: tagihannya TETAP terbit sebagai catatan periode ini sudah
        // beres — kalau dilewati, ia akan muncul lagi sebagai "siap terbit" tiap
        // bulan selamanya. Nol rupiah, langsung lunas, dan tanpa akrual.
        $tBebas = TagihanSantri::where('id_santri', $bebas->id)->where('periode', '2026-07')->sole();
        $this->assertSame('0.00', $tBebas->nominal);
        $this->assertSame('lunas', $tBebas->status);
        $this->assertFalse((bool) $tBebas->sudah_akrual);
        $this->assertStringNotContainsString('akrual', $tBebas->keterangan);

        // Hanya SATU jurnal terbit — jenjang yang seluruhnya bebas tak menjurnal.
        $jurnal = JournalEntry::where('sumber_modul', 'TagihanSpp')->get();
        $this->assertCount(1, $jurnal);
        $this->assertSame('400000.00', Money::of($jurnal->first()->lines()->sum('debet')));

        // Periode berikutnya ia tak lagi terhitung "siap" — sudah ada tagihannya.
        $ulang = collect((new SppService)->pratinjau('2026-07'))->firstWhere('id', $bebas->id);
        $this->assertSame('sudah_ada', $ulang['status']);
    }

    // ---- Pintu KEDUA: nominal SPP khusus dari form penagihan PPSB ----

    /** Calon yang sudah lolos med check — form penagihannya muncul di halaman detail. */
    private function calonLolos(string $jenjang): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Almeer', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'lolos_kesehatan']);

        return $santri->refresh();
    }

    /** Sel uang pangkal harus ada, kalau tidak form penagihannya tak dirender. */
    private function buatUangPangkal(string $jenjang): void
    {
        $this->buatBiaya([
            'kode' => 'UP-'.$jenjang, 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal',
            'nominal' => '5000000', 'kode_jenjang' => $jenjang,
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    /**
     * SPP-nya ikut terlihat saat uang pangkal ditagihkan — itulah saat wali duduk
     * membicarakan seluruh biayanya. Angkanya dari tarif jenjang, dan ASAL-nya
     * ikut disebut supaya petugas tak menebak sel mana yang terpakai.
     */
    public function test_form_penagihan_ppsb_menampilkan_nominal_spp_default(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatUangPangkal('SD');
        $calon = $this->calonLolos('SD');

        $spp = $this->actingAs(User::first())->get(route('santri.show', $calon->id))
            ->assertOk()->viewData('sppSantri');

        $this->assertSame('250000.00', $spp['nominal']);
        $this->assertFalse($spp['khusus']);
        $this->assertNotSame('', $spp['label']); // asal angkanya selalu disebut
    }

    /**
     * TANPA menekan "ubah", `nominal_spp` HARUS tetap null: santrinya terus
     * mengikuti tarif jenjang dan ikut naik saat tarif naik. Kalau nominal
     * default ikut tersimpan, seluruh santri PPSB jadi bertanda khusus selamanya.
     */
    public function test_menagihkan_tanpa_menyentuh_spp_tidak_mengunci_nominalnya(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatUangPangkal('SD');
        $calon = $this->calonLolos('SD');

        $this->actingAs(User::first())
            ->post(route('santri.aksi', ['id' => $calon->id, 'aksi' => 'tagih-uang-pangkal']), [
                'nominal' => '5000000',
            ])->assertRedirect();

        $this->assertNull($calon->refresh()->nominal_spp);
        // Tagihan uang pangkalnya tetap terbit seperti biasa.
        $this->assertSame('5000000.00', TagihanSantri::where('id_santri', $calon->id)
            ->where('perilaku', 'uang_pangkal')->value('nominal'));
    }

    /** Dengan penanda `ubah_spp`, angkanya tersimpan sebagai nominal khusus. */
    public function test_nominal_spp_khusus_bisa_ditetapkan_saat_menagihkan(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatUangPangkal('SD');
        $calon = $this->calonLolos('SD');

        $this->actingAs(User::first())
            ->post(route('santri.aksi', ['id' => $calon->id, 'aksi' => 'tagih-uang-pangkal']), [
                'nominal' => '5000000',
                'ubah_spp' => '1', 'nominal_spp' => '150000', 'keterangan_spp' => 'beasiswa 40%',
            ])->assertRedirect();

        $calon->refresh();
        $this->assertSame('150000.00', $calon->nominal_spp);
        $this->assertSame('beasiswa 40%', $calon->keterangan_spp);

        // Dan itu benar-benar dipakai saat SPP-nya nanti dihitung.
        $calon->update(['status' => 'aktif']);
        $hasil = (new SppService)->nominalSppSantri($calon->id);
        $this->assertSame('150000.00', $hasil['nominal']);
        $this->assertSame('khusus', $hasil['asal']);
    }

    /** Nol = beasiswa penuh (bukan "kosong"); kosong = kembali ke tarif jenjang. */
    public function test_nol_dan_kosong_dibedakan_pada_nominal_spp_khusus(): void
    {
        $this->buatSpp('SPP-UMUM', '250000', null);
        $this->buatUangPangkal('SD');
        $calon = $this->calonLolos('SD');

        $this->actingAs(User::first())
            ->post(route('santri.aksi', ['id' => $calon->id, 'aksi' => 'tagih-uang-pangkal']), [
                'nominal' => '5000000',
                'ubah_spp' => '1', 'nominal_spp' => '0', 'keterangan_spp' => 'anak karyawan',
            ])->assertRedirect();
        $this->assertSame('0.00', $calon->refresh()->nominal_spp);

        // Dikosongkan lewat pintu satunya (modul SPP) → alasannya ikut dibuang.
        $this->actingAs(User::first())
            ->put(route('spp.nominal_khusus'), ['id_santri' => $calon->id, 'nominal_spp' => ''])
            ->assertRedirect();
        $calon->refresh();
        $this->assertNull($calon->nominal_spp);
        $this->assertNull($calon->keterangan_spp);
    }

    /**
     * Menetapkan nominal khusus TIDAK menyentuh tagihan SPP yang sudah terbit —
     * angka yang sudah dijanjikan ke wali tetap seperti semula. Yang berubah
     * adalah penerbitan periode berikutnya.
     */
    public function test_nominal_khusus_tidak_mengubah_tagihan_spp_yang_sudah_terbit(): void
    {
        $this->buatSpp('SPP-SD', '250000', 'SD');
        $santri = $this->santriAktif('SD');
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], User::first()->id_pengguna);

        $lama = TagihanSantri::where('id_santri', $santri->id)->where('periode', '2026-07')->sole();
        $this->assertSame('250000.00', $lama->nominal);

        (new SppService)->setNominalKhusus($santri, '100000', 'beasiswa');

        $this->assertSame('250000.00', $lama->refresh()->nominal);
        $this->assertSame('250000.00', $lama->sisa);

        // Periode berikutnya barulah memakai angka baru.
        (new SppService)->generate(['periode' => '2026-08', 'tanggal' => '2026-08-01'], User::first()->id_pengguna);
        $baru = TagihanSantri::where('id_santri', $santri->id)->where('periode', '2026-08')->sole();
        $this->assertSame('100000.00', $baru->nominal);
    }
}
