<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\Notification;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\NotificationService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifikasi: badge modul + lonceng terpusat.
 *
 * Inti yang dikunci di sini: penanda TUGAS bersumber dari keadaan nyata, jadi
 * ia bertahan sampai dokumennya diproses dan tak bisa dipadamkan dengan
 * "tandai dibaca"; sedangkan KABAR boleh ditandai dibaca.
 */
class NotifikasiTugasTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZNT';
    private const KAS = '1.ZZNT.KAS';
    private const PEND = '4.ZZNT.REG';
    private const UNIT = 'ZZNTU';
    private const TA = '2026/2027';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'NT']);
        foreach ([[self::KAS, 'Kas', 'debet'], [self::PEND, 'Pendapatan Registrasi', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Besar', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        // status WAJIB diisi eksplisit: Akses & TugasSaya menolak pengguna yang
        // statusnya bukan 'aktif', dan nilai default kolom tak terisi di objek
        // hasil create() (hanya di baris databasenya).
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        $this->jenjangUji();
        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);
    }

    private function bayarMenunggu(): PembayaranSantri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $this->jenjangUji(),
        ]);

        return (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $santri->tagihan()->first()->id,
            'tanggal' => now()->toDateString(), 'nominal' => '100000', 'kode_rekening' => self::KAS,
            'metode' => 'tunai',
        ], (int) $this->admin->id_pengguna, 'ppsb');
    }

    private function hitungUlang(): int
    {
        TugasSaya::lupakan();

        return TugasSaya::untukUrl('/ppsb/pembayaran');
    }

    public function test_badge_muncul_saat_ada_pekerjaan_dan_hilang_setelah_diproses(): void
    {
        $this->actingAs($this->admin);
        $this->assertSame(0, $this->hitungUlang());

        $bayar = $this->bayarMenunggu();
        $this->assertSame(1, $this->hitungUlang(), 'Badge harus muncul selama pembayaran belum diverifikasi.');

        (new PembayaranSantriService)->verifikasi($bayar->id, (int) $this->admin->id_pengguna);
        $this->assertSame(0, $this->hitungUlang(), 'Badge harus hilang sendiri setelah diverifikasi.');
    }

    /** Inti permintaan: melihat notifikasi TIDAK menghapus penandanya. */
    public function test_tugas_tidak_bisa_dipadamkan_dengan_tandai_dibaca(): void
    {
        $this->actingAs($this->admin);
        $this->bayarMenunggu();

        $notif = Notification::where('id_pengguna', (int) $this->admin->id_pengguna)
            ->where('jenis', 'pembayaran_santri_menunggu')->firstOrFail();

        $svc = new NotificationService;
        $svc->tandaiDibaca($notif->id, (int) $this->admin->id_pengguna);
        $svc->tandaiSemuaDibaca((int) $this->admin->id_pengguna);

        $this->assertFalse((bool) $notif->refresh()->dibaca, 'Notifikasi tugas tak boleh ikut ditandai dibaca.');
        $this->assertSame(1, $this->hitungUlang(), 'Badge tetap ada karena pekerjaannya belum dikerjakan.');
        $this->assertCount(1, $svc->feed((int) $this->admin->id_pengguna)['tugas']);
    }

    public function test_kabar_boleh_ditandai_dibaca(): void
    {
        $this->actingAs($this->admin);
        $bayar = $this->bayarMenunggu();
        (new PembayaranSantriService)->tolak($bayar->id, 'dana tidak masuk', (int) $this->admin->id_pengguna);

        $svc = new NotificationService;
        $this->assertSame(1, $svc->hitungKabarBelumDibaca((int) $this->admin->id_pengguna));

        $svc->tandaiSemuaDibaca((int) $this->admin->id_pengguna);
        $this->assertSame(0, $svc->hitungKabarBelumDibaca((int) $this->admin->id_pengguna));
    }

    /** Notifikasi tugas yang dokumennya sudah diproses tak boleh menagih selamanya. */
    public function test_notifikasi_tugas_basi_dibereskan_sendiri(): void
    {
        $this->actingAs($this->admin);
        $bayar = $this->bayarMenunggu();
        $notif = Notification::where('jenis', 'pembayaran_santri_menunggu')
            ->where('id_pengguna', (int) $this->admin->id_pengguna)->firstOrFail();

        (new PembayaranSantriService)->verifikasi($bayar->id, (int) $this->admin->id_pengguna);

        $svc = new NotificationService;
        $this->assertSame(1, $svc->rapikan((int) $this->admin->id_pengguna));
        $this->assertTrue((bool) $notif->refresh()->dibaca);
        $this->assertCount(0, $svc->feed((int) $this->admin->id_pengguna)['tugas']);
    }

    public function test_hitungan_disaring_hak_akses(): void
    {
        $this->bayarMenunggu();

        // Pengguna biasa tanpa hak modul & bukan tim keuangan → tak melihat angka.
        $biasa = User::create([
            'username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        $this->actingAs($biasa);
        $this->assertSame(0, $this->hitungUlang());

        $this->actingAs($this->admin);
        $this->assertSame(1, $this->hitungUlang());
    }

    /**
     * Tagihan jatuh tempo ditandai di modul tempat pekerjaannya dikerjakan,
     * BUKAN di halaman "Reminder Tagihan Jatuh Tempo" yang isinya setelan.
     */
    public function test_jatuh_tempo_menandai_modul_kerja_bukan_halaman_setelan(): void
    {
        $this->actingAs($this->admin);
        $bayar = $this->bayarMenunggu();
        // Tagihan registrasi (lingkup PPSB) yang jatuh temponya sudah lewat.
        $bayar->tagihan->update(['jatuh_tempo' => now()->subDay()->toDateString()]);

        TugasSaya::lupakan();
        $this->assertSame(0, TugasSaya::untukUrl('/reminder-tagihan'), 'Halaman setelan tak boleh membawa penanda pekerjaan.');
        // Halaman Pembayaran PPSB memikul dua-duanya: 1 menunggu verifikasi + 1 jatuh tempo.
        $this->assertSame(2, TugasSaya::untukUrl('/ppsb/pembayaran'));
        $this->assertStringContainsString('jatuh tempo', (string) TugasSaya::labelUrl('/ppsb/pembayaran'));
        $this->assertStringContainsString('menunggu verifikasi', (string) TugasSaya::labelUrl('/ppsb/pembayaran'));
    }

    public function test_halaman_notifikasi_dan_badge_tampil(): void
    {
        $this->bayarMenunggu();
        TugasSaya::lupakan();

        $this->actingAs($this->admin)->get('/notifikasi')->assertOk()
            ->assertSee('Menunggu dikerjakan')
            ->assertSee('menunggu verifikasi');

        // Badge ikut muncul di sidebar semua halaman (dirender layout).
        TugasSaya::lupakan();
        $this->actingAs($this->admin)->get('/ppsb/pembayaran')->assertOk()
            ->assertSee('Notifikasi (1 baru)', false);
    }
}
