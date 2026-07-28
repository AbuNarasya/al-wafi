<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pemilih santri pada form catat pembayaran: menampilkan NOMOR PENDAFTARAN +
 * nama, dan bisa dicari bebas.
 *
 * Nama saja tak cukup — petugas memegang nomor pendaftaran, dan santri bisa
 * bernama mirip. Data yang dikirim ke pemilih karenanya wajib memuat nomor
 * pendaftaran & NIS; pencocokan teksnya sendiri dikerjakan di browser.
 */
class PembayaranPilihSantriTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZPS';
    private const KAS = '1.ZZPS.KAS';
    private const PEND = '4.ZZPS.PEND';
    private const UNIT = 'ZZPSU';
    private const TA = '2027/2028';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'PS']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        \App\Models\Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        (new JenisBiayaService)->create([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '1000000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function calon(string $nama): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);

        return (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ]);
    }

    public function test_pemilih_memuat_nomor_pendaftaran_dan_kotak_cari(): void
    {
        $santri = $this->calon('Ahmad');

        $this->actingAs($this->admin)->get('/ppsb/pembayaran/create')->assertOk()
            // Nomor pendaftaran ikut dikirim ke pemilih, bukan hanya nama.
            ->assertSee($santri->no_pendaftaran)
            ->assertSee('no_pendaftaran', false)
            // Kotak pencarian bebas menggantikan dropdown biasa.
            ->assertSee('Ketik nomor pendaftaran, NIS, atau nama')
            ->assertSee('hasilCari', false);
    }

    /** Santri tanpa tagihan tertunggak tak boleh muncul di pemilih. */
    public function test_hanya_santri_bertagihan_yang_muncul(): void
    {
        $punya = $this->calon('PunyaTagihan');
        $lunas = $this->calon('SudahLunas');
        $lunas->tagihan()->first()->update(['status' => 'lunas', 'sisa' => 0]);

        $this->actingAs($this->admin)->get('/ppsb/pembayaran/create')->assertOk()
            ->assertSee($punya->no_pendaftaran)
            ->assertDontSee($lunas->no_pendaftaran);
    }
}
