<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\KartuSantri;
use App\Models\KunjunganKlinik;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/** Klinik (21 Okt 2026): catat kunjungan lengkap, wali dikabari, rawat → selesai, tagihan lain-lain dari kunjungan. */
class KlinikTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private User $perawat;

    private User $admin;

    private Santri $ahmad;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        $this->perawat = User::create(['username' => 'perawat', 'nama' => 'Perawat', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        HakAksesModul::create(['id_pengguna' => $this->perawat->id_pengguna, 'kode_modul' => 'klinik', 'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => false, 'menu' => true]);
        Akses::lupakan();

        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 2, 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        BusinessUnit::create(['kode_unit' => 'ZZKL', 'nama_unit' => 'Klinik']);
        CoaGroup::create(['kode_grup' => 'ZZKL', 'nama_grup' => 'Klinik']);
        CoaDetail::create(['kode_coa' => '4.ZZKL.1', 'nama_coa' => 'Pendapatan Klinik', 'kode_grup' => 'ZZKL', 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZKL.1', 'nama_coa' => 'Piutang Klinik', 'kode_grup' => 'ZZKL', 'jenis_saldo' => 'debet']);
        TipeBiaya::firstOrCreate(['kode' => 'lain'], ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        $this->buatBiaya(['kode' => 'KLN', 'nama' => 'Biaya Klinik', 'tipe' => 'lain', 'kode_coa_pendapatan' => '4.ZZKL.1',
            'kode_coa_piutang' => '1.ZZKL.1', 'kode_unit' => 'ZZKL']);

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = Santri::create(['no_pendaftaran' => 'L-1', 'nis' => '242507001', 'id_wali' => $this->wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J002', 'tingkat' => 1, 'tahun_ajaran' => '2026/2027', 'tahun_ajaran_berjalan' => '2026/2027', 'jalur' => '001', 'status' => 'aktif']);
    }

    public function test_catat_kunjungan_dirawat_lalu_selesai_dan_wali_dikabari(): void
    {
        KartuSantri::create(['uid' => 'ABCD1234', 'id_santri' => $this->ahmad->id, 'status' => 'aktif']);
        $this->actingAs($this->perawat)->get(route('klinik.create'))->assertSee('Tap kartu santri');
        $this->actingAs($this->perawat)->get(route('klinik.create', ['kartu' => 'AHMAD']))->assertSee('Kartu tidak dikenal'); // nama bukan kartu
        $this->actingAs($this->perawat)->get(route('klinik.create', ['kartu' => 'ABCD1234']))->assertOk()->assertSee('AHMAD')->assertSee('Simpan kunjungan');

        $this->actingAs($this->perawat)->post(route('klinik.store'), ['kartu' => 'ABCD1234', 'keluhan' => 'Demam dan pusing',
            'suhu' => 38.4, 'tensi' => '110/70', 'pemeriksaan' => 'Febris', 'tindakan' => 'Kompres', 'tindak_lanjut' => 'dirujuk'])
            ->assertSessionHas('error'); // rujukan wajib menyebut tujuan
        $this->actingAs($this->perawat)->post(route('klinik.store'), ['kartu' => 'ABCD1234', 'keluhan' => 'Demam dan pusing',
            'suhu' => 38.4, 'tensi' => '110/70', 'pemeriksaan' => 'Febris', 'tindakan' => 'Kompres', 'tindak_lanjut' => 'dirawat',
            'obat' => [['nama' => 'Paracetamol', 'dosis' => '3x1', 'jumlah' => '6'], ['nama' => '', 'dosis' => '', 'jumlah' => '']]])->assertRedirect();

        $k = KunjunganKlinik::sole();
        $this->assertSame(['dirawat', 1, '38.4'], [$k->status, $k->obat()->count(), (string) $k->suhu]);
        $kabar = NotifikasiWali::where('jenis', 'klinik')->sole();
        $this->assertSame('kesehatan', $kabar->kategori);
        $this->assertStringContainsString('Demam dan pusing', $kabar->pesan);

        $this->actingAs($this->perawat)->get(route('klinik.index'))->assertSee('Sedang dirawat di klinik (1)');
        $this->actingAs($this->perawat)->post(route('klinik.selesai', $k->id), ['catatan' => 'Sudah tidak demam'])->assertSessionHas('status');
        $this->assertSame('selesai', $k->refresh()->status);
        $this->assertSame(2, NotifikasiWali::where('jenis', 'klinik')->count());

        // Wali melihat riwayatnya di portal.
        $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true])
            ->get(route('portal.klinik'))->assertOk()->assertSee('Riwayat klinik')->assertSee('Demam dan pusing')->assertSee('Paracetamol');
        $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true])
            ->get(route('portal.anak', $this->ahmad->id))->assertDontSee('Demam dan pusing'); // tak lagi di detail anak
        $this->actingAs($this->perawat)->get(route('klinik.rekap'))->assertOk()->assertSee('demam');
    }

    public function test_tagihan_klinik_terbit_sekali_dan_butuh_hak_tagihan_lain(): void
    {
        KartuSantri::create(['uid' => 'ABCD1234', 'id_santri' => $this->ahmad->id, 'status' => 'aktif']);
        $this->actingAs($this->perawat)->post(route('klinik.store'), ['kartu' => 'ABCD1234', 'keluhan' => 'Luka', 'tindak_lanjut' => 'dirujuk', 'rujukan_ke' => 'RS Umum']);
        $k = KunjunganKlinik::sole();

        $this->actingAs($this->perawat)->post(route('klinik.tagihan', $k->id), ['kode_jenis' => 'KLN', 'nominal' => 150000])->assertForbidden();

        $this->actingAs($this->admin)->get(route('klinik.show', $k->id))->assertSee('Biaya Klinik');
        $this->actingAs($this->admin)->post(route('klinik.tagihan', $k->id), ['kode_jenis' => 'KLN', 'nominal' => 150000, 'keterangan' => 'Jahit luka RS'])
            ->assertSessionHas('status');
        $t = $k->refresh()->tagihan;
        $this->assertSame(['150000.00', $k->nomor, 'lain'], [(string) $t->nominal, $t->periode, $t->perilaku]);
        $this->actingAs($this->admin)->post(route('klinik.tagihan', $k->id), ['kode_jenis' => 'KLN', 'nominal' => 150000])->assertSessionHas('error');
    }
}
