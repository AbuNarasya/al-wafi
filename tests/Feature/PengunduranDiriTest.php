<?php

namespace Tests\Feature;

use App\Models\DompetSantri;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Kelas;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\PengajuanKeluar;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\PortalWali\PinTransaksi;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pengunduran diri santri aktif lewat portal wali → wali kelas → kepala sekolah → clearance → dikeluarkan (21 Okt 2026). */
class PengunduranDiriTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482915';

    private Wali $wali;

    private Santri $ahmad;

    /** @var array<string,User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        foreach (['walikelas' => [], 'kepsek' => [], 'keu' => ['clearance-keuangan' => ['buat']], 'asrama' => ['clearance-kesantrian' => ['buat']],
            'tu' => ['clearance-admin' => ['buat', 'ubah']], 'lain' => []] as $nama => $hak) {
            $this->u[$nama] = User::create(['username' => $nama, 'nama' => ucfirst($nama), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
            foreach ($hak + ['pengunduran-diri' => ['lihat']] as $modul => $aksi) {
                HakAksesModul::create(['id_pengguna' => $this->u[$nama]->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                    'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => false, 'menu' => true]);
            }
        }
        Akses::lupakan();
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3, 'id_kepala_sekolah' => $this->u['kepsek']->id_pengguna]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        $kelas = Kelas::create(['kode_jenjang' => 'J002', 'tingkat' => 1, 'nama' => '7A', 'peruntukan' => 'L', 'id_wali_kelas' => $this->u['walikelas']->id_pengguna]);
        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'LAMA-0001', 'nis' => '242507001', 'id_wali' => $this->wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'id_kelas' => $kelas->id, 'tahun_ajaran' => '2026/2027', 'tahun_ajaran_berjalan' => '2026/2027',
            'jalur' => '001', 'status' => 'aktif']);
        (new PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    private function surat(): UploadedFile
    {
        return UploadedFile::fake()->create('surat.pdf', 120, 'application/pdf');
    }

    public function test_alur_lengkap_sampai_santri_dikeluarkan(): void
    {
        DompetSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => 15000]); // titipan yang belum dikembalikan

        $this->sebagaiIbu()->get(route('portal.anak', $this->ahmad->id))->assertSee('Pengunduran diri santri');
        $this->sebagaiIbu()->post(route('portal.mundur.ajukan', $this->ahmad->id), ['alasan' => 'Pindah kota', 'surat' => $this->surat(), 'pin' => '000000'])
            ->assertSessionHas('error');
        $this->sebagaiIbu()->post(route('portal.mundur.ajukan', $this->ahmad->id), ['alasan' => 'Pindah kota', 'surat' => $this->surat(), 'pin' => self::PIN])
            ->assertRedirect(route('portal.mundur', $this->ahmad->id))->assertSessionHas('status');
        $p = PengajuanKeluar::sole();
        $this->assertSame(['menunggu_wali_kelas', 1], [$p->status, $p->dokumen()->count()]);
        $this->sebagaiIbu()->post(route('portal.mundur.ajukan', $this->ahmad->id), ['alasan' => 'lagi', 'surat' => $this->surat(), 'pin' => self::PIN])
            ->assertSessionHas('error'); // satu pengajuan berjalan per santri

        // Wali kelas: orang lain ditolak; kembalikan wajib bercatatan.
        $this->actingAs($this->u['lain'])->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'lanjut'])->assertSessionHas('error');
        $this->actingAs($this->u['walikelas'])->get(route('pengunduran_diri.index'))->assertSee($p->nomor)->assertSee('Perlu tindakan saya (1)');
        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'kembalikan'])->assertSessionHas('error');
        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'kembalikan', 'catatan' => 'Lampirkan surat bertanda tangan'])
            ->assertSessionHas('status');
        $this->assertSame('dikembalikan', $p->refresh()->status);
        $this->sebagaiIbu()->get(route('portal.mundur', $this->ahmad->id))->assertSee('Lampirkan surat bertanda tangan')->assertSee('Kirim ulang');
        $this->sebagaiIbu()->post(route('portal.mundur.ulang', $this->ahmad->id), ['alasan' => 'Pindah kota (ayah dinas)', 'surat' => $this->surat(), 'pin' => self::PIN])
            ->assertSessionHas('status');

        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'lanjut'])->assertSessionHas('status');
        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.putuskan', $p->id), ['keputusan' => 'ya'])->assertSessionHas('error');
        $this->actingAs($this->u['kepsek'])->post(route('pengunduran_diri.putuskan', $p->id), ['keputusan' => 'ya'])->assertSessionHas('status');
        $this->assertSame('clearance', $p->refresh()->status);

        // Clearance: keuangan butuh catatan karena masih ada titipan; admin butuh surat mutasi/SK dulu.
        $this->actingAs($this->u['asrama'])->post(route('pengunduran_diri.clearance', [$p->id, 'keuangan']))->assertSessionHas('error');
        $this->actingAs($this->u['keu'])->post(route('pengunduran_diri.clearance', [$p->id, 'keuangan']))->assertSessionHas('error');
        $this->actingAs($this->u['keu'])->post(route('pengunduran_diri.clearance', [$p->id, 'keuangan']), ['catatan' => 'Saldo Rp 15.000 disepakati ditransfer ke wali'])
            ->assertSessionHas('status');
        $this->actingAs($this->u['asrama'])->post(route('pengunduran_diri.clearance', [$p->id, 'kesantrian']))->assertSessionHas('status');
        $this->actingAs($this->u['tu'])->post(route('pengunduran_diri.clearance', [$p->id, 'admin']))->assertSessionHas('error');
        $this->actingAs($this->u['tu'])->post(route('pengunduran_diri.unggah', $p->id), ['jenis' => 'surat_mutasi', 'berkas' => $this->surat()])->assertSessionHas('status');
        $this->actingAs($this->u['tu'])->post(route('pengunduran_diri.clearance', [$p->id, 'admin']))->assertSessionHas('status');
        $this->assertSame('siap_dikeluarkan', $p->refresh()->status);

        // Akhir bulan: admin sekolah mengeluarkan, lalu menandai Dapodik.
        $this->actingAs($this->u['keu'])->post(route('pengunduran_diri.keluarkan'), ['pengajuan' => [$p->id]])->assertForbidden();
        $this->actingAs($this->u['tu'])->get(route('pengunduran_diri.index', ['tab' => 'siap']))->assertSee('Keluarkan santri');
        $this->actingAs($this->u['tu'])->post(route('pengunduran_diri.keluarkan'), ['pengajuan' => [$p->id]])->assertSessionHas('status', '1 santri dikeluarkan.');
        $this->assertSame(['selesai', 'keluar'], [$p->refresh()->status, $this->ahmad->refresh()->status]);
        $this->actingAs($this->u['tu'])->post(route('pengunduran_diri.dapodik', $p->id))->assertSessionHas('status');
        $this->assertNotNull($p->refresh()->dapodik_pada);

        // Wali: dikabari tiap tahap, dan bisa mengunduh surat mutasi.
        $this->assertGreaterThanOrEqual(5, NotifikasiWali::where('jenis', 'pengunduran_diri')->count());
        $mutasi = $p->dokumen()->where('jenis', 'surat_mutasi')->sole();
        $this->sebagaiIbu()->get(route('portal.mundur', $this->ahmad->id))->assertSee('Selesai')->assertSee('Surat mutasi');
        $this->sebagaiIbu()->get(route('portal.mundur.dokumen', $mutasi->id))->assertOk();
    }

    /**
     * Pemisahan tugas (audit 22 Okt 2026; keputusan user: posisi ganda boleh). Admin yang memverifikasi sebagai
     * wali kelas tak boleh juga memutuskan; orang yang di master data memang wali kelas DAN kepala sekolah boleh.
     */
    public function test_satu_orang_dua_tahap_hanya_bila_memang_berposisi_ganda(): void
    {
        $admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->fresh();
        $this->sebagaiIbu()->post(route('portal.mundur.ajukan', $this->ahmad->id), ['alasan' => 'Pindah kota', 'surat' => $this->surat(), 'pin' => self::PIN]);
        $p = PengajuanKeluar::sole();

        $this->actingAs($admin)->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'lanjut'])->assertSessionHas('status');
        $this->actingAs($admin)->post(route('pengunduran_diri.putuskan', $p->id), ['keputusan' => 'ya'])->assertSessionHas('error');
        $this->assertSame('menunggu_kepala_sekolah', $p->refresh()->status);

        // Posisi ganda yang sah: wali kelas 7A sekaligus kepala sekolah SMP.
        Jenjang::where('kode', 'J002')->update(['id_kepala_sekolah' => $this->u['walikelas']->id_pengguna]);
        $p->update(['status' => 'menunggu_wali_kelas', 'wali_kelas_oleh' => null]);
        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'lanjut'])->assertSessionHas('status');
        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.putuskan', $p->id), ['keputusan' => 'ya'])->assertSessionHas('status');
        $this->assertSame('clearance', $p->refresh()->status);
    }

    public function test_kepala_sekolah_menolak_dan_wali_bisa_membatalkan_sebelumnya(): void
    {
        $this->sebagaiIbu()->post(route('portal.mundur.ajukan', $this->ahmad->id), ['alasan' => 'Ingin pindah', 'surat' => $this->surat(), 'pin' => self::PIN]);
        $p = PengajuanKeluar::sole();
        $this->actingAs($this->u['walikelas'])->post(route('pengunduran_diri.verifikasi', $p->id), ['keputusan' => 'lanjut']);
        $this->actingAs($this->u['kepsek'])->post(route('pengunduran_diri.putuskan', $p->id), ['keputusan' => 'tidak'])->assertSessionHas('error');
        $this->actingAs($this->u['kepsek'])->post(route('pengunduran_diri.putuskan', $p->id), ['keputusan' => 'tidak', 'catatan' => 'Dibicarakan dulu dengan orang tua'])
            ->assertSessionHas('status');
        $this->assertSame(['ditolak', 'aktif'], [$p->refresh()->status, $this->ahmad->refresh()->status]);

        // Pengajuan baru boleh, lalu dibatalkan wali sendiri sebelum diputus.
        $this->sebagaiIbu()->post(route('portal.mundur.ajukan', $this->ahmad->id), ['alasan' => 'Ingin pindah lagi', 'surat' => $this->surat(), 'pin' => self::PIN])
            ->assertSessionHas('status');
        $this->sebagaiIbu()->post(route('portal.mundur.batal', $this->ahmad->id), ['pin' => self::PIN])->assertSessionHas('status');
        $this->assertSame('dibatalkan', PengajuanKeluar::latest('id')->first()->status);
    }
}
