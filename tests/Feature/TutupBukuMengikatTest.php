<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\Bagian;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\PermohonanBukaPeriode;
use App\Models\User;
use App\Services\Ledger\PostingService;
use App\Services\Modules\BukaPeriodeService;
use App\Services\Modules\PeriodCloseService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TUTUP BUKU MENGIKAT + PERMOHONAN BUKA PERIODE (dua tangan).
 *
 * Dulu `PeriodService::GRACE_DAYS = 30` membiarkan periode yang SUDAH ditutup
 * tetap boleh dijurnal siapa pun selama 30 hari, dan membukanya kembali cukup
 * sekali klik tanpa alasan. Yang diuji di sini adalah bahwa kedua celah itu
 * benar-benar tertutup — termasuk celah yang paling mudah terlewat: pemohon
 * yang menyetujui permohonannya sendiri.
 */
class TutupBukuMengikatTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTB';

    private const KAS = '1.ZZTB.KAS';

    private const MODAL = '3.ZZTB.MDL';

    private User $admin;

    private User $direktur;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => '1', 'nama_grup' => 'Aset', 'level' => 1]);
        CoaGroup::create(['kode_grup' => '3', 'nama_grup' => 'Ekuitas', 'level' => 1]);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji', 'kode_induk' => '1', 'level' => 3]);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::MODAL, 'nama_coa' => 'Modal', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        Bagian::create(['kode_bagian' => 'BTB', 'nama_bagian' => 'Umum', 'level' => 3]);
        Level::create(['kode_level' => 'LTB', 'nama_level' => 'Level', 'max_transaksi' => null]);

        // Admin keuangan: boleh MENGAJUKAN saja.
        $this->admin = $this->buatPengguna('adminkeu', ['buat']);
        // Direktur keuangan: boleh MEMUTUSKAN saja.
        $this->direktur = $this->buatPengguna('direkturkeu', ['ubah']);
    }

    /** @param  list<string>  $aksi  sumbu hak pada modul `buka-periode` */
    private function buatPengguna(string $username, array $aksi): User
    {
        $u = User::create([
            'username' => $username, 'nama' => ucfirst($username), 'password_hash' => 'x',
            'kode_level' => 'LTB', 'is_admin' => false, 'status' => 'aktif', 'tim_keuangan' => true,
        ]);

        HakAksesModul::create([
            'id_pengguna' => $u->id_pengguna,
            'kode_modul' => BukaPeriodeService::MODUL,
            'lihat' => true,
            'buat' => in_array('buat', $aksi, true),
            'ubah' => in_array('ubah', $aksi, true),
            'hapus' => false,
        ]);

        return $u;
    }

    private function tutupJuli(): void
    {
        (new PeriodCloseService)->tutupBulan(2026, 7, $this->admin->id_pengguna, $this->admin->nama, 'tutup rutin');
    }

    private function jurnalJuli(): void
    {
        PostingService::postJournal(['referensi' => 'JU-TB-'.uniqid(), 'tanggal' => '2026-07-15', 'sumber_modul' => 'JurnalUmum', 'lines' => [
            ['kode_coa' => self::KAS, 'debet' => '100000', 'kredit' => '0'],
            ['kode_coa' => self::MODAL, 'debet' => '0', 'kredit' => '100000'],
        ]]);
    }

    // ---- Periode tertutup benar-benar mengunci ----

    public function test_periode_tertutup_menolak_jurnal_walau_baru_saja_ditutup(): void
    {
        $this->tutupJuli();

        // Inti perubahannya: dulu jurnal ini LOLOS karena baru ditutup hari ini
        // (masih di dalam toleransi 30 hari). Sekarang ditolak seketika.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah ditutup/');
        $this->jurnalJuli();
    }

    public function test_periode_terbuka_tetap_menerima_jurnal(): void
    {
        $this->jurnalJuli();
        $this->assertSame(1, JournalEntry::count());
    }

    // ---- Membuka hanya lewat permohonan ----

    public function test_buka_bulan_tak_bisa_dipanggil_langsung(): void
    {
        $this->tutupJuli();

        // Penjaga di service, bukan hanya tombol yang disembunyikan.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/permohonan yang disetujui/');
        (new PeriodCloseService)->bukaBulan(2026, 7, $this->direktur->id_pengguna);
    }

    public function test_alur_lengkap_ajukan_lalu_setujui_membuka_periodenya(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->admin);
        $permohonan = (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7,
            'alasan' => 'Ada invoice vendor tertinggal bertanggal 20 Juli.',
        ], $this->admin);

        $this->assertSame('diajukan', $permohonan->status);
        $this->assertSame('closed', AccountingPeriod::where('tahun', 2026)->where('bulan', 7)->value('status'));

        Akses::lupakan();
        $this->actingAs($this->direktur);
        (new BukaPeriodeService)->setujui($permohonan->id, $this->direktur, 'Disetujui, silakan posting.');

        $this->assertSame('open', AccountingPeriod::where('tahun', 2026)->where('bulan', 7)->value('status'));

        // Dan jurnalnya kini benar-benar bisa masuk.
        $this->jurnalJuli();
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_pemohon_tak_boleh_memutuskan_permohonannya_sendiri(): void
    {
        $this->tutupJuli();

        // Seorang yang memegang KEDUA sumbu hak — celah paling mudah terlewat.
        $serbabisa = $this->buatPengguna('serbabisa', ['buat', 'ubah']);

        Akses::lupakan();
        $this->actingAs($serbabisa);
        $permohonan = (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7, 'alasan' => 'Perlu dibuka.',
        ], $serbabisa);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/pemohonnya sendiri/');
        (new BukaPeriodeService)->setujui($permohonan->id, $serbabisa);
    }

    public function test_admin_keuangan_tak_boleh_memutuskan(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->admin);
        $permohonan = (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7, 'alasan' => 'Perlu dibuka.',
        ], $this->admin);

        // Masih ber-sesi admin keuangan: ia tak punya sumbu `ubah`.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/direktur keuangan/');
        (new BukaPeriodeService)->setujui($permohonan->id, $this->admin);
    }

    public function test_direktur_keuangan_tak_boleh_mengajukan(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->direktur);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak berhak mengajukan/');
        (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7, 'alasan' => 'Perlu dibuka.',
        ], $this->direktur);
    }

    public function test_alasan_wajib_diisi(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Alasan wajib/');
        (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7, 'alasan' => '   ',
        ], $this->admin);
    }

    public function test_tak_boleh_dua_permohonan_hidup_untuk_periode_yang_sama(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->admin);
        $isi = ['lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7, 'alasan' => 'Perlu dibuka.'];
        (new BukaPeriodeService)->ajukan($isi, $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/masih menunggu keputusan/');
        (new BukaPeriodeService)->ajukan($isi, $this->admin);
    }

    public function test_penolakan_membiarkan_periodenya_tetap_tertutup(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->admin);
        $permohonan = (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7, 'alasan' => 'Perlu dibuka.',
        ], $this->admin);

        Akses::lupakan();
        $this->actingAs($this->direktur);
        (new BukaPeriodeService)->tolak($permohonan->id, $this->direktur, 'Pakai jurnal periode berjalan saja.');

        $this->assertSame('ditolak', PermohonanBukaPeriode::find($permohonan->id)->status);
        $this->assertSame('closed', AccountingPeriod::where('tahun', 2026)->where('bulan', 7)->value('status'));
    }

    // ---- Jejak ----

    public function test_seluruh_langkahnya_meninggalkan_jejak_beserta_alasannya(): void
    {
        $this->tutupJuli();

        Akses::lupakan();
        $this->actingAs($this->admin);
        $permohonan = (new BukaPeriodeService)->ajukan([
            'lingkup' => 'bulan', 'tahun' => 2026, 'bulan' => 7,
            'alasan' => 'Invoice vendor tertinggal.',
        ], $this->admin);

        Akses::lupakan();
        $this->actingAs($this->direktur);
        (new BukaPeriodeService)->setujui($permohonan->id, $this->direktur, 'Silakan.');

        $aksi = ActivityLog::pluck('aksi')->all();
        $this->assertContains('tutup_bulan', $aksi);
        $this->assertContains('ajukan_buka_periode', $aksi);
        $this->assertContains('setujui_buka_periode', $aksi);
        $this->assertContains('buka_bulan', $aksi);

        // Alasan pemohon ikut terbawa ke jejak keputusannya — tanpa itu, jejak
        // hanya berkata "disetujui" tanpa pernah bisa menjawab "kenapa".
        $jejak = ActivityLog::where('aksi', 'setujui_buka_periode')->first();
        $this->assertStringContainsString('Invoice vendor tertinggal', $jejak->detail);
        $this->assertSame($this->direktur->id_pengguna, $jejak->id_pengguna);
    }
}
