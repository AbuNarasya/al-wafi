<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\Wali;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Referensi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/** PPSB: master wali & jenis biaya, registrasi + lifecycle santri. */
class PpsbTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZPB';

    private const PEND = '4.ZZPB.REG';

    private const UNIT = 'ZZUNIT';

    private const TA = '2026/2027';

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PB']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan Registrasi', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
    }

    private function buatWali(string $telepon = '08111'): Wali
    {
        return (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => $telepon,
        ]);
    }

    private function buatJenisRegistrasi(): JenisBiaya
    {
        return $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    public function test_wali_menyalin_kontak_utama_dan_telepon_unik(): void
    {
        $wali = $this->buatWali('08123');
        $this->assertSame('Budi', $wali->nama);
        $this->assertSame('08123', $wali->telepon);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah dipakai wali/i');
        $this->buatWali('08123');
    }

    public function test_jenis_biaya_validasi_akun(): void
    {
        $this->buatJenisRegistrasi(); // sukses
        $this->assertDatabaseHas('jenis_biaya', ['kode' => 'REG']);

        $this->expectException(AppException::class);
        $this->buatBiaya([
            'kode' => 'X', 'nama' => 'X', 'tipe' => 'lain', 'kode_coa_pendapatan' => 'TIDAK_ADA', 'kode_unit' => self::UNIT,
        ]);
    }

    public function test_registrasi_santri_membuat_tagihan(): void
    {
        $wali = $this->buatWali();
        $this->buatJenisRegistrasi();

        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '2012-05-01',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ]);

        $this->assertSame('calon', $santri->status);
        $this->assertStringStartsWith('PSB-', $santri->no_pendaftaran);
        $this->assertDatabaseHas('pendaftaran', ['id_santri' => $santri->id]);
        $tagihan = TagihanSantri::where('id_santri', $santri->id)->first();
        $this->assertNotNull($tagihan);
        $this->assertSame(500000.0, (float) $tagihan->sisa);
        // umur accessor (lahir 2012 → belasan tahun)
        $this->assertGreaterThan(9, $santri->umur);
    }

    /**
     * Tarif registrasi bertanda BEBAS → tahap registrasi LANGSUNG DILEWATI.
     *
     * Sebelum diperbaiki, calon seperti ini (mis. jalur Anak Karyawan) tertahan
     * SELAMANYA di "Calon": tak ada tagihan registrasi yang bisa dibayar,
     * sedangkan satu-satunya yang menulis status `terbayar` adalah verifikasi
     * pembayaran registrasi — dan halaman detailnya pun tak punya tombol apa pun
     * untuk status `calon`. Pendaftaran lanjutan sudah lama begini; ini
     * menyamakan pendaftaran baru dengannya.
     */
    public function test_registrasi_bebas_melewati_tahap_pembayaran(): void
    {
        $wali = $this->buatWali();
        $this->buatJenisRegistrasi();
        // Sel tanpa jenjang & tanpa jalur = yang dipakai santri fixture ini.
        $this->pasangTarif(self::TA, null, null, 'registrasi', null, bebas: true);

        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Anak Karyawan', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ]);

        $this->assertSame('terbayar', $santri->refresh()->status);
        $this->assertSame(0, TagihanSantri::where('id_santri', $santri->id)->count(), 'bebas = tanpa tagihan, bukan tagihan nol');
        $this->assertDatabaseHas('pendaftaran', ['id_santri' => $santri->id, 'status' => 'terbayar']);

        // Dan tahap berikutnya benar-benar bisa dijalankan.
        $this->assertSame('terverifikasi', (new SantriService)->verifikasiBerkas($santri->id)->status);
    }

    public function test_lifecycle_transisi_valid_dan_invalid(): void
    {
        $wali = $this->buatWali();
        $this->buatJenisRegistrasi();
        $svc = new SantriService;
        $santri = $svc->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);

        // calon tidak bisa langsung verifikasi berkas (harus terbayar dulu).
        try {
            $svc->verifikasiBerkas($santri->id);
            $this->fail('harus 422');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
        }

        // Simulasikan registrasi terbayar (di produksi digerakkan verifikasi keuangan).
        $santri->update(['status' => 'terbayar']);

        $svc->verifikasiBerkas($santri->id);
        $this->assertSame('terverifikasi', $santri->refresh()->status);
        $svc->seleksi($santri->id, ['nilai_baca' => '85']);
        $this->assertSame('diseleksi', $santri->refresh()->status);
        $svc->pengumuman($santri->id, ['lulus' => true]);
        $this->assertSame('diterima', $santri->refresh()->status);
        $svc->medcheck($santri->id, ['lolos' => true]);
        $this->assertSame('lolos_kesehatan', $santri->refresh()->status);
    }

    /**
     * Label pemilih santri: "NIS - Nama - Jenjang - Tingkat".
     *
     * Formatnya kontrak: enam pemilih santri di layar memakainya, dan gunanya
     * satu — nama saja tak cukup membedakan santri yang namanya mirip. Jenjang
     * disebut lewat NAMA (kode `J001` tak bercerita apa pun), dan calon yang
     * belum ber-NIS memakai nomor pendaftarannya.
     */
    public function test_label_pemilih_santri_memuat_nomor_jenjang_dan_tingkat(): void
    {
        Jenjang::create(['kode' => 'J001', 'nama' => 'SD Tahfizh', 'urutan' => 1,
            'status' => 'aktif', 'jumlah_tingkat' => 6]);
        $wali = $this->buatWali('08129');
        $this->buatJenisRegistrasi();

        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad Fauzan', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J001', 'tingkat' => 3, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ]);

        // Belum ber-NIS (masih calon) → nomor pendaftarannya yang dipakai.
        $this->assertSame(
            "{$santri->no_pendaftaran} - Ahmad Fauzan - SD Tahfizh - Tingkat 3",
            Referensi::labelSantri($santri),
        );

        // Sesudah ber-NIS, NIS-nya menang.
        $santri->update(['nis' => '260001']);
        $this->assertSame(
            '260001 - Ahmad Fauzan - SD Tahfizh - Tingkat 3',
            Referensi::labelSantri($santri->refresh()),
        );

        // Yang kosong tak dibiarkan jadi sel hampa — diisi tanda pisah.
        $santri->update(['tingkat' => null]);
        $this->assertStringEndsWith(' - —', Referensi::labelSantri($santri->refresh()));

        // Opsi dropdown berkunci id santri, dan hanya satu kueri master jenjang.
        $opsi = Referensi::santri();
        $this->assertSame(['260001 - Ahmad Fauzan - SD Tahfizh - —'], array_values($opsi));
        $this->assertSame([$santri->id], array_keys($opsi));
    }

    public function test_mengundurkan_diri(): void
    {
        $wali = $this->buatWali();
        $this->buatJenisRegistrasi();
        $svc = new SantriService;
        $santri = $svc->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'P', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);

        $svc->mengundurkanDiri($santri->id, 'pindah kota');
        $this->assertSame('mengundurkan_diri', $santri->refresh()->status);
    }
}
