<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\User;
use App\Models\Wali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layar Dompet & Tabungan Santri.
 *
 * Sebelumnya TAK ADA satu test pun yang membukanya, jadi galat render di sana
 * lolos begitu saja — `view:cache` hanya membuktikan berkasnya terkompilasi,
 * bukan bahwa ia bisa dirender dengan variabel yang sebenarnya.
 *
 * Yang dijaga: halamannya terbuka, dan pemilih walinya BISA DICARI. Daftar wali
 * di pesantren ini ratusan nama; `<select>` bawaan peramban memaksa petugas
 * menggulung mencari satu orang, dan itu tepat jenis gesekan yang membuat
 * petugas memilih nama yang salah.
 */
class LayarDompetTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZDP';

    private const KAS = '1.ZZDP.1';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zzdp_ptg', 'nama' => 'Petugas Dompet', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);
        HakAksesModul::create([
            'id_pengguna' => $this->petugas->id_pengguna, 'kode_modul' => 'dompet',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true,
        ]);

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Kas Dompet']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Bank Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Bank Uji', 'jenis_rekening' => 'bank', 'status' => 'aktif']);

        Wali::create(['nama' => 'ABDUL AZIS', 'telepon' => '87781861381', 'status' => 'aktif']);
        Wali::create(['nama' => 'ZULKIFLI AKBAR', 'telepon' => '81234567890', 'status' => 'aktif']);
    }

    public function test_halaman_terbuka_dan_memuat_daftar_wali(): void
    {
        $this->actingAs($this->petugas)->get(route('dompet.index'))
            ->assertOk()
            ->assertSee('Dompet &amp; Tabungan Santri', false)
            ->assertSee('ABDUL AZIS (87781861381)')
            ->assertSee('ZULKIFLI AKBAR (81234567890)');
    }

    /**
     * Pemilih wali WAJIB komponen yang bisa dicari, bukan <select> biasa.
     *
     * Diperiksa dari markupnya: `searchSelect(` hanya muncul bila komponennya
     * dipakai, dan `name="id_wali"` pada <select> hanya muncul bila ia kembali
     * jadi dropdown bawaan.
     */
    public function test_pemilih_wali_bisa_dicari(): void
    {
        $html = $this->actingAs($this->petugas)->get(route('dompet.index'))->assertOk()->getContent();

        $this->assertStringContainsString('searchSelect(', $html, 'pemilih wali harus memakai komponen yang bisa dicari');
        $this->assertStringNotContainsString('<select name="id_wali"', $html, 'pemilih wali tak boleh kembali jadi <select> biasa');
        $this->assertStringContainsString('name="id_wali"', $html, 'nilainya tetap dikirim dengan nama yang sama');
    }

    /** Wali yang sedang dipilih tetap terpilih sesudah halaman dimuat ulang. */
    public function test_wali_terpilih_tetap_terpilih(): void
    {
        $wali = Wali::where('nama', 'ABDUL AZIS')->sole();

        $this->actingAs($this->petugas)->get(route('dompet.index', ['id_wali' => $wali->id]))
            ->assertOk()
            ->assertSee('Dompet Wali');
    }
}
