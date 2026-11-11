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
    use \Tests\Concerns\MembuatTarif;

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

    /** 22 Okt 2026: staf mengajukan atas nama wali dari halaman santri; tagihan PPSB ikut "tetap tertagih". */
    public function test_staf_mengajukan_atas_nama_wali_dan_seluruh_tagihan_tetap_tertagih(): void
    {
        \App\Models\BusinessUnit::create(['kode_unit' => 'ZZU', 'nama_unit' => 'Unit']);
        \App\Models\CoaGroup::create(['kode_grup' => 'ZZPD', 'nama_grup' => 'Uji']);
        \App\Models\CoaDetail::create(['kode_coa' => '4.ZZPD.UP', 'nama_coa' => 'Pend UP', 'kode_grup' => 'ZZPD', 'jenis_saldo' => 'kredit']);
        $this->buatBiaya(['kode' => 'UPZ', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_jenjang' => 'J002', 'kode_coa_pendapatan' => '4.ZZPD.UP', 'kode_unit' => 'ZZU']);
        \App\Models\TagihanSantri::create(['id_santri' => $this->ahmad->id, 'kode_jenis' => 'UPZ', 'perilaku' => 'uang_pangkal', 'kode_jenjang' => 'J002',
            'tahun_ajaran' => '2026/2027', 'nominal' => 3000000, 'sisa' => 3000000, 'status' => 'belum_bayar', 'jatuh_tempo' => now()->toDateString()]);

        $this->actingAs($this->u['lain'])->post(route('pengunduran_diri.ajukan', $this->ahmad->id), ['alasan' => 'Pindah', 'surat' => $this->surat()])
            ->assertForbidden(); // tanpa hak "buat"
        HakAksesModul::where('id_pengguna', $this->u['tu']->id_pengguna)->where('kode_modul', 'pengunduran-diri')->update(['buat' => true]);
        HakAksesModul::create(['id_pengguna' => $this->u['tu']->id_pengguna, 'kode_modul' => 'santri', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        $this->actingAs($this->u['tu'])->get(route('santri.show', $this->ahmad->id))->assertSee('Ajukan Pengunduran Diri');
        $this->actingAs($this->u['tu'])->post(route('pengunduran_diri.ajukan', $this->ahmad->id), ['alasan' => 'Pindah kota', 'surat' => $this->surat()])
            ->assertSessionHas('status');

        $p = PengajuanKeluar::sole();
        $this->assertSame(['menunggu_wali_kelas', 'staf', $this->wali->id], [$p->status, $p->diajukan_peran, (int) $p->id_wali]);
        $this->assertTrue(NotifikasiWali::where('jenis', 'pengunduran_diri')->exists(), 'wali dikabari');

        $keu = app(\App\Services\Modules\PengunduranDiriService::class)->keuangan($this->ahmad);
        $this->assertSame(['3000000.00', []], [$keu['tetap_total'], $keu['dibatalkan']]);
        $this->assertFalse($keu['bersih']);
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

    /** Pengajuan yang seluruh tahapnya sudah lewat — fixture berita acara (11 Nov 2026). */
    private function pengajuanTuntas(string $status = 'siap_dikeluarkan'): PengajuanKeluar
    {
        $p = new PengajuanKeluar;
        $p->forceFill(['nomor' => 'PKD-2611-0009', 'id_santri' => $this->ahmad->id, 'id_wali' => $this->wali->id, 'diajukan_peran' => 'ibu',
            'alasan' => 'Pindah kota', 'status' => $status]);
        $t = now()->subDays(3);
        foreach (['wali_kelas' => 'walikelas', 'kepala_sekolah' => 'kepsek', 'keuangan' => 'keu', 'kesantrian' => 'asrama', 'admin' => 'tu'] as $tahap => $u) {
            $p->forceFill(["{$tahap}_oleh" => $this->u[$u]->id_pengguna, "{$tahap}_pada" => $t->addHour()->copy(), "{$tahap}_catatan" => $tahap === 'keuangan' ? 'Tunggakan disepakati dicicil' : null]);
        }
        $p->save();

        return $p;
    }

    public function test_berita_acara_clearance_pdf_dan_cek_keaslian(): void
    {
        $p = $this->pengajuanTuntas('clearance');
        $this->actingAs($this->u['lain'])->get(route('pengunduran_diri.show', $p->id))->assertOk()->assertDontSee('Unduh Berita Acara');
        $this->actingAs($this->u['lain'])->from(route('pengunduran_diri.show', $p->id))->get(route('pengunduran_diri.berita_acara', $p->id))
            ->assertRedirect()->assertSessionHas('error', 'Berita acara baru tersedia setelah seluruh clearance selesai.');

        $p->update(['status' => 'siap_dikeluarkan']);
        $this->actingAs($this->u['lain'])->get(route('pengunduran_diri.show', $p->id))->assertSee('Unduh Berita Acara (PDF)');
        $pdf = $this->actingAs($this->u['lain'])->get(route('pengunduran_diri.berita_acara', $p->id))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Berita-Acara-Clearance-PKD-2611-0009.pdf', $pdf->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('activity_log', ['aksi' => 'unduh_berita_acara_clearance']);

        // Isi berita acara (dirender sebelum jadi PDF).
        $data = (new \App\Services\Modules\BeritaAcaraClearance)->data($p);
        $html = view('pengunduran-diri.berita-acara', $data)->render();
        foreach (['AHMAD', '242507001', 'Kepsek', 'Tunggakan disepakati dicicil', 'disahkan secara elektronik', $data['kode']] as $isi) {
            $this->assertStringContainsString($isi, $html);
        }
        $this->assertStringStartsWith('data:image/png;base64,', $data['qr']);

        // Cek keaslian (publik, tanpa login).
        $kode = \App\Services\Modules\BeritaAcaraClearance::kode($p);
        auth()->logout();
        $this->get(route('verifikasi_dokumen.clearance', ['nomor' => $p->nomor, 'kode' => $kode]))->assertOk()
            ->assertSee('Dokumen ASLI')->assertSee('AHMAD')->assertDontSee('Pindah kota')->assertDontSee('Tunggakan disepakati');
        $this->get(route('verifikasi_dokumen.clearance', ['nomor' => $p->nomor, 'kode' => 'AAAABBBBCCCC']))->assertOk()->assertSee('Tidak dapat diverifikasi');
        $this->post(route('verifikasi_dokumen.cari'), ['nomor' => strtolower($p->nomor), 'kode' => $data['kode']])
            ->assertRedirect(route('verifikasi_dokumen.clearance', ['nomor' => $p->nomor, 'kode' => $kode]));

        // Isi pokok berubah (tahap dicatat ulang) → kode lama tak berlaku.
        $p->update(['keuangan_pada' => now()]);
        $this->get(route('verifikasi_dokumen.clearance', ['nomor' => $p->nomor, 'kode' => $kode]))->assertSee('Tidak dapat diverifikasi');
    }

    public function test_riwayat_clearance_dan_dokumen_tampil_di_halaman_santri_keluar(): void
    {
        $p = $this->pengajuanTuntas('selesai');
        $p->update(['dikeluarkan_oleh' => $this->u['tu']->id_pengguna, 'dikeluarkan_pada' => now()]);
        $p->dokumen()->create(['jenis' => 'surat_mutasi', 'keterangan' => 'Mutasi ke SMP Negeri 1', 'nama_asli' => 'mutasi.pdf',
            'path' => 'x/mutasi.pdf', 'mime' => 'application/pdf', 'ukuran' => 100, 'diunggah_oleh' => $this->u['tu']->id_pengguna]);
        $this->ahmad->update(['status' => 'keluar']);
        HakAksesModul::create(['id_pengguna' => $this->u['lain']->id_pengguna, 'kode_modul' => 'santri', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        $this->actingAs($this->u['lain'])->get(route('santri.show', $this->ahmad->id))->assertOk()
            ->assertSee('Riwayat pengunduran diri & clearance', false)->assertSee($p->nomor)
            ->assertSee('Clearance keuangan (tagihan &amp; tunggakan)', false)->assertSee('Tunggakan disepakati dicicil')
            ->assertSee('Mutasi ke SMP Negeri 1')->assertSee('Unduh Berita Acara (PDF)');

        // Tanpa hak Pengunduran Diri: riwayatnya tak tampil.
        $admin = User::create(['username' => 'adm', 'nama' => 'Adm', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $admin->id_pengguna, 'kode_modul' => 'santri', 'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();
        $this->actingAs($admin)->get(route('santri.show', $this->ahmad->id))->assertOk()->assertDontSee('Riwayat pengunduran diri');
    }

    /** 11 Nov 2026: portal keluarga tertutup bila seluruh anak berakhir & tak ada lagi tagihan/saldo. */
    public function test_portal_wali_tertutup_bila_tak_ada_lagi_santri_kecuali_masih_ada_urusan(): void
    {
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk();

        $this->ahmad->update(['status' => 'keluar']);
        $this->assertFalse(\App\Services\PortalWali\AksesPortal::terbuka($this->wali));
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertRedirect(route('portal.masuk'))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'tidak ada lagi santri yang terdaftar'));

        // Masih ada saldo Dompet Wali → tetap terbuka sampai ditarik.
        \App\Models\DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => 5000]);
        $this->assertTrue(\App\Services\PortalWali\AksesPortal::terbuka($this->wali));
        \App\Models\DompetWali::where('id_wali', $this->wali->id)->update(['saldo' => 0]);
        \App\Models\TabunganSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => 1000]);
        $this->assertTrue(\App\Services\PortalWali\AksesPortal::terbuka($this->wali));
        \App\Models\TabunganSantri::where('id_santri', $this->ahmad->id)->update(['saldo' => 0]);
        $this->assertFalse(\App\Services\PortalWali\AksesPortal::terbuka($this->wali));

        // Adik didaftarkan sebagai calon → terbuka lagi dengan sendirinya.
        Santri::create(['no_pendaftaran' => 'PSB-0002', 'id_wali' => $this->wali->id, 'nama' => 'Fatimah', 'jenis_kelamin' => 'P',
            'kode_jenjang' => 'J002', 'tahun_ajaran' => '2026/2027', 'jalur' => '001', 'status' => 'calon']);
        $this->assertTrue(\App\Services\PortalWali\AksesPortal::terbuka($this->wali));
        $this->sebagaiIbu()->get(route('portal.beranda'))->assertOk();
    }

    public function test_masuk_dengan_password_keluarga_tertutup_diberi_tahu_alasannya(): void
    {
        $this->ahmad->update(['status' => 'alumni']);
        (new \App\Services\PortalWali\KataSandiWali)->simpan($this->wali, 'ibu', 'rahasia123', 'rahasia123');

        $this->post(route('portal.masuk.sandi'), ['email' => 'aminah@contoh.id', 'sandi' => 'salah-sekali'])->assertSessionHas('error', fn ($e) => str_contains($e, 'salah'));
        $this->post(route('portal.masuk.sandi'), ['email' => 'aminah@contoh.id', 'sandi' => 'rahasia123'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'tidak ada lagi santri yang terdaftar'));
        $this->assertGuest('wali');
    }
}
