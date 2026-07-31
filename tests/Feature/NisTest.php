<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\NisSantri;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\NisService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * NIS berformat, beriwayat, dan diterbitkan MASSAL.
 *
 * Format: {TA4}{TINGKAT2}{URUT3} → 262707001
 *   2627 = tahun ajaran saat MASUK JENJANG itu
 *   07   = tingkat saat masuk jenjang itu
 *   001  = urutan menurut abjad dalam satu (T.A masuk, jenjang)
 *
 * Diterbitkan manual karena urutan abjad hanya bisa ditentukan setelah seluruh
 * angkatan diterima — menerbitkannya satu per satu saat daftar ulang akan
 * menghasilkan urutan kedatangan.
 */
class NisTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZNS';

    private const PEND = '4.ZZNS.PEND';

    private const UNIT = 'ZZNSU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15');

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'NS']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);

        // Penomoran berkelanjutan: SDTQ 1–6, SMP 7–9, SMA 10–12.
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3, 'tingkat_mulai' => 10]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3, 'tingkat_mulai' => 7, 'kode_jenjang_lanjutan' => 'SMA']);
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6, 'tingkat_mulai' => 1, 'kode_jenjang_lanjutan' => 'SMP']);

        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Santri aktif di sebuah jenjang & tingkat, lengkap dengan riwayat tingkatnya. */
    private function santri(string $nama, string $jenjang, int $tingkat, string $ta = self::TA): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(1000000, 9999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $jenjang,
            'tingkat' => $tingkat, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif', 'tahun_ajaran_berjalan' => $ta]);
        RiwayatTingkat::create(['id_santri' => $santri->id, 'tahun_ajaran' => $ta,
            'kode_jenjang' => $jenjang, 'tingkat' => $tingkat]);

        return $santri->refresh();
    }

    /** Format bawaan merakit persis 2627 + 07 + 001. */
    public function test_format_bawaan_merakit_nis(): void
    {
        $svc = new NisService;

        $this->assertSame('{TA4}{TINGKAT2}{URUT3}', $svc->pengaturan()->format);
        $this->assertSame('262707001', $svc->contoh());
        $this->assertSame('262710012', $svc->rakit('{TA4}{TINGKAT2}{URUT3}', [
            'tahun_ajaran' => '2026/2027', 'tingkat' => 10, 'urut' => 12, 'kode_jenjang' => 'SMA',
        ]));
    }

    /** Format bisa disetel, dan token yang tak dikenal ditolak dengan sebabnya. */
    public function test_format_bisa_disetel_dan_divalidasi(): void
    {
        $svc = new NisService;

        $svc->simpanFormat('{JENJANG}-{TA2}-{URUT4}');
        $this->assertSame('SMP-26-0001', $svc->contoh());

        // Tanpa {URUT} semua santri satu angkatan akan bernomor sama.
        try {
            $svc->simpanFormat('{TA4}{TINGKAT2}');
            $this->fail('format tanpa URUT seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('{URUT', $e->getMessage());
        }

        try {
            $svc->simpanFormat('{TA4}{KELAS}{URUT3}');
            $this->fail('token tak dikenal seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('{KELAS}', $e->getMessage());
        }
    }

    /** Daftar ulang TIDAK lagi menerbitkan NIS — itu tugas Generate NIS. */
    public function test_daftar_ulang_tidak_menerbitkan_nis(): void
    {
        $s = $this->santri('Ahmad', 'SMP', 7);
        $this->assertNull($s->nis);

        $this->assertCount(1, (new NisService)->pratinjau());
    }

    /** Urutan mengikuti ABJAD nama, bukan urutan pendaftaran. */
    public function test_urutan_mengikuti_abjad(): void
    {
        $this->santri('Zulkifli', 'SMP', 7);
        $this->santri('Ahmad', 'SMP', 7);
        $this->santri('Mahmud', 'SMP', 7);

        $daftar = (new NisService)->pratinjau();

        $this->assertSame(['Ahmad', 'Mahmud', 'Zulkifli'], array_column($daftar, 'nama'));
        $this->assertSame(['262707001', '262707002', '262707003'], array_column($daftar, 'nis'));
    }

    /** Tingkat ikut masuk NIS, dan urutannya terpisah per jenjang. */
    public function test_urutan_terpisah_per_jenjang(): void
    {
        $this->santri('Ahmad', 'SDTQ', 1);
        $this->santri('Budi', 'SMP', 7);
        $this->santri('Citra', 'SMA', 10);

        $nis = collect((new NisService)->pratinjau())->pluck('nis', 'nama')->all();

        // Ketiganya bernomor urut 001 — deretnya sendiri-sendiri per jenjang.
        $this->assertSame('262701001', $nis['Ahmad']);
        $this->assertSame('262707001', $nis['Budi']);
        $this->assertSame('262710001', $nis['Citra']);
    }

    /** Penyusul MELANJUTKAN nomor, walau abjadnya jadi tak berurutan. */
    public function test_penyusul_melanjutkan_nomor(): void
    {
        $svc = new NisService;
        $this->santri('Budi', 'SMP', 7);
        $this->santri('Citra', 'SMP', 7);
        $svc->terbitkan(Santri::pluck('id')->all(), $this->admin);

        // Masuk belakangan, namanya paling awal secara abjad.
        $abdul = $this->santri('Abdul', 'SMP', 7);

        $daftar = $svc->pratinjau();
        $this->assertCount(1, $daftar);
        $this->assertSame('Abdul', $daftar[0]['nama']);
        $this->assertSame('262707003', $daftar[0]['nis'], 'penyusul melanjutkan, tidak menyisip di 001');

        $svc->terbitkan([$abdul->id], $this->admin);
        $this->assertSame('262707003', $abdul->refresh()->nis);
    }

    /** Terbit = santri.nis terisi + satu baris riwayat berlaku. */
    public function test_terbitkan_menyimpan_riwayat(): void
    {
        $s = $this->santri('Ahmad', 'SMP', 7);

        (new NisService)->terbitkan([$s->id], $this->admin);

        $this->assertSame('262707001', $s->refresh()->nis);
        $riwayat = NisSantri::where('id_santri', $s->id)->sole();
        $this->assertSame('262707001', $riwayat->nis);
        $this->assertTrue($riwayat->berlaku);
        $this->assertSame('SMP', $riwayat->kode_jenjang);
        $this->assertSame(7, $riwayat->tingkat);

        // Sudah ber-NIS untuk jenjangnya → tak lagi ditawarkan.
        $this->assertSame([], (new NisService)->pratinjau());
    }

    /**
     * NAIK JENJANG → NIS baru, yang lama TETAP tersimpan.
     *
     * Inilah alasan riwayat NIS ada: kartu & rapor lama menunjuk nomor yang
     * sudah tak berlaku, dan itu satu-satunya pegangan wali di meja administrasi.
     */
    public function test_naik_jenjang_menerbitkan_nis_baru_dan_menyimpan_yang_lama(): void
    {
        $svc = new NisService;
        $s = $this->santri('Ahmad', 'SMP', 7);
        $svc->terbitkan([$s->id], $this->admin);
        $this->assertSame('262707001', $s->refresh()->nis);

        // Naik ke SMA tingkat 10 pada T.A berikutnya.
        TahunAjaran::create(['kode' => '2029/2030', 'status' => 'aktif']);
        $s->update(['kode_jenjang' => 'SMA', 'tingkat' => 10, 'tahun_ajaran_berjalan' => '2029/2030']);
        RiwayatTingkat::create(['id_santri' => $s->id, 'tahun_ajaran' => '2029/2030',
            'kode_jenjang' => 'SMA', 'tingkat' => 10]);

        $daftar = $svc->pratinjau();
        $this->assertCount(1, $daftar);
        $this->assertSame('293010001', $daftar[0]['nis']);
        $this->assertSame('262707001', $daftar[0]['nis_lama']);

        $svc->terbitkan([$s->id], $this->admin);

        $this->assertSame('293010001', $s->refresh()->nis);
        $this->assertSame(2, NisSantri::where('id_santri', $s->id)->count());
        $this->assertSame('293010001', NisSantri::where('id_santri', $s->id)->where('berlaku', true)->sole()->nis);
        $this->assertSame('262707001', NisSantri::where('id_santri', $s->id)->where('berlaku', false)->sole()->nis);
    }

    /** NIS memakai tingkat saat MASUK jenjang, bukan tingkat sekarang. */
    public function test_nis_memakai_tingkat_saat_masuk_jenjang(): void
    {
        $s = $this->santri('Ahmad', 'SMA', 10);
        // Sudah naik ke tingkat 12, tetapi masuk SMA-nya di tingkat 10.
        $s->update(['tingkat' => 12]);

        $this->assertSame('262710001', (new NisService)->pratinjau()[0]['nis']);
    }

    /** Pencarian di daftar santri ikut menelusuri NIS LAMA. */
    public function test_pencarian_menemukan_nis_lama(): void
    {
        $svc = new NisService;
        $s = $this->santri('Ahmad', 'SMP', 7);
        $svc->terbitkan([$s->id], $this->admin);

        TahunAjaran::create(['kode' => '2029/2030', 'status' => 'aktif']);
        $s->update(['kode_jenjang' => 'SMA', 'tingkat' => 10, 'tahun_ajaran_berjalan' => '2029/2030']);
        RiwayatTingkat::create(['id_santri' => $s->id, 'tahun_ajaran' => '2029/2030',
            'kode_jenjang' => 'SMA', 'tingkat' => 10]);
        $svc->terbitkan([$s->id], $this->admin);

        // Dicari dengan nomor LAMA — yang tercetak di kartu wali.
        $this->actingAs(User::find($this->admin))
            ->get(route('santri.aktif', ['q' => '262707001']))->assertOk()->assertSee('Ahmad');
    }

    /** Alur HTTP: layarnya tampil & penerbitannya tersimpan. */
    public function test_alur_http_generate_nis(): void
    {
        $s = $this->santri('Ahmad', 'SMP', 7);
        $admin = User::find($this->admin);

        $this->actingAs($admin)->get(route('nis.index'))->assertOk()
            ->assertSee('Generate NIS')
            ->assertSee('262707001')
            ->assertSee('{TA4}{TINGKAT2}{URUT3}');

        $this->actingAs($admin)->post(route('nis.terbitkan'), ['id_santri' => [$s->id]])->assertRedirect();
        $this->assertSame('262707001', $s->refresh()->nis);
    }
}
