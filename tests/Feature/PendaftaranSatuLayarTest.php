<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Http\Controllers\SantriController;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Gelombang;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * FORM PENDAFTARAN SATU LAYAR — dua hal yang dulu memaksa petugas keluar dari
 * formulirnya, atau mengakali angkanya:
 *
 *  1. WALI SEKALIAN. Keluarga yang belum terdaftar dulu harus dibuat di modul
 *     lain lebih dulu, dan seluruh isian santri yang sudah diketik hilang.
 *     Barisnya kini ditulis DALAM SATU TRANSAKSI dengan santrinya — pendaftaran
 *     yang gagal tak boleh meninggalkan wali tanpa seorang anak pun.
 *
 *  2. PEMBEBASAN REGISTRASI PER ANAK. Sebelumnya pembebasan hanya ada pada
 *     tingkat TARIF, yang membebaskan seluruh pendaftar satu jalur. Wewenangnya
 *     terpisah dari hak input santri, dan potongan gelombangnya TETAP diberikan.
 */
class PendaftaranSatuLayarTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZPS';

    private const PENDAPATAN = '4.ZZPS.REG';

    private const UNIT = 'ZZPSU';

    private const TA = '2026/2027';

    protected function setUp(): void
    {
        parent::setUp();
        // Dibekukan supaya "tahun berjalan" & periode gelombang tak bergeser
        // bersama kalender mesin yang menjalankan test.
        Carbon::setTestNow('2026-09-15');

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Pendaftaran Uji']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Registrasi',
            'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);

        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji', 'status' => 'aktif',
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2027-06-30', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'urutan' => 1, 'status' => 'aktif']);
        $this->jenjangUji();
        $this->buatBiaya([
            'kode' => 'REGPS', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => self::UNIT,
            'kode_jenjang' => $this->jenjangUji(), 'tahun_ajaran' => self::TA,
        ]);
    }

    /** @param  array<string,array<string,bool>>  $hak  kosong = admin penuh */
    private function pengguna(string $username, array $hak = []): User
    {
        $user = User::create([
            'username' => $username, 'nama' => $username, 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => $hak === [], 'status' => 'aktif',
        ]);
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create([
                'id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul,
                'lihat' => true, 'buat' => $aksi['buat'] ?? false, 'ubah' => $aksi['ubah'] ?? false,
                'hapus' => $aksi['hapus'] ?? false, 'menu' => true,
            ]);
        }
        Akses::lupakan();

        return $user;
    }

    /**
     * Isian santri yang sah & minimal, bentuk SERVICE (gelombang null = tanpa
     * gelombang). Kiriman lewat form memakai `isianForm()`, yang memakai penanda
     * teksnya — sebab isian wajib tak bisa dikirim kosong.
     *
     * @return array<string,mixed>
     */
    private function isianSantri(array $timpa = []): array
    {
        return array_merge([
            'nama' => 'Calon Uji', 'jenis_kelamin' => 'L',
            'kode_jenjang' => $this->jenjangUji(), 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
            'gelombang' => null,
        ], $timpa);
    }

    /** @return array<string,mixed> */
    private function isianForm(array $timpa = []): array
    {
        return $this->isianSantri(['gelombang' => SantriController::TANPA_GELOMBANG] + $timpa);
    }

    // ---- 1. Wali sekalian ----

    public function test_wali_baru_dibuat_bersama_santrinya(): void
    {
        $santri = (new SantriService)->create($this->isianSantri([
            'wali_baru' => [
                'kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '081200001',
                'email_ayah' => 'budi@contoh.id', 'alamat' => 'Jl. Uji 1', 'status' => 'aktif',
            ],
        ]));

        $wali = $santri->wali;
        $this->assertSame('Budi', $wali->nama);
        // nama & telepon wali adalah SALINAN kontak utama — diisi WaliService,
        // bukan diketik terpisah di form santri.
        $this->assertSame('081200001', $wali->telepon);
        $this->assertSame('Jl. Uji 1', $wali->alamat);
        $this->assertSame('aktif', $wali->status);
    }

    public function test_wali_baru_ikut_batal_bila_santrinya_gagal(): void
    {
        // NISN yang sudah dipakai menggagalkan pendaftaran SETELAH walinya ditulis.
        $lama = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lama', 'telepon_ayah' => '081200000']);
        (new SantriService)->create($this->isianSantri(['id_wali' => $lama->id, 'nisn' => '123456']));

        try {
            (new SantriService)->create($this->isianSantri([
                'nama' => 'Anak Kedua', 'nisn' => '123456',
                'wali_baru' => ['kontak_utama' => 'ayah', 'nama_ayah' => 'Baru', 'telepon_ayah' => '081200002'],
            ]));
            $this->fail('harus 409 — NISN kembar');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
        }

        // Inilah inti transaksinya: wali yatim tak boleh tertinggal dan mengotori
        // pencarian wali selamanya.
        $this->assertDatabaseMissing('wali', ['telepon' => '081200002']);
        $this->assertSame(1, Wali::count());
    }

    public function test_telepon_kembar_mengarahkan_ke_wali_yang_sudah_ada(): void
    {
        (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Abu Fulan', 'telepon_ayah' => '081299999']);

        try {
            (new SantriService)->create($this->isianSantri([
                'wali_baru' => ['kontak_utama' => 'ayah', 'nama_ayah' => 'Abu Fulan', 'telepon_ayah' => '081299999'],
            ]));
            $this->fail('harus 409 — telepon kembar');
        } catch (AppException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('Abu Fulan', $e->getMessage());
            $this->assertStringContainsString('kakak-adik', $e->getMessage());
        }
        $this->assertSame(0, Santri::count());
    }

    public function test_form_mengirim_isian_wali_datar_menjadi_satu_wali(): void
    {
        $this->actingAs($this->pengguna('ps_admin'))
            ->post(route('santri.store'), $this->isianForm([
                'mode_wali' => 'baru',
                'wali_kontak_utama' => 'ibu',
                'wali_nama_ibu' => 'Ibu Sarah', 'wali_telepon_ibu' => '081233333',
                'wali_pendapatan_ibu' => 'juta_5_10',
                'wali_nama_ayah' => 'Ayah Umar', 'wali_telepon_ayah' => '081244444',
                'wali_alamat' => 'Jl. Kirim 9', 'wali_auto_debet' => '1',
            ]))->assertSessionMissing('error');

        $wali = Wali::firstOrFail();
        // Kontak utama IBU → nama & telepon wali ikut ibu, bukan ayah, walau
        // keduanya terisi.
        $this->assertSame('Ibu Sarah', $wali->nama);
        $this->assertSame('081233333', $wali->telepon);
        $this->assertSame('Ayah Umar', $wali->nama_ayah);
        $this->assertSame('juta_5_10', $wali->pendapatan_ibu);
        $this->assertTrue($wali->auto_debet);
        $this->assertSame($wali->id, Santri::firstOrFail()->id_wali);
    }

    // ---- 2. Pembebasan biaya registrasi ----

    public function test_pembebasan_membatalkan_tagihan_registrasi_dan_melewati_tahapnya(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '081211111']);
        $pembebas = $this->pengguna('ps_kepala');

        $santri = (new SantriService)->create($this->isianSantri([
            'id_wali' => $wali->id,
            'gratis_registrasi' => true,
            'alasan_gratis_registrasi' => 'Anak yatim, surat keterangan RT terlampir.',
            'gratis_registrasi_oleh' => $pembebas->id_pengguna,
        ]));

        $this->assertTrue($santri->gratis_registrasi);
        $this->assertSame(0, TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')->count());
        // Tanpa tagihan tak ada yang bisa memverifikasi pembayaran, jadi tahapnya
        // dilewati — kalau tidak, calonnya tertahan selamanya di status "calon".
        $this->assertSame('terbayar', $santri->status);
        $this->assertSame('terbayar', $santri->pendaftaran()->first()->status);

        // Jejak pertanggungjawabannya lengkap & cap waktunya ditulis service.
        $this->assertSame($pembebas->id_pengguna, $santri->gratis_registrasi_oleh);
        $this->assertStringContainsString('yatim', $santri->alasan_gratis_registrasi);
        $this->assertNotNull($santri->gratis_registrasi_pada);
    }

    public function test_pembebasan_tak_menuntut_tarif_yang_belum_diisi(): void
    {
        // Jalur yang sel tarifnya belum ada: pendaftaran biasa ditolak…
        JalurPendaftaran::create(['kode' => 'khusus', 'nama' => 'Khusus', 'urutan' => 2, 'status' => 'aktif']);
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '081222222']);

        try {
            (new SantriService)->create($this->isianSantri(['id_wali' => $wali->id, 'jalur' => 'khusus']));
            $this->fail('harus 422 — sel tarif belum diisi');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
        }

        // …tetapi anak yang memang tidak ditagih tak boleh ikut tertolak hanya
        // karena tarif jalurnya belum dilengkapi.
        $santri = (new SantriService)->create($this->isianSantri([
            'id_wali' => $wali->id, 'jalur' => 'khusus', 'nama' => 'Dibebaskan',
            'gratis_registrasi' => true, 'alasan_gratis_registrasi' => 'Anak karyawan.',
        ]));
        $this->assertSame('terbayar', $santri->status);
    }

    public function test_pembebasan_ditolak_tanpa_hak_meski_tandanya_dikirim(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '081255555']);

        // Hak input santri saja: centangnya tak dirender, dan tanda yang dipalsukan
        // pun tak menembus — tagihan registrasinya tetap terbit.
        $tanpaHak = $this->pengguna('ps_petugas', ['santri' => ['buat' => true]]);
        $this->actingAs($tanpaHak)->post(route('santri.store'), $this->isianForm([
            'id_wali' => $wali->id, 'nama' => 'Tanpa Hak',
            'gratis_registrasi' => '1', 'alasan_gratis_registrasi' => 'coba-coba',
        ]))->assertSessionMissing('error');

        $santri = Santri::where('nama', 'Tanpa Hak')->firstOrFail();
        $this->assertFalse($santri->gratis_registrasi);
        $this->assertNull($santri->alasan_gratis_registrasi);
        $this->assertSame(1, TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'registrasi')->count());

        // Dengan haknya: menembus, dan tagihannya tak terbit.
        $berhak = $this->pengguna('ps_kepala2', ['santri' => ['buat' => true], 'pembebasan-registrasi' => ['buat' => true]]);
        $this->actingAs($berhak)->post(route('santri.store'), $this->isianForm([
            'id_wali' => $wali->id, 'nama' => 'Berhak',
            'gratis_registrasi' => '1', 'alasan_gratis_registrasi' => 'Dhuafa.',
        ]))->assertSessionMissing('error');

        $dibebaskan = Santri::where('nama', 'Berhak')->firstOrFail();
        $this->assertTrue($dibebaskan->gratis_registrasi);
        $this->assertSame($berhak->id_pengguna, $dibebaskan->gratis_registrasi_oleh);
        $this->assertSame(0, TagihanSantri::where('id_santri', $dibebaskan->id)->where('perilaku', 'registrasi')->count());
    }

    public function test_alasan_wajib_diisi_saat_tanda_pembebasan_dikirim(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '081266666']);

        $this->actingAs($this->pengguna('ps_admin2'))
            ->post(route('santri.store'), $this->isianForm([
                'id_wali' => $wali->id, 'gratis_registrasi' => '1',
            ]))
            ->assertSessionHasErrors('alasan_gratis_registrasi');
        $this->assertSame(0, Santri::count());
    }

    /**
     * Potongan gelombang TETAP diberikan kepada yang dibebaskan.
     *
     * Aturan umumnya "potongan diperoleh dengan membayar registrasi", dan
     * menerapkannya apa adadanya membuat uang pangkal si dhuafa justru LEBIH MAHAL
     * daripada tetangga sebangkunya yang mampu membayar. Yang dipakai tanggal
     * pendaftarannya.
     */
    public function test_yang_dibebaskan_tetap_mendapat_potongan_gelombang(): void
    {
        $jenjang = $this->jenjangUji();
        $this->buatBiaya([
            'kode' => 'UPPS', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '10000000',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => self::UNIT,
            'kode_jenjang' => $jenjang, 'tahun_ajaran' => self::TA,
        ]);
        Gelombang::create(['tahun_ajaran' => self::TA, 'kode' => 'G1', 'nama' => 'Gelombang 1',
            'berlaku_mulai' => '2026-09-01', 'berlaku_sampai' => '2026-12-31', 'status' => 'aktif']);
        PotonganGelombang::create(['tahun_ajaran' => self::TA, 'kode_jenjang' => $jenjang,
            'gelombang' => 'G1', 'potongan' => '1000000']);

        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '081277777']);
        $santri = (new SantriService)->create($this->isianSantri([
            'id_wali' => $wali->id, 'gelombang' => 'G1',
            'gratis_registrasi' => true, 'alasan_gratis_registrasi' => 'Dhuafa.',
        ]));

        // Uang pangkal baru boleh ditagih setelah calonnya lulus seleksi; tahapan
        // seleksinya sendiri bukan yang sedang diuji di sini.
        $santri->update(['status' => 'diterima']);
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '10000000']);

        $tagihan = TagihanSantri::where('id_santri', $santri->id)->where('perilaku', 'uang_pangkal')->firstOrFail();
        $this->assertSame(9000000.0, (float) $tagihan->nominal);
    }
}
