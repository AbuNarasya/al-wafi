<?php

namespace Tests\Feature;

use App\Models\DokumenSantri;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Kelas;
use App\Models\Level;
use App\Models\PengajuanKeluar;
use App\Models\PermohonanBukaPeriode;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Support\Akses;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PERSETUJUAN SAYA TERPADU (keputusan user 12 Nov 2026): semua pekerjaan yang menunggu staf punya pintasan
 * di Persetujuan Saya + angka di menu modulnya, dan hanya yang benar-benar giliran pengguna itu.
 */
class PersetujuanSayaTerpaduTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,User> */
    private array $u = [];

    private Santri $ahmad;

    private PengajuanKeluar $p;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        foreach (['walikelas' => [], 'kepsek' => [], 'keu' => ['clearance-keuangan' => ['buat'], 'buka-periode' => ['buat', 'ubah']],
            'tu' => ['clearance-admin' => ['buat', 'ubah'], 'buka-periode' => ['buat', 'ubah'], 'dokumen-santri' => ['buat'], 'santri' => []], 'lain' => []] as $nama => $hak) {
            $this->u[$nama] = User::create(['username' => $nama, 'nama' => ucfirst($nama), 'password_hash' => 'x', 'kode_level' => 'L1',
                'is_admin' => false, 'status' => 'aktif'])->fresh();
            foreach ($hak + ['pengunduran-diri' => []] as $modul => $aksi) {
                HakAksesModul::create(['id_pengguna' => $this->u[$nama]->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                    'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => false, 'menu' => true]);
            }
        }
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3, 'id_kepala_sekolah' => $this->u['kepsek']->id_pengguna]);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        $kelas = Kelas::create(['kode_jenjang' => 'J002', 'tingkat' => 1, 'nama' => '7A', 'peruntukan' => 'L', 'id_wali_kelas' => $this->u['walikelas']->id_pengguna]);
        $wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'LAMA-0001', 'nis' => '242507001', 'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'id_kelas' => $kelas->id, 'jalur' => '001', 'status' => 'aktif']);
        $this->p = PengajuanKeluar::create(['nomor' => 'PKD-2611-0001', 'id_santri' => $this->ahmad->id, 'id_wali' => $wali->id,
            'diajukan_peran' => 'staf', 'alasan' => 'Pindah kota', 'status' => 'menunggu_wali_kelas']);
    }

    /** Angka untuk satu pengguna, dihitung ulang dari nol (memo per request dibuang). */
    private function tugas(string $siapa): array
    {
        $this->actingAs($this->u[$siapa]);
        Akses::lupakan();
        TugasSaya::lupakan();

        return ['hub' => TugasSaya::untukUrl('/approvals'), 'menu' => TugasSaya::untukUrl('/kesantrian/pengunduran-diri'),
            'total' => TugasSaya::total(), 'label' => array_column(TugasSaya::persetujuan(), 'label')];
    }

    public function test_pengunduran_diri_hanya_muncul_pada_gilirannya(): void
    {
        $this->assertSame(['hub' => 1, 'menu' => 1, 'total' => 1, 'label' => ['pengunduran diri menunggu verifikasi wali kelas']], $this->tugas('walikelas'));
        $this->assertSame(0, $this->tugas('kepsek')['hub']);
        $this->assertSame(0, $this->tugas('lain')['hub']);

        // Halaman Persetujuan Saya: kelompok Kesantrian + tautan ke tab "Perlu tindakan".
        TugasSaya::lupakan();
        $this->actingAs($this->u['walikelas'])->get(route('approvals.inbox'))->assertOk()
            ->assertSee('data-bidang="Kesantrian"', false)->assertSee('pengunduran diri menunggu verifikasi wali kelas')
            ->assertSee(url('/kesantrian/pengunduran-diri?tab=tindakan'), false);

        // Giliran kepala sekolah — yang tadi memverifikasi sebagai wali kelas tak ikut memutus.
        $this->p->update(['status' => 'menunggu_kepala_sekolah', 'wali_kelas_oleh' => $this->u['walikelas']->id_pengguna]);
        $this->assertSame(['hub' => 1, 'menu' => 1, 'total' => 1, 'label' => ['pengunduran diri menunggu keputusan kepala sekolah']], $this->tugas('kepsek'));
        $this->assertSame(0, $this->tugas('walikelas')['hub']);

        // Clearance: tiap pihak sampai bagiannya diberikan; pengeluaran baru setelah semuanya.
        $this->p->update(['status' => 'clearance']);
        $this->assertSame(['pengunduran diri menunggu clearance keuangan'], $this->tugas('keu')['label']);
        $this->assertSame(['pengunduran diri menunggu clearance admin sekolah'], $this->tugas('tu')['label']);
        $this->p->update(['keuangan_pada' => now(), 'keuangan_oleh' => $this->u['keu']->id_pengguna]);
        $this->assertSame(0, $this->tugas('keu')['hub']);
        $this->p->update(['status' => 'siap_dikeluarkan']);
        $this->assertSame(['santri siap dikeluarkan (pengunduran diri)'], $this->tugas('tu')['label']);
    }

    public function test_pemohon_tak_melihat_permohonannya_sendiri_dan_hub_tak_menggandakan_lonceng(): void
    {
        $this->p->update(['status' => 'selesai']);
        PermohonanBukaPeriode::create(['lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 10, 'alasan' => 'Koreksi jurnal',
            'status' => 'diajukan', 'diajukan_oleh' => $this->u['keu']->id_pengguna, 'diajukan_pada' => now()]);

        $this->assertSame(0, $this->tugas('keu')['hub']);
        $tu = $this->tugas('tu');
        $this->assertSame(['permohonan buka periode menunggu persetujuan'], $tu['label']);
        // Angka hub = jumlah pintasan, tetapi total (lonceng & ikon) tetap satu — tidak dihitung dua kali.
        $this->assertSame([1, 1], [$tu['hub'], $tu['total']]);
        $this->assertSame(1, TugasSaya::untukUrl('/period-close'));
    }

    public function test_berkas_wali_belum_diperiksa_punya_pintasan_dan_saringan(): void
    {
        $this->p->update(['status' => 'selesai']);
        DokumenSantri::create(['id_santri' => $this->ahmad->id, 'jenis' => 'hasil_medcheck', 'path' => 'x/a.pdf', 'nama_asli' => 'a.pdf',
            'mime' => 'application/pdf', 'ukuran' => 10, 'tahap' => 'pasca_lulus']);
        $lain = Santri::create(['no_pendaftaran' => 'LAMA-0002', 'id_wali' => $this->ahmad->id_wali, 'nama' => 'Budi', 'jenis_kelamin' => 'L', 'kode_jenjang' => 'J002',
            'tingkat' => 1, 'jalur' => '001', 'status' => 'aktif']);

        $this->assertSame(['santri aktif dengan berkas unggahan wali belum diperiksa'], $this->tugas('tu')['label']);
        $this->assertSame(1, TugasSaya::untukUrl('/kesantrian/santri'));

        $this->actingAs($this->u['tu'])->get(route('santri.aktif', ['berkas' => 'menunggu']))->assertOk()
            ->assertSee('AHMAD')->assertDontSee('BUDI');

        // Diputus staf → hilang dari tugas.
        (new \App\Services\Modules\DokumenSantriService)->putuskan($this->ahmad->id, 'hasil_medcheck', 'diterima', null, $this->u['tu']->id_pengguna);
        $this->assertSame(0, $this->tugas('tu')['hub']);
    }
}
