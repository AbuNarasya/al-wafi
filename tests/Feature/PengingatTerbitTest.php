<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JadwalPengingatTerbit;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Notification;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\BatchTagihanService;
use App\Services\Modules\NotificationService;
use App\Services\Modules\PengingatTerbitService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BATCH TAGIHAN — tahap 3: pengingat menyusun draft.
 *
 * Yang dijaga di sini terutama REDANYA. Pengingat yang tak pernah padam akan
 * diabaikan dalam sebulan, dan pengingat yang padam sendiri saat pekerjaannya
 * belum dikerjakan tak ada gunanya sama sekali.
 */
class PengingatTerbitTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPT';

    private const PIUTANG = '1.ZZPT.1';

    private const PENDAPATAN = '4.ZZPT.1';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();
        Akses::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        // Bukan admin: penerimanya harus benar-benar ditentukan hak modul,
        // sebab admin ikut lewat jalur lain dan akan menyamarkan kekeliruan.
        $this->petugas = User::create([
            'username' => 'zzpt_petugas', 'nama' => 'Petugas', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        foreach ([['batch-tagihan', true], ['tagihan-lain', true]] as [$modul, $buat]) {
            HakAksesModul::create(['id_pengguna' => $this->petugas->id_pengguna, 'kode_modul' => $modul,
                'lihat' => true, 'buat' => $buat, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        }
        Akses::lupakan();

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZPTU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Pengingat Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'LDR', 'nama' => 'Laundry', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZPTU', 'status' => 'aktif', 'pengakuan' => 'akrual', 'cara_tagih' => 'kepesertaan',
        ]);
    }

    private function jadwal(array $ubah = []): JadwalPengingatTerbit
    {
        return JadwalPengingatTerbit::create(array_merge([
            'modul' => 'tagihan_lain', 'kode_jenis' => 'LDR',
            'judul' => 'Tagihan laundry bulanan', 'catatan' => 'Periksa timbangannya dulu.',
            'irama' => 'bulanan', 'tanggal' => 28, 'hari_sebelum' => 0, 'aktif' => true,
        ], $ubah));
    }

    private function santri(string $nis): Santri
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '08'.$nis,
            'nama' => 'Ayah', 'telepon' => '08'.$nis, 'status' => 'aktif',
        ]);

        return Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => "Santri {$nis}",
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    // ---- Kalender ----

    public function test_terkirim_tepat_pada_tanggalnya_dan_tidak_pada_hari_lain(): void
    {
        $this->jadwal();
        $svc = new PengingatTerbitService;

        $this->assertSame(0, $svc->kirim(Carbon::parse('2026-09-27'))['terkirim']);
        $this->assertSame(1, $svc->kirim(Carbon::parse('2026-09-28'))['terkirim']);
    }

    public function test_hari_sebelum_melempar_tepukan_ke_bulan_sebelumnya(): void
    {
        // Tanggal 2 tiap bulan, ditepuk 3 hari sebelumnya → 30 Agustus untuk
        // periode September. Kalau kandidat periodenya tak menengok ke depan,
        // pengingat ini tak akan pernah terkirim sama sekali.
        $this->jadwal(['tanggal' => 2, 'hari_sebelum' => 3]);

        $hasil = (new PengingatTerbitService)->kirim(Carbon::parse('2026-08-30'));

        $this->assertSame(1, $hasil['terkirim']);
        $this->assertSame('2026-09', $hasil['jadwal'][0]['periode']);
    }

    public function test_tanggal_31_jatuh_ke_hari_terakhir_di_bulan_yang_lebih_pendek(): void
    {
        $this->jadwal(['tanggal' => 31]);

        // September hanya 30 hari — tanpa penjagaan, pengingatnya hilang diam-diam.
        $this->assertSame(1, (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-30'))['terkirim']);
    }

    public function test_hari_terakhir_bulan_mengikuti_panjang_bulannya(): void
    {
        $this->jadwal(['tanggal' => JadwalPengingatTerbit::AKHIR_BULAN]);
        $svc = new PengingatTerbitService;

        $this->assertSame(1, $svc->kirim(Carbon::parse('2026-02-28'))['terkirim'], 'Februari 2026 berakhir tanggal 28.');
        $this->assertSame(1, $svc->kirim(Carbon::parse('2026-03-31'))['terkirim']);
    }

    public function test_jadwal_nonaktif_tidak_berbunyi(): void
    {
        $this->jadwal(['aktif' => false]);

        $this->assertSame(0, (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'))['terkirim']);
    }

    // ---- Penerima & pengulangan ----

    public function test_dikirim_ke_pemegang_hak_modul_penerbitnya(): void
    {
        $this->jadwal();
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));

        $n = Notification::where('jenis', PengingatTerbitService::JENIS_NOTIF)->get();
        $this->assertCount(1, $n);
        $this->assertSame($this->petugas->id_pengguna, $n->first()->id_pengguna);
        $this->assertSame('Tagihan laundry bulanan', $n->first()->judul);
        $this->assertStringContainsString('Periksa timbangannya dulu.', $n->first()->pesan);
    }

    public function test_dijalankan_dua_kali_tidak_melipatgandakan_notifikasi(): void
    {
        $this->jadwal();
        $svc = new PengingatTerbitService;

        $svc->kirim(Carbon::parse('2026-09-28'));
        $svc->kirim(Carbon::parse('2026-09-28'));

        $this->assertSame(1, Notification::where('jenis', PengingatTerbitService::JENIS_NOTIF)->count());
    }

    // ---- Redanya ----

    public function test_tugas_tetap_menyala_selama_belum_dikerjakan(): void
    {
        $jadwal = $this->jadwal();
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));

        $feed = (new NotificationService)->feed($this->petugas->id_pengguna);

        $this->assertCount(1, $feed['tugas'], 'Pengingat harus muncul sebagai TUGAS, bukan kabar.');
        unset($jadwal);
    }

    public function test_menandai_dibaca_tidak_memadamkan_tugasnya(): void
    {
        $this->jadwal();
        $svc = new NotificationService;
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));

        // Inti permintaan user: tak bisa didiamkan begitu saja.
        Notification::where('jenis', PengingatTerbitService::JENIS_NOTIF)->update(['dibaca' => true]);

        // Tugas yang sudah dibaca memang tak ditampilkan lagi, TAPI perapian
        // tak boleh menganggapnya selesai — begitu dibuka ulang, ia harus
        // kembali terhitung sebagai pekerjaan yang belum dilakukan.
        Notification::where('jenis', PengingatTerbitService::JENIS_NOTIF)->update(['dibaca' => false]);
        $this->assertCount(1, $svc->feed($this->petugas->id_pengguna)['tugas']);
    }

    public function test_konfirmasi_petugas_memadamkan_tugasnya(): void
    {
        $jadwal = $this->jadwal();
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));

        (new PengingatTerbitService)->konfirmasi($jadwal->id, '2026-09', $this->petugas->id_pengguna);

        $this->assertCount(0, (new NotificationService)->feed($this->petugas->id_pengguna)['tugas']);
    }

    public function test_batch_yang_sudah_diotorisasi_ikut_memadamkan_tanpa_perlu_konfirmasi(): void
    {
        $jadwal = $this->jadwal();
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));
        $this->assertCount(1, (new NotificationService)->feed($this->petugas->id_pengguna)['tugas']);

        // Petugas mengerjakannya lewat layar, tanpa menekan "sudah saya kerjakan".
        $s = $this->santri('330001');
        $svc = new BatchTagihanService;
        $batch = $svc->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'LDR', 'sumber' => 'manual', 'id_santri' => [$s->id],
                'nominal' => '50000', 'periode' => '2026-09'],
        ], $this->petugas->id_pengguna);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        $this->assertCount(0, (new NotificationService)->feed($this->petugas->id_pengguna)['tugas'],
            'Tak perlu mengonfirmasi dua kali hal yang sama.');
        unset($jadwal);
    }

    public function test_batch_periode_lain_tidak_memadamkan_pengingat_periode_ini(): void
    {
        $this->jadwal();
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));

        $s = $this->santri('330002');
        $svc = new BatchTagihanService;
        $batch = $svc->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'LDR', 'sumber' => 'manual', 'id_santri' => [$s->id],
                'nominal' => '50000', 'periode' => '2026-08'],
        ], $this->petugas->id_pengguna);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        $this->assertCount(1, (new NotificationService)->feed($this->petugas->id_pengguna)['tugas']);
    }

    public function test_draft_yang_belum_diotorisasi_belum_dianggap_dikerjakan(): void
    {
        $this->jadwal();
        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));

        $s = $this->santri('330003');
        (new BatchTagihanService)->susun([
            'modul' => 'tagihan_lain',
            'parameter' => ['kode_jenis' => 'LDR', 'sumber' => 'manual', 'id_santri' => [$s->id],
                'nominal' => '50000', 'periode' => '2026-09'],
        ], $this->petugas->id_pengguna);

        $this->assertCount(1, (new NotificationService)->feed($this->petugas->id_pengguna)['tugas'],
            'Draft yang belum diotorisasi belum tentu jadi — pekerjaannya belum selesai.');
    }

    public function test_sudah_dikerjakan_sebelum_ditepuk_maka_tak_jadi_ditepuk(): void
    {
        $jadwal = $this->jadwal();
        (new PengingatTerbitService)->konfirmasi($jadwal->id, '2026-09', $this->petugas->id_pengguna);

        $this->assertSame(0, (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'))['terkirim'],
            'Jangan menagih pekerjaan yang sudah selesai.');
    }

    // ---- Lewat HTTP ----

    public function test_tombol_sudah_saya_kerjakan_memadamkan_untuk_semua_penerima(): void
    {
        $jadwal = $this->jadwal();
        $rekan = User::create([
            'username' => 'zzpt_rekan', 'nama' => 'Rekan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        HakAksesModul::create(['id_pengguna' => $rekan->id_pengguna, 'kode_modul' => 'tagihan-lain',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        (new PengingatTerbitService)->kirim(Carbon::parse('2026-09-28'));
        $this->assertSame(2, Notification::where('jenis', PengingatTerbitService::JENIS_NOTIF)->count());

        $this->actingAs($this->petugas)->post(route('pengingat_terbit.konfirmasi'), [
            'id_jadwal' => $jadwal->id, 'periode' => '2026-09',
        ])->assertRedirect();

        // Satu orang mengerjakannya ⇒ yang lain tak perlu lagi ditagih.
        $this->assertCount(0, (new NotificationService)->feed($rekan->id_pengguna)['tugas']);
    }

    public function test_jadwal_bisa_dibuat_lewat_layar(): void
    {
        $this->actingAs($this->petugas)->post(route('pengingat_terbit.store'), [
            'modul' => 'tagihan_lain', 'kode_jenis' => 'LDR',
            'judul' => 'Laundry tiap akhir bulan', 'irama' => 'bulanan',
            'tanggal' => 0, 'hari_sebelum' => 2, 'aktif' => 1,
        ])->assertRedirect(route('pengingat_terbit.index'));

        $j = JadwalPengingatTerbit::firstOrFail();
        $this->assertSame(JadwalPengingatTerbit::AKHIR_BULAN, $j->tanggal);
        $this->assertSame(2, $j->hari_sebelum);
        $this->assertTrue($j->aktif);
    }

    public function test_pengingat_tagihan_lain_tanpa_jenis_biaya_ditolak(): void
    {
        $this->actingAs($this->petugas)->post(route('pengingat_terbit.store'), [
            'modul' => 'tagihan_lain', 'judul' => 'Tanpa jenis', 'irama' => 'bulanan', 'tanggal' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(0, JadwalPengingatTerbit::count());
    }
}
