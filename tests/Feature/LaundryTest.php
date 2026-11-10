<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\JenisPakaianLaundry;
use App\Models\Jenjang;
use App\Models\KartuSantri;
use App\Models\Laundry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laundry tahap 1 (26 Okt 2026) + paket 1 (3 Nov 2026): terima (tap kartu / cari manual, TIMBANG SAJA) → pemeriksaan
 * (timbang ulang + daftar pakaian wajib) → proses → selesai → serahkan (tap kartu + cocokkan pakaian). Kurang saat
 * diserahkan wajib bercatatan.
 */
class LaundryTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    private Santri $ahmad;

    private Santri $budi;

    private int $gamis;

    private int $handuk;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $this->petugas = User::create(['username' => 'lnd', 'nama' => 'Petugas Laundry', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        // Petugas laundry IKHWAN (santri uji di sini berjenis kelamin L) — 3 Nov 2026.
        HakAksesModul::create(['id_pengguna' => $this->petugas->id_pengguna, 'kode_modul' => 'laundry-ikhwan', 'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true]);
        Akses::lupakan();
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        $w = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu', 'telepon_ibu' => '0811', 'nama' => 'Ibu', 'telepon' => '0811', 'status' => 'aktif']);
        $buat = fn ($nis, $nama) => Santri::create(['no_pendaftaran' => "L-{$nis}", 'nis' => $nis, 'id_wali' => $w->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'status' => 'aktif']);
        $this->ahmad = $buat('242507001', 'Ahmad Fauzi');
        $this->budi = $buat('242507002', 'Budi Santoso');
        KartuSantri::create(['uid' => 'KARTU001', 'id_santri' => $this->ahmad->id, 'status' => 'aktif']);
        $this->gamis = JenisPakaianLaundry::where('nama', 'Gamis')->value('id');
        $this->handuk = JenisPakaianLaundry::where('nama', 'Handuk')->value('id');
    }

    private function terima(array $isi): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->petugas)->post(route('laundry.simpan_terima'), $isi + ['berat' => '3.4',
            'item' => [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 3], ['id_jenis_pakaian' => $this->handuk, 'jumlah' => 1]]]);
    }

    public function test_alur_lengkap_terima_proses_selesai_serahkan(): void
    {
        $this->actingAs($this->petugas)->get(route('laundry.terima', ['kartu' => 'SALAH']))->assertSee('Kartu tidak dikenal');
        $this->actingAs($this->petugas)->get(route('laundry.terima', ['kartu' => 'KARTU001']))->assertOk()->assertSee('AHMAD FAUZI');

        // Layar awal Terima (10 Nov 2026): kotak cari nama/NIS di ATAS dan langsung terbuka; tap kartu tetap fokus.
        $awal = $this->actingAs($this->petugas)->get(route('laundry.terima'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<details\s+open#', $awal);
        $this->assertStringContainsString('order-1 rounded-xl', $awal);
        $this->assertMatchesRegularExpression('#name="kartu" autofocus#', $awal);

        // Penerimaan cukup ditimbang (paket 1). Label kantong TIDAK lagi dicetak otomatis — tombol Cetak label (10 Nov 2026).
        $this->actingAs($this->petugas)->get(route('laundry.terima', ['kartu' => 'KARTU001']))->assertDontSee('+ pakaian');
        $this->actingAs($this->petugas)->post(route('laundry.simpan_terima'), ['kartu' => 'KARTU001', 'berat' => '3.4'])
            ->assertSessionHas('status')->assertSessionHas('cetak_label');
        $this->actingAs($this->petugas)->withSession(['cetak_label' => Laundry::sole()->id])->get(route('laundry.terima'))
            ->assertSee('Cetak label')->assertDontSee('<iframe', false);
        $l = Laundry::sole();
        $this->assertSame(['diterima', 'kartu', 0, '3.40'], [$l->status, $l->cara_terima, $l->jumlahPotong(), (string) $l->berat_terima]);
        $this->actingAs($this->petugas)->get(route('laundry.label', $l->id))->assertOk()->assertSee($l->nomor)->assertSee('data-qr="'.$l->nomor.'"', false);

        // Tak bisa dimajukan dari papan sebelum diperiksa.
        $this->actingAs($this->petugas)->post(route('laundry.majukan'), ['dari' => 'diterima', 'id' => [$l->id]])->assertSessionHasErrors('dari');

        // Meja pemeriksaan: pindai label → formulir; daftar pakaian wajib; berat ulang yang dihitung.
        $this->actingAs($this->petugas)->get(route('laundry.pemeriksaan', ['nomor' => strtolower($l->nomor)]))->assertRedirect(route('laundry.periksa', $l->id));
        $this->actingAs($this->petugas)->get(route('laundry.pemeriksaan', ['nomor' => 'LDI-0000-9999']))->assertSessionHas('error');
        // Tanpa pemindai / label tak tercetak: cari nama atau NIS santri (3 Nov 2026).
        $this->actingAs($this->petugas)->get(route('laundry.pemeriksaan', ['nomor' => 'ahmad']))->assertRedirect(route('laundry.periksa', $l->id));
        $this->actingAs($this->petugas)->get(route('laundry.pemeriksaan', ['nomor' => '242507001']))->assertRedirect(route('laundry.periksa', $l->id));
        $this->actingAs($this->petugas)->get(route('laundry.pemeriksaan', ['nomor' => 'Budi']))->assertSessionHas('error');
        $this->actingAs($this->petugas)->get(route('laundry.periksa', $l->id))->assertOk()->assertSee('<option value="Gamis">', false);
        $this->actingAs($this->petugas)->post(route('laundry.simpan_periksa', $l->id), ['berat' => '3.1', 'item' => [['id_jenis_pakaian' => '', 'jumlah' => '']]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'wajib'));
        $this->actingAs($this->petugas)->post(route('laundry.simpan_periksa', $l->id), ['berat' => '3.1', 'catatan' => 'Noda di gamis',
            'item' => [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 3], ['id_jenis_pakaian' => $this->handuk, 'jumlah' => 1]]])
            ->assertRedirect(route('laundry.pemeriksaan'));
        $l->refresh();
        $this->assertSame(['diperiksa', '3.10', '3.40', 4, 'Noda di gamis'], [$l->status, (string) $l->berat, (string) $l->berat_terima, $l->jumlahPotong(), $l->catatan]);
        $this->assertSame($this->petugas->id_pengguna, (int) $l->diperiksa_oleh);
        $this->actingAs($this->petugas)->post(route('laundry.simpan_periksa', $l->id), ['berat' => '3', 'item' => [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 1]]])
            ->assertSessionHas('error'); // sudah diperiksa
        $this->assertSame($this->petugas->id_pengguna, (int) $l->diterima_oleh);

        // Belum Selesai → tak bisa diserahkan.
        $this->actingAs($this->petugas)->get(route('laundry.serahkan', ['kartu' => 'KARTU001']))->assertSee('Tidak ada cucian yang siap diambil');

        $this->actingAs($this->petugas)->post(route('laundry.majukan'), ['dari' => 'diperiksa', 'id' => [$l->id]])->assertSessionHas('status');
        $this->actingAs($this->petugas)->post(route('laundry.majukan'), ['dari' => 'diperiksa', 'id' => [$l->id]])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 dilewati')); // sudah dimajukan petugas lain
        $this->actingAs($this->petugas)->post(route('laundry.majukan'), ['dari' => 'diproses', 'id' => [$l->id]]);
        $this->assertSame('selesai', $l->refresh()->status);
        $this->assertNotNull($l->diproses_pada);

        // Serahkan: handuk kurang 1 → catatan wajib.
        $handuk = $l->item->firstWhere('id_jenis_pakaian', $this->handuk);
        $this->actingAs($this->petugas)->get(route('laundry.serahkan', ['kartu' => 'KARTU001']))->assertSee($l->nomor)->assertSee('Handuk')
            ->assertSee('(tidak wajib)')->assertSee('>Cocok</button>', false); // cocokkan manual tanpa pemindai
        $this->actingAs($this->petugas)->post(route('laundry.simpan_serahkan'), ['kartu' => 'KARTU001', 'id' => [$l->id], 'kembali' => [$handuk->id => 0]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'kurang'));
        $this->assertSame('selesai', $l->refresh()->status, 'gagal = tak ada yang berubah');
        $this->actingAs($this->petugas)->post(route('laundry.simpan_serahkan'), ['kartu' => 'KARTU001', 'id' => [$l->id], 'kembali' => [$handuk->id => 0],
            'catatan_serah' => 'Handuk dicari dulu'])->assertSessionHas('status');
        $l->refresh();
        $this->assertSame(['diserahkan', 'kartu', true], [$l->status, $l->cara_serah, $l->ada_kurang]);
        $this->assertSame(0, (int) $handuk->refresh()->jumlah_kembali);
        $this->actingAs($this->petugas)->get(route('laundry.show', $l->id))->assertOk()->assertSee('kurang 1')->assertSee('Handuk dicari dulu');
    }

    public function test_kartu_tertinggal_cari_manual_dan_tak_bisa_menyerahkan_kantong_orang_lain(): void
    {
        $this->actingAs($this->petugas)->get(route('laundry.terima', ['cari' => 'Budi']))->assertSee('BUDI SANTOSO');
        $this->terima(['santri' => $this->budi->id])->assertSessionHas('status');
        $milikBudi = Laundry::sole();
        $this->assertSame('manual', $milikBudi->cara_terima);
        Laundry::whereKey($milikBudi->id)->update(['status' => 'selesai']);

        $this->actingAs($this->petugas)->post(route('laundry.simpan_serahkan'), ['kartu' => 'KARTU001', 'id' => [$milikBudi->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'bukan milik'));
        $this->assertSame('selesai', $milikBudi->refresh()->status);
    }

    public function test_batal_hanya_saat_diterima_dan_berat_wajar(): void
    {
        $this->terima(['kartu' => 'KARTU001', 'berat' => '0']);
        $this->assertSame(0, Laundry::count());
        $this->terima(['kartu' => 'KARTU001']);
        $l = Laundry::sole();
        $this->actingAs($this->petugas)->post(route('laundry.batal', $l->id), ['alasan' => 'Salah santri'])->assertSessionHas('status');
        $this->assertSame('dibatalkan', $l->refresh()->status);
        $this->actingAs($this->petugas)->post(route('laundry.batal', $l->id), ['alasan' => 'lagi'])->assertSessionHas('error');
    }

    public function test_papan_hak_akses_dan_instans_wafi_mart(): void
    {
        $this->terima(['kartu' => 'KARTU001']);
        $this->actingAs($this->petugas)->get(route('laundry.index'))->assertRedirect(route('laundry.papan', 'ikhwan'));
        $this->actingAs($this->petugas)->get(route('laundry.papan', 'ikhwan'))->assertOk()->assertSee('Papan Laundry Ikhwan')->assertSee('AHMAD FAUZI');
        $this->actingAs($this->petugas)->get(route('laundry.riwayat'))->assertOk()->assertSee('AHMAD FAUZI');
        $this->actingAs($this->petugas)->get(route('laundry.jenis'))->assertForbidden(); // master butuh hak sendiri
        $this->actingAs($this->petugas)->get(route('laundry.dashboard'))->assertForbidden(); // dashboard butuh hak sendiri

        $tamu = User::create(['username' => 'tamu', 'nama' => 'Tamu', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);
        $this->actingAs($tamu)->get(route('laundry.index'))->assertForbidden();

        config(['instans.kode' => 'wafimart']);
        Akses::lupakan();
        $this->assertFalse(\App\Support\Instans::modulHidup('laundry-ikhwan'), 'Wafi Mart tak punya santri — laundry mati di sana');
        $this->assertFalse(\App\Support\Instans::modulHidup('laundry-akhwat'));
    }

    public function test_petugas_ikhwan_tak_bisa_menyentuh_cucian_akhwat(): void
    {
        $w = Wali::first();
        $siti = Santri::create(['no_pendaftaran' => 'L-P1', 'nis' => '242507009', 'id_wali' => $w->id, 'nama' => 'Siti Aisyah', 'jenis_kelamin' => 'P',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'status' => 'aktif']);
        KartuSantri::create(['uid' => 'KARTUP01', 'id_santri' => $siti->id, 'status' => 'aktif']);
        $petugasAkhwat = User::create(['username' => 'lnda', 'nama' => 'Petugas Akhwat', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $petugasAkhwat->id_pengguna, 'kode_modul' => 'laundry-akhwat', 'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true]);
        Akses::lupakan();

        // Ikhwan menge-tap kartu akhwat → ditolak; namanya tak muncul di pencarian.
        $this->actingAs($this->petugas)->get(route('laundry.terima', ['kartu' => 'KARTUP01']))->assertSee('dilayani laundry akhwat');
        $this->actingAs($this->petugas)->get(route('laundry.terima', ['cari' => 'Siti']))->assertDontSee('SITI AISYAH');
        $this->terima(['kartu' => 'KARTUP01'])->assertSessionHas('error');
        $this->assertSame(0, Laundry::count());

        // Petugas akhwat menerimanya; petugas ikhwan tak melihat & tak bisa memajukan / membuka.
        Akses::lupakan();
        $this->actingAs($petugasAkhwat)->post(route('laundry.simpan_terima'), ['kartu' => 'KARTUP01', 'berat' => '2',
            'item' => [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 2]]])->assertSessionHas('status');
        $l = Laundry::sole();
        // Nomor per pusat penerimaan: akhwat LDA-, urutannya sendiri.
        $this->assertSame('LDA-'.now()->format('ym').'-0001', $l->nomor);
        Akses::lupakan();
        $this->terima(['kartu' => 'KARTU001'])->assertSessionHas('status');
        $this->assertSame('LDI-'.now()->format('ym').'-0001', Laundry::where('id_santri', $this->ahmad->id)->value('nomor'), 'ikhwan mulai dari 0001 sendiri');
        $this->actingAs($petugasAkhwat)->get(route('laundry.papan', 'akhwat'))->assertOk()->assertSee('SITI AISYAH');
        $this->actingAs($petugasAkhwat)->get(route('laundry.papan', 'ikhwan'))->assertForbidden();

        Akses::lupakan();
        $this->actingAs($this->petugas)->get(route('laundry.papan', 'akhwat'))->assertForbidden();
        $this->actingAs($this->petugas)->get(route('laundry.papan', 'ikhwan'))->assertDontSee('SITI AISYAH');
        $this->actingAs($this->petugas)->get(route('laundry.riwayat'))->assertDontSee('SITI AISYAH');
        $this->actingAs($this->petugas)->get(route('laundry.show', $l->id))->assertForbidden();
        $this->actingAs($this->petugas)->get(route('laundry.periksa', $l->id))->assertForbidden();
        $this->actingAs($this->petugas)->get(route('laundry.pemeriksaan', ['nomor' => $l->nomor]))->assertSessionHas('error');
        $this->actingAs($this->petugas)->post(route('laundry.simpan_periksa', $l->id), ['berat' => '2', 'item' => [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 2]]])->assertForbidden();
        $this->assertSame('diterima', $l->fresh()->status);
    }

    /** Paket 1 (3 Nov 2026): ubah data salah input — alasan wajib, jejak audit; batal sampai diperiksa. */
    public function test_ubah_data_dengan_alasan_dan_batal_sampai_diperiksa(): void
    {
        $this->terima(['kartu' => 'KARTU001']);
        $l = Laundry::sole();
        (new \App\Services\Modules\LaundryService)->periksa($l, '3.4', [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 3]], null, $this->petugas->id_pengguna);
        $this->actingAs($this->petugas)->get(route('laundry.show', $l->id))->assertOk()->assertSee('Ubah data')->assertSee('Cetak label kantong');

        $isi = ['berat' => '2.8', 'catatan' => 'Salah timbang', 'item' => [['id_jenis_pakaian' => $this->gamis, 'jumlah' => 2], ['id_jenis_pakaian' => $this->handuk, 'jumlah' => 1]]];
        $this->actingAs($this->petugas)->put(route('laundry.ubah', $l->id), $isi)->assertSessionHasErrors('alasan');
        $this->actingAs($this->petugas)->put(route('laundry.ubah', $l->id), ['item' => []] + $isi + ['alasan' => 'x'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'wajib')); // sudah diperiksa → pakaian wajib
        $this->actingAs($this->petugas)->put(route('laundry.ubah', $l->id), $isi + ['alasan' => 'Salah input petugas'])->assertSessionHas('status');
        $l->refresh();
        $this->assertSame(['2.80', 3, 'Salah timbang'], [(string) $l->berat, $l->jumlahPotong(), $l->catatan]);
        $jejak = \Illuminate\Support\Facades\DB::table('activity_log')->where('aksi', 'ubah_laundry')->sole();
        $this->assertStringContainsString('Salah input petugas', $jejak->detail);
        $this->assertStringContainsString('Gamis 3', $jejak->detail);
        $this->actingAs($this->petugas)->put(route('laundry.ubah', $l->id), $isi + ['alasan' => 'lagi'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Tidak ada data yang berubah'));

        // Batal masih boleh saat Diperiksa; sesudah dicuci tidak.
        $l->update(['status' => 'diproses']);
        $this->actingAs($this->petugas)->post(route('laundry.batal', $l->id), ['alasan' => 'x'])->assertSessionHas('error');
        $l->update(['status' => 'diperiksa']);
        $this->actingAs($this->petugas)->post(route('laundry.batal', $l->id), ['alasan' => 'Salah santri'])->assertSessionHas('status');
        $this->actingAs($this->petugas)->put(route('laundry.ubah', $l->id), $isi + ['alasan' => 'x'])->assertSessionHas('error');
    }
}
