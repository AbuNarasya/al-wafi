<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Kelas;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Impor\ImporSaldoAwal;
use App\Services\Impor\Pemeta\PemetaSantriLama;
use App\Services\Modules\KelasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KELAS / ROMBEL (21 Okt 2026): master, penempatan santri aktif, pengosongan saat kenaikan,
 * dan impor santri lama dari berkas sekolah ("7A - ABU BAKAR…", "Laki-laki", "29 April 2019").
 */
class KelasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
    }

    private function santri(string $nama, string $jk, string $status = 'aktif', int $tingkat = 1): Santri
    {
        static $n = 0;

        $wali = \App\Models\Wali::firstOrCreate(['telepon' => '0811'], ['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '0811', 'nama' => 'Ayah', 'status' => 'aktif']);

        return Santri::create(['no_pendaftaran' => 'ZZKL-'.(++$n), 'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => $jk, 'kode_jenjang' => 'J002',
            'tingkat' => $tingkat, 'tahun_ajaran' => '2026/2027', 'tahun_ajaran_berjalan' => '2026/2027', 'jalur' => '001', 'status' => $status]);
    }

    public function test_pembaca_isian_berkas_sekolah(): void
    {
        $this->assertSame(['tingkat' => 7, 'nama' => '7D - ALI BIN ABI THALIB'], KelasService::pecah('7D -  Ali bin Abi Thalib'));
        $this->assertSame(['tingkat' => 1, 'nama' => '1 (ABU BAKAR ASH SIDDIQ)'], KelasService::pecah('1 (ABU BAKAR ASH SIDDIQ)'));
        $this->assertSame(12, KelasService::pecah('12 SCIENCE')['tingkat']);
        $this->assertNull(KelasService::pecah('SEVILLA'));
        $this->assertSame(['tingkat' => 7, 'kelas' => null], PemetaSantriLama::tingkatKelas('7'));
        $this->assertSame(['L', 'P', 'L', null], array_map([PemetaSantriLama::class, 'jenisKelamin'], ['Laki-laki', 'PEREMPUAN', 'l', '']));
        $this->assertSame(['2019-04-29', '2012-08-27', '2011-12-26', '2011-05-17', null, null],
            array_map([PemetaSantriLama::class, 'tanggalNormal'], ['29 April 2019', '27/08/2012', '40903', '2011-05-17', '0', '31/02/2012']));
    }

    public function test_penempatan_menjaga_tingkat_jenis_kelamin_dan_status(): void
    {
        $svc = new KelasService;
        $putra = $svc->simpan(['kode_jenjang' => 'J002', 'tingkat' => 1, 'nama' => '1A - Abu Bakar', 'peruntukan' => 'L']);
        $putri = $svc->simpan(['kode_jenjang' => 'J002', 'tingkat' => 1, 'nama' => '1 Sevilla', 'peruntukan' => 'P']);
        $ahmad = $this->santri('Ahmad', 'L');
        $aisyah = $this->santri('Aisyah', 'P');
        $calon = $this->santri('Calon', 'L', 'calon');
        $kakak = $this->santri('Kakak', 'L', 'aktif', 2);

        $this->actingAs($this->admin)->get(route('kelas.penempatan', ['jenjang' => 'J002']))->assertOk()
            ->assertSee('AHMAD')->assertSee('AISYAH')->assertDontSee('CALON')->assertSee('belum berkelas');

        $this->actingAs($this->admin)->post(route('kelas.tempatkan'), ['santri' => [$ahmad->id], 'id_kelas' => $putra->id])
            ->assertSessionHas('status', '1 santri ditempatkan di kelas 1A - ABU BAKAR.');
        $this->assertSame($putra->id, $ahmad->refresh()->id_kelas);

        foreach ([[[$aisyah->id], $putra], [[$calon->id], $putra], [[$kakak->id], $putra], [[$ahmad->id, $aisyah->id], $putri]] as [$ids, $k]) {
            try {
                $svc->tempatkan($ids, $k->id);
                $this->fail('Harus ditolak.');
            } catch (AppException) {
            }
        }
        $this->assertSame($putra->id, $ahmad->refresh()->id_kelas, 'penolakan tak mengubah apa pun');

        // Kelas berpenghuni tak bisa dihapus / diubah peruntukannya ke jenis kelamin lain.
        $this->expectException(AppException::class);
        $svc->hapus($putra);
    }

    public function test_kenaikan_tingkat_mengosongkan_kelas(): void
    {
        $k = (new KelasService)->simpan(['kode_jenjang' => 'J002', 'tingkat' => 1, 'nama' => '1A', 'peruntukan' => 'C']);
        $s = $this->santri('Ahmad', 'L');
        (new KelasService)->tempatkan([$s->id], $k->id);

        $s->refresh()->update(['nama' => 'Ahmad Fauzi']);
        $this->assertSame($k->id, $s->refresh()->id_kelas, 'perubahan lain tak menyentuh kelas');

        $s->update(['tingkat' => 2, 'tahun_ajaran_berjalan' => '2027/2028']);
        $this->assertNull($s->refresh()->id_kelas);
        $this->actingAs($this->admin)->get(route('kelas.index', ['jenjang' => 'J002']))->assertOk()->assertSee('1 belum berkelas');
    }

    public function test_impor_santri_lama_dari_berkas_sekolah_membuat_kelas(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'kls').'.csv';
        file_put_contents($csv, implode("\n", [
            'NIS,NAMA,JENIS_KELAMIN,KODE_JENJANG,TINGKAT,TAHUN_AJARAN,JALUR,WALI_NAMA,WALI_TELEPON,TANGGAL_LAHIR',
            '242507017,ANNISA,Perempuan,SMP,1 SEVILLA,,,Wisnu,081200000001,27/08/2012',
            '242507018,ASHA,Perempuan,SMP,1 SEVILLA,,,Saifudin,081200000002,29 April 2012',
            '242507019,ABDUL,Laki-laki,SMP,1A - ABU BAKAR ASH SHIDDIQ,,,Salahudin,081200000003,',
            '242507020,FAUZAN,LAKI-LAKI,SMP,1A -  Abu Bakar Ash Shiddiq,,,Faisal,081200000004,',
        ]));
        $kunci = array_search(PemetaSantriLama::class, ImporSaldoAwal::daftar(), true);
        $param = ['tahun_ajaran_bawaan' => '2026/2027', 'jalur_bawaan' => '001'];

        $p = app(ImporSaldoAwal::class)->pratinjau($kunci, $csv, $param);
        $this->assertSame([4, 0], [$p['siap'], $p['masalah']], json_encode($p['baris_masalah']));
        app(ImporSaldoAwal::class)->jalankan($kunci, $csv, $param, $this->admin->id_pengguna);

        $this->assertSame(['1 SEVILLA' => 'P', '1A - ABU BAKAR ASH SHIDDIQ' => 'L'], Kelas::orderBy('nama')->pluck('peruntukan', 'nama')->all());
        $annisa = Santri::where('nis', '242507017')->first();
        $this->assertSame(['P', 1, '1 SEVILLA', '2012-08-27', '2026/2027', '001'],
            [$annisa->jenis_kelamin, (int) $annisa->tingkat, $annisa->kelas->nama, $annisa->tanggal_lahir?->format('Y-m-d') ?? (string) $annisa->tanggal_lahir, $annisa->tahun_ajaran, $annisa->jalur]);
        $this->assertSame(2, Kelas::where('nama', '1A - ABU BAKAR ASH SHIDDIQ')->first()->santri()->count());
        @unlink($csv);
    }
}
