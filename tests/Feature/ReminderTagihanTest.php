<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Level;
use App\Models\Notification;
use App\Models\ReminderSetting;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\ReminderTagihanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reminder tagihan jatuh tempo: jendela H-n, pengiriman, dan dedup per titik. */
class ReminderTagihanTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private int $admin;

    private int $keuangan;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;
        $this->keuangan = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true])->id_pengguna;

        CoaGroup::create(['kode_grup' => 'ZZRM', 'nama_grup' => 'RM']);
        CoaDetail::create(['kode_coa' => '4.ZZRM.REG', 'nama_coa' => 'Pendapatan Registrasi', 'kode_grup' => 'ZZRM', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZRMU', 'nama_unit' => 'Unit']);
        \App\Models\TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        \App\Models\JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => '2026/2027']);
    }

    private function buatTagihan(string $jatuhTempo): TagihanSantri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08999']);
        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => '4.ZZRM.REG', 'kode_unit' => 'ZZRMU', 'tahun_ajaran' => '2026/2027',
        ]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '2012-05-01',
            'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler',
        ]);

        // Tagihan registrasi otomatis dari registrasi tidak ber-jatuh-tempo; pakai tagihan manual.
        return TagihanSantri::create([
            'id_santri' => $santri->id, 'kode_jenis' => 'REG', 'periode' => 'UJI',
            'nominal' => '150000', 'sisa' => '150000', 'status' => 'belum_bayar', 'jatuh_tempo' => $jatuhTempo,
        ]);
    }

    public function test_daftar_mendekati_dan_kirim_dengan_dedup(): void
    {
        $tagihan = $this->buatTagihan(now()->addDays(3)->toDateString());
        $svc = new ReminderTagihanService;

        $item = collect($svc->daftarMendekati())
            ->first(fn ($d) => $d['sumber'] === 'tagihan_santri' && $d['id'] === (string) $tagihan->id);
        $this->assertNotNull($item);
        $this->assertSame(3, $item['hari_tersisa']);

        // H-3 memenuhi titik 3 → terkirim ke admin + tim keuangan.
        $hasil = $svc->kirim();
        $this->assertSame(2, $hasil['terkirim']);
        $ref = "tagihan_santri:{$tagihan->id}:h3";
        foreach ([$this->admin, $this->keuangan] as $id) {
            $this->assertDatabaseHas('notifications', ['id_pengguna' => $id, 'jenis' => 'reminder_tagihan', 'ref_id' => $ref]);
        }

        // Kirim ulang → dedup, tidak ada notifikasi baru.
        $this->assertSame(0, $svc->kirim()['terkirim']);
        $this->assertSame(2, Notification::where('ref_id', $ref)->count());
    }

    public function test_di_luar_jendela_dan_nonaktif_tidak_mengirim(): void
    {
        $this->buatTagihan(now()->addDays(30)->toDateString()); // di luar H-7
        $svc = new ReminderTagihanService;
        $this->assertSame(0, $svc->kirim()['terkirim']);

        ReminderSetting::create(['id' => 1, 'aktif' => false]);
        $this->assertSame(['terkirim' => 0, 'kandidat' => 0], $svc->kirim());
    }

    public function test_tagihan_terlambat_memakai_titik_terkecil(): void
    {
        $tagihan = $this->buatTagihan(now()->subDays(5)->toDateString());
        $svc = new ReminderTagihanService;

        $svc->kirim();
        $notif = Notification::where('ref_id', "tagihan_santri:{$tagihan->id}:h1")
            ->where('id_pengguna', $this->admin)->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('TERLAMBAT 5 hari', $notif->pesan);
    }

    public function test_parse_hari_normalisasi(): void
    {
        $this->assertSame([14, 3, 1], ReminderTagihanService::parseHari(' 14, 3,3, 1 '));
        $this->assertSame([7, 3, 1], ReminderTagihanService::parseHari(''));
        $this->assertSame([7, 0], ReminderTagihanService::parseHari('0,7,99'));
    }
}
