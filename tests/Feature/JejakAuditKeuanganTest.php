<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Inventory;
use App\Models\Level;
use App\Models\User;
use App\Services\Ledger\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JEJAK AUDIT MODUL KEUANGAN.
 *
 * `ActivityLog` selama ini hanya ditulisi modul kesantrian; jurnal, kas,
 * invoice, pengajuan, dan perintah pembayaran tak meninggalkan jejak sama
 * sekali — justru di situ jejak paling dibutuhkan.
 *
 * Yang diuji bukan sekadar "ada barisnya", melainkan bahwa jejaknya menjawab
 * pertanyaan yang sesungguhnya diajukan saat sebuah angka dipersoalkan:
 * siapa, kapan, dokumen mana, dan DARI BERAPA MENJADI BERAPA.
 */
class JejakAuditKeuanganTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZJA';

    private const KAS = '1.ZZJA.KAS';

    private const MODAL = '3.ZZJA.MDL';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => '1', 'nama_grup' => 'Aset', 'level' => 1]);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji', 'kode_induk' => '1', 'level' => 3]);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::MODAL, 'nama_coa' => 'Modal', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        Bagian::create(['kode_bagian' => 'BJA', 'nama_bagian' => 'Umum', 'level' => 3]);
        Level::create(['kode_level' => 'LJA', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        $this->admin = User::create([
            'username' => 'admja', 'nama' => 'Admin Audit', 'password_hash' => 'x',
            'kode_level' => 'LJA', 'is_admin' => true, 'status' => 'aktif',
        ]);
    }

    public function test_jurnal_yang_diposting_meninggalkan_jejak(): void
    {
        $this->actingAs($this->admin);

        PostingService::postJournal(['referensi' => 'JU-JA-1', 'tanggal' => '2026-07-01', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '100000', 'kredit' => '0'],
            ['kode_coa' => self::MODAL, 'debet' => '0', 'kredit' => '100000'],
        ]]);

        $jejak = ActivityLog::where('ref_jenis', 'JournalEntry')->first();

        $this->assertNotNull($jejak, 'jurnal keuangan harus meninggalkan jejak');
        $this->assertSame('buat_journal', $jejak->aksi);
        $this->assertSame('journal', $jejak->modul);
        $this->assertSame($this->admin->id_pengguna, $jejak->id_pengguna);
    }

    public function test_perubahan_merekam_nilai_lama_dan_baru(): void
    {
        $this->actingAs($this->admin);

        $entry = PostingService::postJournal(['referensi' => 'JU-JA-2', 'tanggal' => '2026-07-01', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '100000', 'kredit' => '0'],
            ['kode_coa' => self::MODAL, 'debet' => '0', 'kredit' => '100000'],
        ]]);

        $entry->update(['keterangan' => 'Diperbaiki keterangannya']);

        $jejak = ActivityLog::where('aksi', 'ubah_journal')->latest('id')->first();

        $this->assertNotNull($jejak);
        $this->assertArrayHasKey('keterangan', $jejak->perubahan);
        $this->assertSame('Diperbaiki keterangannya', $jejak->perubahan['keterangan']['baru']);
    }

    public function test_penyimpanan_tanpa_perubahan_tidak_melahirkan_jejak(): void
    {
        $this->actingAs($this->admin);

        $akun = CoaDetail::create(['kode_coa' => '1.ZZJA.X', 'nama_coa' => 'Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        $sebelum = ActivityLog::count();

        // Menyimpan ulang nilai yang sama persis — tak ada yang berubah, jadi
        // tak layak jadi baris jejak. Tanpa penjaga ini, jejaknya penuh derau
        // dan yang penting tenggelam.
        $akun->update(['nama_coa' => 'Uji']);

        $this->assertSame($sebelum, ActivityLog::count());
    }

    public function test_kolom_turunan_persediaan_tak_mengotori_jejak(): void
    {
        $this->actingAs($this->admin);

        $item = Inventory::create([
            'kode_persediaan' => 'BRGJA1', 'nama_persediaan' => 'Beras', 'satuan' => 'kg',
            'harga_perolehan' => '0', 'kode_coa' => self::KAS, 'status' => 'aktif',
        ]);
        $sebelum = ActivityLog::count();

        // Kolom turunan bergerak pada SETIAP pergerakan stok; riwayat
        // sesungguhnya sudah utuh di kartu stok, jadi ia tak perlu ikut jejak.
        $item->update(['stok_masuk' => '100', 'nilai_persediaan' => '1000000', 'harga_perolehan' => '10000']);

        $this->assertSame($sebelum, ActivityLog::count());
    }

    public function test_jejak_merekam_dokumen_yang_disentuhnya(): void
    {
        $this->actingAs($this->admin);

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        $jejak = ActivityLog::where('ref_jenis', 'BankAccount')->first();

        $this->assertNotNull($jejak);
        $this->assertSame('bank-accounts', $jejak->modul);
        $this->assertSame(self::KAS, $jejak->ref_id);
    }

    public function test_halaman_jejak_audit_terbuka_dan_bisa_disaring(): void
    {
        $this->actingAs($this->admin);

        PostingService::postJournal(['referensi' => 'JU-JA-3', 'tanggal' => '2026-07-01', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '100000', 'kredit' => '0'],
            ['kode_coa' => self::MODAL, 'debet' => '0', 'kredit' => '100000'],
        ]]);

        $this->get(route('jejak_audit.index'))
            ->assertOk()
            ->assertSee('Jejak Audit')
            ->assertSee('buat_journal')
            ->assertDontSee('Tidak ada jejak yang cocok');

        // Saringan modul yang tak punya jejak harus benar-benar mengosongkan.
        $this->get(route('jejak_audit.index', ['modul' => 'wali']))
            ->assertOk()
            ->assertSee('Tidak ada jejak yang cocok');
    }

    public function test_jejak_audit_hanya_untuk_admin(): void
    {
        $biasa = User::create([
            'username' => 'biasaja', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'LJA', 'is_admin' => false, 'status' => 'aktif',
        ]);

        $this->actingAs($biasa)->get(route('jejak_audit.index'))->assertForbidden();
    }

    public function test_tak_ada_rute_untuk_menghapus_jejak(): void
    {
        // Jejak yang bisa dihapus dari dalam aplikasi berhenti menjadi jejak.
        $rute = collect(app('router')->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter(fn ($n) => $n && str_starts_with($n, 'jejak_audit.'))
            ->values()->all();

        $this->assertSame(['jejak_audit.index'], $rute);
    }
}
