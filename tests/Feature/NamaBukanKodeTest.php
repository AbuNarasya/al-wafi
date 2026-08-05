<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layar & cetakan menyebut NAMA master, bukan kodenya.
 *
 * Lahir dari temuan nyata: kolom Unit pada rincian pengajuan (dan di halaman
 * verifikasi berkas keuangan) menampilkan "U001", legenda grafik dashboard PPSB
 * menampilkan "J001", dan label centang di halaman Tarif berbunyi "lepas centang
 * untuk J002 saja" — petugas tak hafal kode master mana pun.
 *
 * Kode master di sini SENGAJA dibuat berbeda jauh dari namanya supaya
 * kelalaian "menampilkan kode" tak bisa lolos hanya karena kode = nama.
 */
class NamaBukanKodeTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZNK';
    private const BEBAN = '5.ZZNK.1';
    private const UNIT = 'U001';
    private const NAMA_UNIT = 'Yayasan Pusat';

    private User $admin;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian Umum', 'level' => 3]);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => self::NAMA_UNIT]);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji Nama']);
        CoaDetail::create(['kode_coa' => self::BEBAN, 'nama_coa' => 'Beban Transportasi', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        $this->admin = User::create([
            'username' => 'zznk_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);
        $this->staff = User::create([
            'username' => 'zznk_stf', 'nama' => 'Staff', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4,
        ])->id_pengguna;

        $flow = ApprovalFlow::create(['kode_flow' => 'FNK', 'nama_flow' => 'Pengajuan', 'jenis_dokumen' => PengajuanPembayaranService::SUMBER]);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
    }

    public function test_rincian_pengajuan_menyebut_nama_unit_bukan_kodenya(): void
    {
        $rec = (new PengajuanPembayaranService)->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'BBM dan Etoll',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '1000000']],
        ], $this->staff);

        $halaman = $this->actingAs($this->admin)->get(route('pengajuan.show', $rec->id))->assertOk();

        // Kolom Unit pada rincian — inilah yang dulu berbunyi "U001".
        $halaman->assertSee('<td class="px-4 py-2 text-gray-500">'.self::NAMA_UNIT.'</td>', false);
        // Bagian pada ringkasan rantai persetujuan.
        $halaman->assertSee('Bagian Umum');
    }

    public function test_lembar_cetak_pengajuan_juga_memakai_nama_unit(): void
    {
        $svc = new PengajuanPembayaranService;
        $rec = $svc->create([
            'tanggal' => '2026-07-15', 'jenis' => 'pembayaran', 'keterangan' => 'BBM dan Etoll',
            'details' => [['kode_coa' => self::BEBAN, 'kode_unit' => self::UNIT, 'nominal' => '1000000']],
        ], $this->staff);
        // Cetak hanya sah bila rantai persetujuannya TUNTAS — di sini cukup
        // ditandai langsung, alur persetujuannya sendiri diuji di test lain.
        $rec->update(['status' => 'disetujui']);
        \App\Models\ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('id_dokumen', (string) $rec->id)->update(['status' => 'disetujui']);

        $this->actingAs($this->admin)->get(route('pengajuan.cetak', $rec->id))->assertOk()
            ->assertSee('<td class="px-2 py-1.5">'.self::NAMA_UNIT.'</td>', false);
    }

    /**
     * Halaman Tarif: label centang penyalinan dulu berbunyi "lepas centang untuk
     * J002 saja" — kode jenjang mentah di tengah kalimat.
     */
    public function test_label_salin_tarif_menyebut_nama_jenjang(): void
    {
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 1]);
        \App\Models\TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);

        $this->actingAs($this->admin)->get(route('tarif.index', ['ta' => '2026/2027', 'jenjang' => 'J002']))->assertOk()
            ->assertSee('lepas centang untuk SMP saja')
            ->assertDontSee('lepas centang untuk J002 saja')
            ->assertSee('Jalur yang berlaku di SMP');
    }

    /** Kolom Unit di master Jenis Biaya juga sempat menampilkan kodenya. */
    public function test_daftar_jenis_biaya_menyebut_nama_unit(): void
    {
        Jenjang::create(['kode' => 'J002', 'nama' => 'SMP', 'urutan' => 1]);
        \App\Models\TipeBiaya::create(['kode' => 'TB1', 'nama' => 'SPP', 'perilaku' => 'spp', 'urutan' => 1, 'bawaan' => false, 'status' => 'aktif']);
        \App\Models\JenisBiaya::create([
            'kode' => 'JB1', 'nama' => 'SPP SMP', 'tipe' => 'TB1', 'kode_jenjang' => 'J002',
            'kode_coa_pendapatan' => self::BEBAN, 'kode_unit' => self::UNIT, 'berulang' => true, 'status' => 'aktif',
        ]);

        $this->actingAs($this->admin)->get(route('jenis_biaya.index'))->assertOk()
            ->assertSee(self::NAMA_UNIT)
            ->assertDontSee('>'.self::UNIT.'<', false);
    }
}
