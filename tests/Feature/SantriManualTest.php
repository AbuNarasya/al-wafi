<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\NisSantri;
use App\Models\Pendaftaran;
use App\Models\PembayaranSantri;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\SantriManualService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INPUT MANUAL SANTRI AKTIF — pintu satuan, versi non-borongan dari impor.
 *
 * Yang dijaga di sini tiga hal:
 *
 *   1. Santrinya lahir AKTIF tanpa melewati PPSB — tanpa baris pendaftaran,
 *      tanpa tagihan registrasi, tanpa potongan gelombang.
 *   2. Tiap tagihan menaati PERLAKUAN POSTING yang dipilih. Ketiganya berbeda
 *      bukan pada namanya melainkan pada jurnal yang terbit dan pada sisi mana
 *      yang dikredit saat dibayar — dan salah satu di antaranya membuat laporan
 *      laba rugi keliru tanpa satu pun galat muncul.
 *   3. Pintunya milik ADMIN, dan pembatalannya hanya menyentuh yang lahir di sini.
 */
class SantriManualTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZSM';

    private const PIUTANG = '1.ZZSM.1';

    private const PENDAPATAN = '4.ZZSM.1';

    private const TA = '2026/2027';

    private User $admin;

    private Wali $wali;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzsm_admin', 'nama' => 'Admin Uji', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3, 'urutan' => 1, 'status' => 'aktif']);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        TahunAjaran::create(['kode' => '2024/2025', 'nama' => 'TA Lama']);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'urutan' => 1, 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => 'ZZSMU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Santri Manual Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        JenisBiaya::create([
            'kode' => 'LAIN-UJI', 'nama' => 'Tunggakan Uji', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZSMU', 'status' => 'aktif',
        ]);
        JenisBiaya::create([
            'kode' => 'TANPA-AKUN', 'nama' => 'Tanpa Akun', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => null,
            'kode_unit' => 'ZZSMU', 'status' => 'aktif',
        ]);

        $this->wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Uji', 'telepon_ayah' => '0811',
            'nama' => 'Bapak Uji', 'telepon' => '0811', 'status' => 'aktif',
        ]);
    }

    /** @param list<array<string,mixed>> $tagihan */
    private function kirim(array $timpa = [], array $tagihan = [])
    {
        return $this->actingAs($this->admin)->post(route('santri_manual.store'), array_merge([
            'nis' => '990001',
            'nama' => 'Santri Pindahan',
            'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP',
            'tingkat' => 2,
            'tahun_ajaran' => '2024/2025',
            'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler',
            'id_wali' => $this->wali->id,
            'tagihan' => $tagihan,
        ], $timpa));
    }

    /** @return array<string,mixed> */
    private function baris(string $posting, array $timpa = []): array
    {
        return $timpa + [
            'kode_jenis' => 'LAIN-UJI',
            'nominal' => '1500000',
            'posting' => $posting,
            'keterangan' => 'Tunggakan SPP Jan–Jun 2025',
        ];
    }

    /**
     * Lahir AKTIF, tanpa jejak PPSB apa pun. Menagihnya registrasi adalah
     * kekeliruan — ia tak pernah mendaftar di aplikasi ini.
     */
    public function test_santri_lahir_aktif_tanpa_melewati_ppsb(): void
    {
        $this->kirim()->assertRedirect();

        $s = Santri::firstOrFail();
        $this->assertSame('aktif', $s->status);
        $this->assertStringStartsWith('LAMA-', $s->no_pendaftaran);
        $this->assertNull($s->gelombang, 'santri lama tak boleh kena hitungan potongan gelombang');
        $this->assertNull($s->id_batch, 'bukan hasil impor');
        $this->assertSame(self::TA, $s->tahun_ajaran_berjalan);
        $this->assertSame('2024/2025', $s->tahun_ajaran, 'tahun MASUK tak ikut maju');

        $this->assertSame(0, Pendaftaran::count(), 'tak ada baris pendaftaran PPSB');
        $this->assertSame(0, TagihanSantri::count(), 'tak ada tagihan registrasi');
        $this->assertSame(0, JournalEntry::count());

        // Riwayat NIS wajib ikut: tanpa itu layar Generate NIS mengira santri ini
        // belum pernah bernomor lalu menawarkan nomor baru.
        $this->assertSame(1, NisSantri::where('id_santri', $s->id)->count());
        $this->assertSame(1, RiwayatTingkat::where('id_santri', $s->id)->count());
    }

    /** SALDO AWAL: berakrual, TANPA jurnal — masuk neraca lewat baris turunan. */
    public function test_posting_saldo_awal_tidak_menjurnal(): void
    {
        $this->kirim([], [$this->baris('saldo_awal')])->assertRedirect();

        $t = TagihanSantri::firstOrFail();
        $this->assertTrue($t->sudah_akrual);
        $this->assertTrue($t->saldo_awal);
        $this->assertSame(0, JournalEntry::count());
    }

    /** AKRUAL SEKARANG: jurnal D Piutang / K Pendapatan terbit hari ini. */
    public function test_posting_akrual_menerbitkan_jurnal(): void
    {
        $this->kirim([], [$this->baris('akrual')])->assertRedirect();

        $t = TagihanSantri::firstOrFail();
        $this->assertTrue($t->sudah_akrual);
        $this->assertFalse($t->saldo_awal, 'ini pendapatan periode berjalan, bukan saldo awal');

        $entry = JournalEntry::firstOrFail();
        $this->assertSame(1500000.0, (float) JournalLine::where('entry_id', $entry->id)
            ->where('kode_coa', self::PIUTANG)->value('debet'));
        $this->assertSame(1500000.0, (float) JournalLine::where('entry_id', $entry->id)
            ->where('kode_coa', self::PENDAPATAN)->value('kredit'));
    }

    /** KAS BASIS: tak diakui sama sekali sampai uangnya datang. */
    public function test_posting_kas_tidak_mengakui_apa_pun(): void
    {
        $this->kirim([], [$this->baris('kas')])->assertRedirect();

        $t = TagihanSantri::firstOrFail();
        $this->assertFalse($t->sudah_akrual, 'pembayarannya kelak mengkredit Pendapatan, bukan Piutang');
        $this->assertFalse($t->saldo_awal);
        $this->assertSame(0, JournalEntry::count());
    }

    /** Ketiganya boleh bercampur dalam satu santri. */
    public function test_tiga_perlakuan_boleh_bercampur(): void
    {
        $this->kirim([], [
            $this->baris('saldo_awal', ['nominal' => '100000']),
            $this->baris('akrual', ['nominal' => '200000']),
            $this->baris('kas', ['nominal' => '300000']),
        ])->assertRedirect();

        $this->assertSame(3, TagihanSantri::count());
        $this->assertSame(1, TagihanSantri::where('saldo_awal', true)->count());
        $this->assertSame(2, TagihanSantri::where('sudah_akrual', true)->count());
        $this->assertSame(1, JournalEntry::count(), 'hanya baris akrual yang menjurnal');
    }

    /**
     * Ditolak DI MUKA, dan yang penting: SANTRINYA TIDAK IKUT TERBUAT.
     *
     * Kalau barisnya baru ditolak di tengah penyimpanan, santrinya sudah
     * terlanjur ada dan petugas harus membereskan setengah jadi.
     */
    public function test_jenis_tanpa_akun_piutang_ditolak_dan_tak_menyisakan_setengah_jadi(): void
    {
        $this->kirim([], [$this->baris('saldo_awal', ['kode_jenis' => 'TANPA-AKUN'])])
            ->assertSessionHas('error');

        $this->assertSame(0, Santri::count(), 'santrinya tak boleh terlanjur dibuat');
        $this->assertSame(0, TagihanSantri::count());
    }

    /** Jenis tanpa akun piutang TETAP sah untuk kas basis — ia tak butuh piutang. */
    public function test_jenis_tanpa_akun_piutang_sah_untuk_kas_basis(): void
    {
        $this->kirim([], [$this->baris('kas', ['kode_jenis' => 'TANPA-AKUN'])])->assertRedirect();

        $this->assertSame(1, TagihanSantri::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_nis_kembar_ditolak(): void
    {
        $this->kirim()->assertRedirect();
        $this->kirim(['nama' => 'Santri Lain'])->assertSessionHas('error');

        $this->assertSame(1, Santri::count());
    }

    /** Wali boleh dibuat sekalian — lewat WaliService, beserta penjagaannya. */
    public function test_wali_baru_dibuat_sekaligus(): void
    {
        // Isiannya BERPERAN: nama & telepon wali disalin dari peran yang ditunjuk
        // `kontak_utama`, bukan diisi langsung.
        $this->kirim([
            'id_wali' => null,
            'wali_baru' => ['kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Baru', 'telepon_ayah' => '08999'],
        ])->assertRedirect();

        $this->assertSame(2, Wali::count());
        $this->assertSame('Bapak Baru', Santri::firstOrFail()->wali->nama);
    }

    public function test_wali_wajib_salah_satu(): void
    {
        $this->kirim(['id_wali' => null])->assertSessionHasErrors();
        $this->assertSame(0, Santri::count());
    }

    /** Boleh dihapus selama belum tersentuh — setara pembatalan batch impor. */
    public function test_boleh_dihapus_selama_belum_tersentuh(): void
    {
        $this->kirim([], [$this->baris('saldo_awal')])->assertRedirect();
        $s = Santri::firstOrFail();

        $this->assertSame([], SantriManualService::halangan($s));

        $this->actingAs($this->admin)->delete(route('santri_manual.destroy', $s->id))->assertRedirect();

        $this->assertSame(0, Santri::count());
        $this->assertSame(0, TagihanSantri::count());
        $this->assertSame(0, NisSantri::count());
        $this->assertSame(0, RiwayatTingkat::count());
    }

    public function test_tidak_bisa_dihapus_setelah_ada_pembayaran(): void
    {
        $this->kirim([], [$this->baris('saldo_awal')])->assertRedirect();
        $s = Santri::firstOrFail();
        $t = TagihanSantri::firstOrFail();

        PembayaranSantri::create([
            'nomor' => 'BYR-'.uniqid(), 'id_tagihan' => $t->id, 'id_santri' => $s->id,
            'tanggal' => '2026-09-01', 'nominal' => '100000', 'metode' => 'tunai',
            'kode_rekening' => self::PIUTANG, 'status' => 'menunggu_verifikasi',
            'dicatat_oleh' => $this->admin->id_pengguna,
        ]);

        $this->assertNotSame([], SantriManualService::halangan($s->refresh()));
        $this->actingAs($this->admin)->delete(route('santri_manual.destroy', $s->id))->assertSessionHas('error');
        $this->assertSame(1, Santri::count());
    }

    /**
     * Tagihan yang sudah BERJURNAL menahan penghapusan: piutangnya ada di buku
     * besar, dan membuang barisnya meninggalkannya tanpa lawan.
     */
    public function test_tagihan_berjurnal_menahan_penghapusan(): void
    {
        $this->kirim([], [$this->baris('akrual')])->assertRedirect();
        $s = Santri::firstOrFail();

        $halangan = SantriManualService::halangan($s);
        $this->assertNotSame([], $halangan);
        $this->assertStringContainsString('diakrualkan', implode(' ', $halangan));
    }

    /** Santri PPSB & hasil impor tak boleh dihapus lewat pintu ini. */
    public function test_menolak_menghapus_santri_dari_jalur_lain(): void
    {
        $ppsb = Santri::create([
            'no_pendaftaran' => 'PSB-2609-0001', 'nis' => '880001', 'nama' => 'Calon PPSB',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id,
        ]);

        $halangan = SantriManualService::halangan($ppsb);
        $this->assertNotSame([], $halangan);
        $this->assertStringContainsString('tidak dibuat lewat Input Manual', implode(' ', $halangan));

        $this->actingAs($this->admin)->delete(route('santri_manual.destroy', $ppsb->id))->assertSessionHas('error');
        $this->assertSame(1, Santri::count());
    }

    /** Pintunya milik admin — bukan modul hak yang bisa dicentangkan ke siapa pun. */
    public function test_hanya_admin(): void
    {
        $staf = User::create(['username' => 'zzsm_staf', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif']);

        $this->actingAs($staf)->get(route('santri_manual.create'))->assertForbidden();
        $this->actingAs($staf)->post(route('santri_manual.store'), ['nis' => '1'])->assertForbidden();

        $this->assertSame(0, Santri::count());
    }

    public function test_layar_terbuka(): void
    {
        $this->kirim()->assertRedirect();

        $this->actingAs($this->admin)->get(route('santri_manual.create'))
            ->assertOk()
            ->assertSee('Input Manual Santri Aktif')
            ->assertSee('Santri Pindahan')
            ->assertSee('Akrual sekarang');
    }
}
