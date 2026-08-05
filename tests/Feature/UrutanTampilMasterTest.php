<?php

namespace Tests\Feature;

use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\SumberInformasi;
use App\Models\TipeBiaya;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * URUTAN TAMPIL master yang diatur dengan menyeret baris (jenjang, tipe biaya,
 * sumber informasi, jalur pendaftaran).
 *
 * Yang dijaga di sini bukan gerakan seretnya — itu urusan peramban — melainkan
 * yang gampang rusak diam-diam: nomor urut selalu dirapikan 1..n, baris yang
 * tak ikut terkirim tak terlempar ke bawah, dan menyunting baris lewat form
 * tidak MENGEMBALIKAN urutannya (dulu semua form mengirim `urutan` sendiri).
 */
class UrutanTampilMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzur_adm', 'nama' => 'Admin Urut', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    private function buatJalur(): void
    {
        foreach ([['001', 'Reguler'], ['002', 'Pindahan'], ['003', 'Anak Karyawan']] as [$kode, $nama]) {
            JalurPendaftaran::create(['kode' => $kode, 'nama' => $nama, 'status' => 'aktif']);
        }
    }

    /**
     * Halaman master TIDAK lagi urut nama. Sebelumnya "Anak Karyawan" selalu di
     * baris pertama semata-mata karena abjad — itulah yang hendak diganti.
     */
    public function test_daftar_master_mengikuti_urutan_bukan_abjad(): void
    {
        $this->buatJalur();
        $this->actingAs($this->admin)->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['001', '003', '002']]);

        $urut = app(\App\Services\Modules\JalurPendaftaranService::class)->list()->pluck('kode')->all();
        $this->assertSame(['001', '003', '002'], $urut);
    }

    public function test_menyimpan_urutan_merapikan_nomor_jadi_1_sampai_n(): void
    {
        $this->buatJalur();
        // Nomor berlubang & kembar: keadaan yang mungkin terbawa dari isian
        // angka manual sebelum fitur ini ada.
        JalurPendaftaran::whereKey('001')->update(['urutan' => 9]);
        JalurPendaftaran::whereKey('002')->update(['urutan' => 9]);
        JalurPendaftaran::whereKey('003')->update(['urutan' => 0]);

        $this->actingAs($this->admin)
            ->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['002', '003', '001']])
            ->assertOk()->assertJsonPath('pesan', 'Urutan tersimpan.');

        $this->assertSame([2 => 1, 3 => 2, 1 => 3], [
            2 => JalurPendaftaran::find('002')->urutan,
            3 => JalurPendaftaran::find('003')->urutan,
            1 => JalurPendaftaran::find('001')->urutan,
        ]);
        $this->assertSame(['002', '003', '001'], JalurPendaftaran::orderBy('urutan')->pluck('kode')->all());
    }

    public function test_baris_yang_tak_terkirim_tetap_ada_dan_menyusul_di_bawah(): void
    {
        $this->buatJalur();

        $this->actingAs($this->admin)
            ->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['003', '002']])
            ->assertOk();

        // '001' tak ikut terkirim → tetap ada, diletakkan sesudah yang dikirim.
        $this->assertSame(['003', '002', '001'], JalurPendaftaran::orderBy('urutan')->pluck('kode')->all());
    }

    public function test_kode_asing_ditolak_dan_tak_ada_yang_berubah(): void
    {
        $this->buatJalur();
        $sebelum = JalurPendaftaran::orderBy('urutan')->pluck('kode')->all();

        $this->actingAs($this->admin)
            ->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['001', 'XXX']])
            ->assertStatus(422)->assertJsonPath('message', 'Baris tidak dikenal: XXX.');

        $this->assertSame($sebelum, JalurPendaftaran::orderBy('urutan')->pluck('kode')->all());
    }

    public function test_daftar_kosong_ditolak(): void
    {
        $this->buatJalur();

        $this->actingAs($this->admin)
            ->postJson(route('jalur_pendaftaran.urutan'), ['kode' => []])
            ->assertStatus(422);
    }

    public function test_tanpa_hak_ubah_tak_boleh_menyimpan_urutan(): void
    {
        $this->buatJalur();
        $petugas = User::create([
            'username' => 'zzur_ptg', 'nama' => 'Petugas', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'jalur-pendaftaran',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);

        $this->actingAs($petugas)
            ->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['003', '002', '001']])
            ->assertForbidden();

        $this->assertSame(['001', '002', '003'], JalurPendaftaran::orderBy('urutan')->pluck('kode')->all());
    }

    public function test_jalur_baru_masuk_paling_bawah(): void
    {
        $this->buatJalur();

        $this->actingAs($this->admin)->post(route('jalur_pendaftaran.store'), [
            'kode' => '009', 'nama' => 'Aa Paling Depan Kalau Diurut Nama', 'status' => 'aktif',
        ])->assertRedirect(route('jalur_pendaftaran.index'));

        $this->assertSame('009', JalurPendaftaran::orderByDesc('urutan')->first()->kode);
    }

    /**
     * Jebakan yang paling mudah terlewat: form sunting tak lagi punya isian
     * "Urutan Tampil". Kalau servicenya tetap membaca `urutan` dari data form,
     * menyunting nama saja akan membuat nomornya kembali 0 — dan barisnya
     * melompat ke atas tanpa ada yang menyentuh urutannya.
     */
    public function test_menyunting_baris_tidak_mengubah_urutannya(): void
    {
        $this->buatJalur();
        $this->actingAs($this->admin)->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['003', '002', '001']]);

        $this->actingAs($this->admin)->put(route('jalur_pendaftaran.update', '002'), [
            'nama' => 'Pindahan Luar', 'status' => 'aktif',
        ])->assertRedirect();

        $this->assertSame(2, JalurPendaftaran::find('002')->urutan);
        $this->assertSame(['003', '002', '001'], JalurPendaftaran::orderBy('urutan')->pluck('kode')->all());
    }

    public function test_urutan_jalur_dipakai_dropdown_dan_dashboard(): void
    {
        $this->buatJalur();
        $this->actingAs($this->admin)->postJson(route('jalur_pendaftaran.urutan'), ['kode' => ['003', '002', '001']]);

        $this->assertSame(['003', '002', '001'], array_keys(\App\Support\Referensi::jalur()));
        $this->assertSame(
            ['003', '002', '001'],
            array_keys(app(\App\Services\Modules\TarifService::class)->jalurBerlaku('2026/2027', 'SMP'))
        );
    }

    public function test_urutan_jenjang_tipe_biaya_dan_sumber_informasi(): void
    {
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 2]);
        $this->actingAs($this->admin)->postJson(route('jenjang.urutan'), ['kode' => ['SMA', 'SMP']])->assertOk();
        $this->assertSame(['SMA', 'SMP'], Jenjang::orderBy('urutan')->pluck('kode')->all());

        TipeBiaya::create(['kode' => 'ZA', 'nama' => 'A', 'perilaku' => 'lain', 'urutan' => 1, 'bawaan' => false, 'status' => 'aktif']);
        TipeBiaya::create(['kode' => 'ZB', 'nama' => 'B', 'perilaku' => 'lain', 'urutan' => 2, 'bawaan' => false, 'status' => 'aktif']);
        $this->actingAs($this->admin)->postJson(route('tipe_biaya.urutan'), ['kode' => ['ZB', 'ZA']])->assertOk();
        $this->assertSame(['ZB', 'ZA'], TipeBiaya::whereIn('kode', ['ZA', 'ZB'])->orderBy('urutan')->pluck('kode')->all());

        SumberInformasi::create(['kode' => 'ZS1', 'nama' => 'Brosur', 'urutan' => 1, 'bawaan' => false, 'butuh_keterangan' => false, 'status' => 'aktif']);
        SumberInformasi::create(['kode' => 'ZS2', 'nama' => 'Teman', 'urutan' => 2, 'bawaan' => false, 'butuh_keterangan' => false, 'status' => 'aktif']);
        $this->actingAs($this->admin)->postJson(route('sumber_informasi.urutan'), ['kode' => ['ZS2', 'ZS1']])->assertOk();
        $this->assertSame(['ZS2', 'ZS1'], SumberInformasi::whereIn('kode', ['ZS1', 'ZS2'])->orderBy('urutan')->pluck('kode')->all());
    }

    /**
     * Kolom pegangan HARUS ikut dirender untuk pengguna tanpa hak ubah — kalau
     * tidak, jumlah kolomnya berbeda antar pengguna dan indeks filter kolom
     * (<x-fcol :col="…">) menyaring kolom yang salah bagi sebagian orang.
     */
    public function test_kolom_pegangan_tetap_ada_walau_tanpa_hak_ubah(): void
    {
        $this->buatJalur();
        $petugas = User::create([
            'username' => 'zzur_ptg2', 'nama' => 'Petugas 2', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'jalur-pendaftaran',
            'lihat' => true, 'buat' => false, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);

        $this->actingAs($petugas)->get(route('jalur_pendaftaran.index'))->assertOk()
            ->assertSee('<span class="sr-only">Urutan</span>', false)
            ->assertDontSee('data-seret', false);

        $this->actingAs($this->admin)->get(route('jalur_pendaftaran.index'))->assertOk()
            ->assertSee('data-seret', false)
            ->assertSee('data-kode="001"', false);
    }
}
