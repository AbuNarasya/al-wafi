<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Modules\BudgetPengajuanService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua kekeliruan yang ditemukan pengguna di layar, bukan oleh test.
 *
 * 1. Kas Keluar yang seluruh baris rinciannya belum lengkap menjawab "Jurnal
 *    harus memiliki minimal 2 baris" — benar secara pembukuan, tetapi tak
 *    menyebut apa yang harus dikerjakan. Barisnya dibuang sebelum sampai ke
 *    akuntansi (disengaja, untuk baris yang telanjur ditambah lalu ditinggal),
 *    sehingga yang tersisa hanya sisi kas. Kas MASUK tidak kena: di sana
 *    `details.*.kode_coa` sudah `required` sejak validasi bentuk.
 *
 * 2. Kotak "Persetujuan Saya" memuat sepuluh jenis dokumen, tetapi mencocokkan
 *    dokumennya hanya lewat angka id. Usulan Anggaran #N dan Pengajuan
 *    Pembayaran #N sama-sama ada, sehingga kartu usulan anggaran menampilkan
 *    nomor, keterangan, dan tautan milik pengajuan pembayaran.
 */
class PesanRincianBelumLengkapTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        CoaGroup::create(['kode_grup' => 'ZZPR', 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => '1.ZZPR.1', 'nama_coa' => 'Bank Uji', 'kode_grup' => 'ZZPR', 'jenis_saldo' => 'debet']);
        BankAccount::create(['kode_coa' => '1.ZZPR.1', 'nama_rekening' => 'Bank Uji', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZPRU', 'nama_unit' => 'Yayasan']);

        $this->admin = User::create([
            'username' => 'zzpesan', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ])->refresh();
    }

    /** Baris uang muka tanpa uang muka terpilih → sebabnya disebut, bukan aturan jurnal. */
    public function test_kas_keluar_menyebut_baris_yang_belum_lengkap(): void
    {
        $this->actingAs($this->admin)->post(route('cash_out.store'), [
            'tanggal' => '2026-08-05',
            'kode_unit' => 'ZZPRU',
            'kode_rekening' => '1.ZZPR.1',
            'keterangan' => 'Uang Muka Bahan Bangunan',
            'details' => [
                ['tipe' => 'uang_muka', 'id_pengajuan' => '', 'nominal' => '10000000'],
            ],
        ])->assertRedirect();

        $pesan = (string) session('error');
        $this->assertStringContainsString('Baris 1', $pesan);
        $this->assertStringContainsString('uang muka belum dipilih', $pesan);
        $this->assertStringNotContainsString('minimal 2 baris', $pesan);
    }

    /**
     * Baris yang lengkap tetap diproses meski ada baris lain yang ditinggalkan —
     * kelonggaran itu memang disengaja dan tak boleh ikut hilang.
     */
    public function test_baris_kosong_tetap_boleh_ditinggalkan_bila_ada_yang_lengkap(): void
    {
        Bagian::create(['kode_bagian' => 'BJ1', 'nama_bagian' => 'Divisi Beban', 'level' => 3]);
        CoaDetail::create(['kode_coa' => '5.ZZPR.1', 'nama_coa' => 'Beban Uji', 'kode_grup' => 'ZZPR', 'jenis_saldo' => 'debet']);

        $this->actingAs($this->admin)->post(route('cash_out.store'), [
            'tanggal' => '2026-08-05',
            'kode_unit' => 'ZZPRU',
            'kode_rekening' => '1.ZZPR.1',
            'keterangan' => 'Beban dengan satu baris ditinggalkan',
            'details' => [
                ['tipe' => 'lainnya', 'kode_coa' => '5.ZZPR.1', 'nominal' => '75000', 'kode_bagian' => 'BJ1'],
                ['tipe' => 'lainnya', 'kode_coa' => '', 'nominal' => ''],
            ],
        ]);

        $this->assertNull(session('error'));
        $this->assertDatabaseHas('cash_out', ['keterangan' => 'Beban dengan satu baris ditinggalkan']);
    }

    /**
     * Jebakannya butuh KEDUANYA menunggu di kotak yang sama: satu Pengajuan
     * Pembayaran (yang membuat dokumennya termuat) dan satu Usulan Anggaran
     * ber-id sama. Yang diperiksa jumlah kemunculan, bukan ada/tidaknya —
     * nomor pengajuan itu memang SAH muncul sekali di kartunya sendiri; yang
     * salah adalah bila ia muncul DUA kali, yaitu ikut terbawa ke kartu usulan.
     */
    public function test_kartu_persetujuan_tidak_tertukar_antar_jenis_dokumen(): void
    {
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Divisi Umum', 'level' => 3]);
        $penyetuju = User::create([
            'username' => 'zzp_mb', 'nama' => 'Penyetuju', 'password_hash' => 'x',
            'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3,
        ])->refresh();

        $pb = PengajuanPembayaran::create([
            'nomor' => 'PB-JEBAKAN', 'tanggal' => '2026-08-05', 'jenis' => 'pembayaran',
            'keterangan' => 'KETERANGAN MILIK PENGAJUAN PEMBAYARAN', 'nominal' => '1000000',
            'status' => 'diajukan', 'kode_bagian' => 'B1', 'id_pengguna' => $this->admin->id_pengguna,
        ]);

        foreach ([['FPB', PengajuanPembayaranService::SUMBER], ['FBG', BudgetPengajuanService::SUMBER]] as [$kode, $jenis]) {
            $flow = ApprovalFlow::create(['kode_flow' => $kode, 'nama_flow' => $jenis, 'jenis_dokumen' => $jenis]);
            $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
            ApprovalInstance::create([
                'kode_flow' => $kode, 'jenis_dokumen' => $jenis,
                'id_dokumen' => (string) $pb->id, 'status' => 'berjalan', 'tahap_sekarang' => 1,
                'nominal' => '1000000', 'kode_bagian' => 'B1', 'id_pemohon' => $this->admin->id_pengguna,
            ]);
        }

        $isi = $this->actingAs($penyetuju)->get(route('approvals.inbox'))->assertOk()->content();

        $this->assertSame(1, substr_count($isi, 'PB-JEBAKAN'), 'Nomor pengajuan pembayaran ikut terbawa ke kartu usulan anggaran.');
        $this->assertSame(1, substr_count($isi, 'KETERANGAN MILIK PENGAJUAN PEMBAYARAN'));
        // Kartu usulan anggaran tetap ada, hanya tanpa detail yang bukan miliknya.
        $this->assertStringContainsString(BudgetPengajuanService::SUMBER, $isi);
    }
}
