<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JenisPakaianLaundry;
use App\Models\Jenjang;
use App\Models\Laundry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanLaundry;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\LaundryService;
use App\Services\Modules\TagihanLaundryService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * Laundry tahap 2 (26 Okt 2026): kuota per bulan kalender (bawaan 20 kg, bisa per jenjang, tak terbawa), draf
 * tagihan kelebihan tanggal 1, terbit otomatis tanggal 5 lewat Tagihan Lain-lain, bulan yang ditagih terkunci.
 */
class TagihanLaundryTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private User $keu;

    private Santri $smp;

    private Santri $sma;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();
        Carbon::setTestNow('2026-10-10 09:00:00');
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $this->keu = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach (['laundry-ikhwan', 'laundry-akhwat', 'laundry-tagihan', 'laundry-master', 'laundry-dashboard'] as $m) {
            HakAksesModul::create(['id_pengguna' => $this->keu->id_pengguna, 'kode_modul' => $m, 'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true]);
        }
        Akses::lupakan();
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        Jenjang::create(['kode' => 'J003', 'nama' => 'SMA', 'urutan' => 3, 'jumlah_tingkat' => 3]);
        \App\Models\TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZLD', 'nama_unit' => 'Laundry']);
        CoaGroup::create(['kode_grup' => 'ZZLD', 'nama_grup' => 'Laundry']);
        CoaDetail::create(['kode_coa' => '4.ZZLD.1', 'nama_coa' => 'Pendapatan Laundry', 'kode_grup' => 'ZZLD', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZLD.1', 'nama_coa' => 'Piutang Laundry', 'kode_grup' => 'ZZLD', 'jenis_saldo' => 'debet']);
        TipeBiaya::firstOrCreate(['kode' => 'lain'], ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        $this->buatBiaya(['kode' => 'LDR', 'nama' => 'Laundry', 'tipe' => 'lain', 'kode_coa_pendapatan' => '4.ZZLD.1', 'kode_coa_piutang' => '1.ZZLD.1', 'kode_unit' => 'ZZLD']);
        DB::table('pengaturan_laundry')->where('id', 1)->update(['tarif_per_kg' => 7000, 'kode_jenis_bawaan' => 'LDR', 'diubah_oleh' => $this->keu->id_pengguna]);
        DB::table('pengaturan_laundry_jenjang')->insert(['kode_jenjang' => 'J003', 'kuota_ikhwan_kg' => 15]); // SMA ikhwan: 15 kg

        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '0811', 'nama' => 'Ibu', 'telepon' => '0811', 'status' => 'aktif']);
        $buat = fn ($nis, $nama, $j) => Santri::create(['no_pendaftaran' => "L-{$nis}", 'nis' => $nis, 'id_wali' => $w->id, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => $j, 'tingkat' => 1, 'status' => 'aktif', 'tahun_ajaran' => '2026/2027']);
        $this->smp = $buat('1001', 'Ahmad', 'J002');
        $this->sma = $buat('1002', 'Budi', 'J003');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Setor + (bawaan) langsung diperiksa dengan berat yang sama — alur sebenarnya. Sejak audit 10 Nov 2026 santri
     * yang kantongnya belum diperiksa DITUNDA penagihannya, jadi fixture tak boleh berhenti di "diterima".
     */
    private function setor(Santri $s, string $kg, bool $periksa = true): Laundry
    {
        $svc = new LaundryService;
        $l = $svc->terima($s, $kg, [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]], null, 'kartu', $this->keu->id_pengguna);

        return $periksa ? $svc->periksa($l, $kg, [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]], null, $this->keu->id_pengguna) : $l;
    }

    public function test_santri_dengan_kantong_belum_diperiksa_ditunda_penagihannya(): void
    {
        $l = $this->setor($this->smp, '25', periksa: false);
        $this->setor($this->sma, '20');
        Carbon::setTestNow('2026-11-05 01:15:00');
        $h = (new TagihanLaundryService)->jalankanJadwal();
        $this->assertSame([1, 1], [$h['diterbitkan']['terbit'], $h['diterbitkan']['ditunda']]);
        $this->assertSame([$this->sma->id], TagihanSantri::pluck('id_santri')->all());

        // Diperiksa dengan berat ulang yang BERBEDA — tidak terkunci, lalu terbit esok harinya dengan berat final.
        (new LaundryService)->periksa($l, '24', [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]], null, $this->keu->id_pengguna);
        Carbon::setTestNow('2026-11-06 01:15:00');
        (new TagihanLaundryService)->jalankanJadwal();
        $this->assertSame('28000.00', (string) TagihanSantri::where('id_santri', $this->smp->id)->sole()->nominal);   // (24 − 20) × 7.000
    }

    public function test_kuota_per_jenjang_dan_perkiraan_saat_menimbang(): void
    {
        $this->setor($this->smp, '18');
        $svc = new TagihanLaundryService;
        $r = $svc->ringkas($this->smp);
        $this->assertSame(['20.00', '18.00', '2.00', '0.00'], [$r['kuota'], $r['terpakai'], $r['sisa'], $r['lebih']]);
        $r = $svc->ringkas($this->smp, null, 5);   // timbangan berikutnya 5 kg
        $this->assertSame(['3.00', '21000.00'], [$r['lebih'], $r['perkiraan']]);
        $this->assertSame('15.00', $svc->ringkas($this->sma)['kuota']);
        // Matriks (3 Nov 2026): sel akhwat SMA kosong → bawaan akhwat; bawaan akhwat boleh beda dari ikhwan.
        DB::table('pengaturan_laundry')->where('id', 1)->update(['kuota_akhwat_kg' => 25]);
        $this->assertSame(['25.00', '15.00', '20.00'], [$svc->kuota('J003', 'P'), $svc->kuota('J003', 'L'), $svc->kuota('J002', 'L')]);
        $this->sma->update(['jenis_kelamin' => 'P']);
        $this->assertSame('25.00', $svc->ringkas($this->sma->fresh())['kuota']);
        $this->sma->update(['jenis_kelamin' => 'L']);

        // Bulan berikutnya mulai dari nol — sisa kuota tak terbawa.
        Carbon::setTestNow('2026-11-02 08:00:00');
        $this->assertSame('0.00', $svc->ringkas($this->smp)['terpakai']);
    }

    public function test_draf_tanggal_1_terbit_otomatis_tanggal_5_sekali_saja(): void
    {
        $this->setor($this->smp, '12');
        $this->setor($this->smp, '11.5');   // 23,5 kg → lebih 3,5 kg
        $this->setor($this->sma, '14');     // di bawah kuota SMA 15 kg
        $svc = new TagihanLaundryService;

        Carbon::setTestNow('2026-11-01 01:15:00');
        $h = $svc->jalankanJadwal();
        $this->assertSame(['2026-10', 1, null], [$h['periode'], $h['disusun']['draf'], $h['diterbitkan']]);
        $draf = TagihanLaundry::sole();
        $this->assertSame(['draf', '23.50', '3.50', '24500.00'], [$draf->status, $draf->kg_total, $draf->kg_lebih, $draf->nominal]);
        $this->assertSame(0, TagihanSantri::count(), 'belum terbit sebelum tanggal 5');

        Carbon::setTestNow('2026-11-05 01:15:00');
        $svc->jalankanJadwal();
        $t = TagihanSantri::sole();
        $this->assertSame(['24500.00', '2026-10', $this->smp->id], [(string) $t->nominal, $t->periode, $t->id_santri]);
        $this->assertStringContainsString('Kelebihan kuota laundry', $t->keterangan);
        $this->assertSame(['terbit', $t->id], [$draf->refresh()->status, $draf->id_tagihan]);

        $svc->jalankanJadwal();   // diulang besok → tak ganda
        $this->assertSame(1, TagihanSantri::count());
    }

    public function test_kecualikan_dan_bulan_tertagih_terkunci(): void
    {
        $a = $this->setor($this->smp, '25');
        $this->setor($this->sma, '20');
        Carbon::setTestNow('2026-11-02 09:00:00');
        $svc = new TagihanLaundryService;
        $svc->susunDraf('2026-10');
        $smaDraf = TagihanLaundry::where('id_santri', $this->sma->id)->sole();

        $this->actingAs($this->keu)->post(route('laundry.tagihan.kecualikan', $smaDraf->id), ['alasan' => 'Seragam asrama'])->assertSessionHas('status');
        $this->actingAs($this->keu)->post(route('laundry.tagihan.terbitkan'), ['periode' => '2026-10'])->assertSessionHas('status');
        $this->assertSame([$this->smp->id], TagihanSantri::pluck('id_santri')->all(), 'yang dikecualikan tak ditagih');

        try {
            (new LaundryService)->ubah($a, '20', [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]], null, 'salah timbang', $this->keu->id_pengguna);
            $this->fail('bulan yang sudah ditagih harus terkunci');
        } catch (AppException $e) {
            $this->assertStringContainsString('terkunci', $e->getMessage());
        }
        $this->actingAs($this->keu)->get(route('laundry.show', $a->id))->assertSee('berat</b> terkunci', false);
    }

    public function test_koreksi_berat_sebelum_ditagih_masuk_hitungan_draf(): void
    {
        $a = $this->setor($this->smp, '25');
        $this->actingAs($this->keu)->put(route('laundry.ubah', $a->id), ['berat' => '2.5', 'alasan' => 'Salah ketik',
            'item' => [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]]])->assertSessionHas('status');
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->assertSame(0, (new TagihanLaundryService)->susunDraf('2026-10')['draf']);
        $this->assertTrue(DB::table('activity_log')->where('aksi', 'ubah_laundry')->exists());
    }

    public function test_bulan_berjalan_tak_bisa_ditagih_dan_layar_kuota_pengaturan(): void
    {
        $this->setor($this->smp, '22');
        $this->actingAs($this->keu)->post(route('laundry.tagihan.terbitkan'), ['periode' => '2026-10'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'bulan yang sudah lewat'));
        $this->actingAs($this->keu)->get(route('laundry.tagihan', ['periode' => '2026-10']))->assertOk()
            ->assertSee('perkiraan')->assertSee('melebihi kuota')->assertSee('AHMAD');

        $this->actingAs($this->keu)->put(route('laundry.pengaturan.simpan'), ['tarif_per_kg' => '8000', 'kuota_ikhwan_kg' => '18', 'kuota_akhwat_kg' => '25', 'kode_jenis_bawaan' => 'LDR',
            'hari_terbit' => 3, 'terbit_otomatis' => '1', 'jenjang' => ['J003' => ['kuota_ikhwan_kg' => '', 'kuota_akhwat_kg' => '22', 'kode_jenis' => '']]])->assertSessionHas('status');
        $p = (new TagihanLaundryService)->pengaturan();
        $this->assertSame(['8000.00', '18.00', '25.00', 3], [(string) $p->tarif_per_kg, (string) $p->kuota_ikhwan_kg, (string) $p->kuota_akhwat_kg, (int) $p->hari_terbit]);
        $this->assertSame('18.00', (new TagihanLaundryService)->kuota('J003', 'L'), 'sel ikhwan SMA dikosongkan → ikut bawaan ikhwan');
        $this->assertSame('22.00', (new TagihanLaundryService)->kuota('J003', 'P'));
        $this->actingAs($this->keu)->get(route('laundry.pengaturan'))->assertOk()->assertSee('name="jenjang[J003][kuota_akhwat_kg]"', false);
        $this->actingAs($this->keu)->get(route('laundry.terima', ['santri' => $this->smp->id]))->assertSee('Kuota Oktober');
    }

    public function test_dashboard_12_bulan_pemakaian_penyerahan_dan_tagihan(): void
    {
        $a = $this->setor($this->smp, '12', periksa: false);
        $this->setor($this->smp, '11.5');   // Oktober: Ahmad 23,5 kg → lebih 3,5 kg = Rp 24.500
        $this->setor($this->sma, '14');
        $svc = new LaundryService;
        $svc->periksa($a, (string) $a->berat, [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]], null, $this->keu->id_pengguna);
        $svc->majukan([$a->id], 'diperiksa', $this->keu->id_pengguna);
        $svc->majukan([$a->id], 'diproses', $this->keu->id_pengguna);
        $svc->serahkan($this->smp, [$a->id], [], null, 'kartu', $this->keu->id_pengguna);
        Carbon::setTestNow('2026-11-05 01:15:00');
        (new TagihanLaundryService)->jalankanJadwal();   // draf tgl 1 terlewat → disusun & terbit sekaligus

        $this->actingAs($this->keu)->get(route('laundry.dashboard', ['sampai' => '2026-10']))->assertOk()
            ->assertSee('Pemakaian 12 bulan')->assertSee('<svg', false)
            ->assertSee('37,5 kg')              // total Oktober
            ->assertSee('3,5')                  // kg kelebihan
            ->assertSee('Rp 24.500')            // tagihan terbit
            ->assertSee('1,0×')                 // 1 penyerahan / 1 santri
            ->assertSeeInOrder(['10 pemakai terbanyak', 'AHMAD', 'BUDI']);

        // Bagian akhwat: santri uji semuanya ikhwan → kosong.
        $this->actingAs($this->keu)->get(route('laundry.dashboard', ['sampai' => '2026-10', 'bagian' => 'akhwat']))->assertOk()
            ->assertDontSee('AHMAD')->assertSee('0,0 kg');
    }

    /** Paket 2 (3 Nov 2026): Monitor Kelebihan — pemakaian vs kuota, tagihan lunas/belum, tunggakan lewat jatuh tempo. */
    public function test_monitor_kelebihan_dan_kartu_dashboard_bisa_diklik(): void
    {
        $this->setor($this->smp, '23.5');   // Oktober: lebih 3,5 kg
        $this->setor($this->sma, '10');
        $this->actingAs($this->keu)->get(route('laundry.monitor'))->assertForbidden();
        HakAksesModul::create(['id_pengguna' => $this->keu->id_pengguna, 'kode_modul' => 'laundry-monitor', 'lihat' => true, 'menu' => true]);
        \App\Support\Akses::lupakan();

        $this->actingAs($this->keu)->get(route('laundry.monitor', ['periode' => '2026-10', 'saring' => 'over']))->assertOk()
            ->assertSee('AHMAD')->assertDontSee('BUDI')->assertSee('Rp 24.500');

        Carbon::setTestNow('2026-11-05 01:15:00');
        (new TagihanLaundryService)->jalankanJadwal();
        $this->actingAs($this->keu)->get(route('laundry.dashboard', ['sampai' => '2026-10']))->assertOk()
            ->assertSee(route('laundry.monitor', ['tampil' => 'tagihan', 'status' => 'belum']))->assertSee('rincian');
        $this->actingAs($this->keu)->get(route('laundry.monitor', ['tampil' => 'tagihan', 'status' => 'belum']))->assertOk()
            ->assertSee('AHMAD')->assertSee('belum dibayar');
        $this->actingAs($this->keu)->get(route('laundry.monitor', ['tampil' => 'tagihan', 'status' => 'lunas']))->assertOk()->assertDontSee('AHMAD');

        // Tunggakan hanya yang SUDAH lewat jatuh tempo (terbit + 14 hari).
        $mon = new \App\Services\Modules\MonitorLaundryService;
        $this->assertCount(0, $mon->tunggakan());
        Carbon::setTestNow('2026-11-25 08:00:00');
        $t = $mon->tunggakanSantri($this->smp);
        $this->assertSame([1, '24500.00', ['2026-10']], [$t->jumlah, $t->sisa, $t->periode]);
        $this->actingAs($this->keu)->get(route('laundry.monitor', ['tampil' => 'tunggakan']))->assertOk()->assertSee('AHMAD')->assertSee('Okt 2026');
    }

    /**
     * Paket 3 (3 Nov 2026): menunggak ≥ N periode LEWAT jatuh tempo → cucian bersih tertahan (setor tetap boleh);
     * izin mendesak oleh hak laundry-izin, alasan wajib, tercatat, boleh berkali-kali; wali dikabari & melihat penandanya.
     */
    public function test_penahanan_pengambilan_izin_mendesak_dan_penanda_portal(): void
    {
        $this->setor($this->smp, '23.5');
        Carbon::setTestNow('2026-11-05 01:15:00');
        (new TagihanLaundryService)->jalankanJadwal();
        Carbon::setTestNow('2026-11-25 08:00:00');
        $mon = new \App\Services\Modules\MonitorLaundryService;
        $this->assertNull($mon->tertahan($this->smp), 'pengaturan 0 = tak ada penahanan');
        DB::table('pengaturan_laundry')->where('id', 1)->update(['tahan_setelah_periode' => 2]);
        $this->assertNull($mon->tertahan($this->smp), 'baru 1 periode menunggak');
        DB::table('pengaturan_laundry')->where('id', 1)->update(['tahan_setelah_periode' => 1]);
        $this->assertNotNull($mon->tertahan($this->smp));

        $w = $this->smp->wali;
        $w->update(['email_ibu' => 'ibu@contoh.id']);

        // Setor tetap boleh; selesai → kabar "tertahan", bukan "siap diambil".
        $svc = new LaundryService;
        $l = $this->setor($this->smp, '3', periksa: false);
        $svc->periksa($l, '3', [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 5]], null, $this->keu->id_pengguna);
        $svc->majukan([$l->id], 'diperiksa', $this->keu->id_pengguna);
        $svc->majukan([$l->id], 'diproses', $this->keu->id_pengguna);
        $this->assertStringContainsString('tertahan', \App\Models\NotifikasiWali::where('jenis', 'laundry_tertahan')->sole()->judul);
        $this->assertSame(0, \App\Models\NotifikasiWali::where('jenis', 'laundry_selesai')->count());

        // Petugas tanpa hak izin: layar menolak, kiriman berizin 403, tanpa izin ditolak layanan.
        $this->actingAs($this->keu)->get(route('laundry.serahkan', ['santri' => $this->smp->id]))->assertOk()
            ->assertSee('Cucian TERTAHAN')->assertSee('Tertahan — tidak bisa diserahkan');
        $kirim = ['santri' => $this->smp->id, 'id' => [$l->id]];
        $this->actingAs($this->keu)->post(route('laundry.simpan_serahkan'), $kirim)->assertSessionHas('error', fn ($m) => str_contains($m, 'TERTAHAN'));
        $this->actingAs($this->keu)->post(route('laundry.simpan_serahkan'), $kirim + ['izin' => 'Pulang darurat'])->assertForbidden();
        $this->assertSame('selesai', $l->fresh()->status);

        HakAksesModul::create(['id_pengguna' => $this->keu->id_pengguna, 'kode_modul' => 'laundry-izin', 'lihat' => true, 'ubah' => true, 'menu' => true]);
        \App\Support\Akses::lupakan();
        $this->actingAs($this->keu)->get(route('laundry.serahkan', ['santri' => $this->smp->id]))->assertSee('Izinkan &amp; serahkan (mendesak)', false);
        $this->actingAs($this->keu)->post(route('laundry.simpan_serahkan'), $kirim + ['izin' => 'Pulang darurat'])->assertSessionHas('status');
        $this->assertSame('diserahkan', $l->fresh()->status);
        $jejak = DB::table('activity_log')->where('aksi', 'izin_laundry_tertahan')->sole();
        $this->assertStringContainsString('Pulang darurat', $jejak->detail);

        // Izin tak hanya sekali: kantong berikutnya bisa diizinkan lagi.
        $l2 = $this->setor($this->smp, '2', periksa: false);
        $svc->periksa($l2, '2', [['id_jenis_pakaian' => JenisPakaianLaundry::value('id'), 'jumlah' => 2]], null, $this->keu->id_pengguna);
        $svc->majukan([$l2->id], 'diperiksa', $this->keu->id_pengguna);
        $svc->majukan([$l2->id], 'diproses', $this->keu->id_pengguna);
        $svc->serahkan($this->smp, [$l2->id], [], null, 'manual', $this->keu->id_pengguna, 'Wali sudah transfer, menunggu verifikasi');
        $this->assertSame(2, DB::table('activity_log')->where('aksi', 'izin_laundry_tertahan')->count());

        // Portal wali: spanduk tertahan di halaman Laundry & penanda di Beranda.
        (new \App\Services\PortalWali\KataSandiWali)->simpan($w->fresh(), 'ibu', 'sandiUji12345', 'sandiUji12345');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->post(route('portal.masuk.sandi'), ['email' => 'ibu@contoh.id', 'sandi' => 'sandiUji12345']);
        $this->get(route('portal.beranda'))->assertOk()->assertSee('Cucian bersih tertahan');
        $this->get(route('portal.laundry'))->assertOk()->assertSee('Cucian bersih tertahan')->assertSee('Bayar tagihan');
    }

    public function test_saklar_terbit_otomatis_dari_layar_tagihan(): void
    {
        $svc = new TagihanLaundryService;
        $this->assertTrue((bool) $svc->pengaturan()->terbit_otomatis);
        $this->actingAs($this->keu)->get(route('laundry.tagihan'))->assertOk()->assertSee('NYALA')->assertSee('Matikan');

        $this->actingAs($this->keu)->post(route('laundry.tagihan.otomatis'), ['aktif' => 0])->assertSessionHas('status');
        $this->assertFalse((bool) $svc->pengaturan()->terbit_otomatis);
        $this->actingAs($this->keu)->get(route('laundry.tagihan'))->assertSee('MATI')->assertSee('Nyalakan');

        $this->actingAs($this->keu)->post(route('laundry.tagihan.otomatis'), ['aktif' => 1]);
        $this->assertTrue((bool) $svc->pengaturan()->terbit_otomatis);
    }
}
