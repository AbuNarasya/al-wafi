<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JALUR BEBAS UANG PANGKAL (mis. Anak Karyawan) & kolom kenaikan jenjang.
 *
 * Pembebasan sengaja BUKAN soal besaran tarif melainkan soal tagihannya terbit
 * atau tidak: nominal nol ditolak penjaga, dan tanpa baris tarif pun pencarian
 * turun ke tarif umum. Karena itu penandanya kolom tersendiri di master jalur.
 */
class JalurBebasUangPangkalTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const TA = '2026/2027';

    private const GRP = 'ZZBB';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzbb_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'jumlah_tingkat' => 6, 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3, 'urutan' => 2]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);

        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);
        JalurPendaftaran::create([
            'kode' => 'karyawan', 'nama' => 'Anak Karyawan', 'status' => 'aktif',
            'bebas_uang_pangkal' => true, 'kode_jalur_lanjutan' => 'karyawan',
        ]);

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => '4.ZZBB.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZBB.1', 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BusinessUnit::create(['kode_unit' => 'ZZUNIT', 'nama_unit' => 'Unit']);

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => '4.ZZBB.1', 'kode_unit' => 'ZZUNIT', 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'nominal' => '20000000',
            'kode_coa_pendapatan' => '4.ZZBB.1', 'kode_coa_piutang' => '1.ZZBB.1', 'kode_unit' => 'ZZUNIT', 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'PLK', 'nama' => 'Perlengkapan', 'tipe' => 'perlengkapan', 'nominal' => '5000000',
            'kode_coa_pendapatan' => '4.ZZBB.1', 'kode_coa_piutang' => '1.ZZBB.1', 'kode_unit' => 'ZZUNIT', 'tahun_ajaran' => self::TA]);

        // PEMBEBASAN kini ditegakkan lewat SEL TARIF bertanda Bebas, bukan lagi
        // lewat penanda `bebas_uang_pangkal` di master jalur — penanda itu tinggal
        // menjadi pengisi awal grid. Satu sumber kebenaran saat menagih.
        $this->pasangTarif(self::TA, 'SMP', 'karyawan', 'uang_pangkal', null, bebas: true);
    }

    private function calonLulus(string $jalur): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812'.rand(100000, 999999)]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Zaid', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => $jalur, 'kode_jenjang' => 'SMP', 'tingkat' => 1,
        ]);
        $santri->update(['status' => 'lolos_kesehatan']);

        return $santri->refresh();
    }

    public function test_jalur_bebas_tak_menerbitkan_tagihan_uang_pangkal(): void
    {
        $santri = $this->calonLulus('karyawan');

        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal_perlengkapan' => '5000000']);

        // Tak ada tagihan uang pangkal SAMA SEKALI — bukan bernominal nol.
        $this->assertNull($hasil['uang_pangkal']);
        $this->assertSame(0, TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'UP')->count());

        // Perlengkapannya tetap terbit.
        $this->assertSame(5000000.0, (float) $hasil['perlengkapan']->nominal);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_jalur_biasa_tetap_ditagih(): void
    {
        $santri = $this->calonLulus('reguler');

        $hasil = (new SantriService)->tagihkanUangPangkal($santri->id, [
            'nominal' => '20000000', 'nominal_perlengkapan' => '5000000',
        ]);

        $this->assertSame(20000000.0, (float) $hasil['uang_pangkal']->nominal);
    }

    /**
     * Tanpa ini santri berjalur bebas terkunci selamanya di "lolos kesehatan":
     * daftar ulang menuntut tagihan uang pangkal yang memang tak pernah terbit.
     */
    public function test_daftar_ulang_tetap_bisa_tanpa_tagihan_uang_pangkal(): void
    {
        $santri = $this->calonLulus('karyawan');
        (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal_perlengkapan' => '5000000']);

        (new SantriService)->daftarUlang($santri->id, $this->admin->id_pengguna);

        $santri->refresh();
        $this->assertSame('aktif', $santri->status);
        $this->assertNotNull($santri->nis);
        // Yang diakrualkan hanya perlengkapannya.
        $this->assertSame(1, JournalEntry::count());
    }

    /** Jalur biasa tetap wajib menagih uang pangkal sebelum daftar ulang. */
    public function test_jalur_biasa_tanpa_uang_pangkal_tetap_ditolak_daftar_ulang(): void
    {
        $santri = $this->calonLulus('reguler');

        $this->expectExceptionMessage('Uang pangkal belum ditagihkan');
        (new SantriService)->daftarUlang($santri->id, $this->admin->id_pengguna);
    }

    public function test_form_menyembunyikan_isian_uang_pangkal_bagi_jalur_bebas(): void
    {
        $bebas = $this->calonLulus('karyawan');
        $this->actingAs($this->admin)->get(route('santri.show', $bebas->id))->assertOk()
            ->assertSee('Bebas uang pangkal')
            ->assertDontSee('name="nominal"', false);

        $biasa = $this->calonLulus('reguler');
        $this->actingAs($this->admin)->get(route('santri.show', $biasa->id))->assertOk()
            ->assertSee('name="nominal"', false);

        // Kiriman tanpa nominal diterima untuk jalur bebas, ditolak untuk yang biasa.
        $this->actingAs($this->admin)
            ->post(route('santri.aksi', ['id' => $bebas->id, 'aksi' => 'tagih-uang-pangkal']), ['nominal_perlengkapan' => '5000000'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('santri.aksi', ['id' => $biasa->id, 'aksi' => 'tagih-uang-pangkal']), [])
            ->assertSessionHasErrors('nominal');
    }

    public function test_kolom_kenaikan_tersimpan_lewat_form_master(): void
    {
        // Jenjang: lanjutan tak boleh menunjuk dirinya sendiri.
        $this->actingAs($this->admin)->put(route('jenjang.update', 'SDTQ'), [
            'nama' => 'SDTQ', 'jumlah_tingkat' => 6, 'kode_jenjang_lanjutan' => 'SDTQ', 'status' => 'aktif',
        ])->assertSessionHasErrors('kode_jenjang_lanjutan');

        // Sekaligus menjaga jumlah_tingkat: keduanya sempat terbuang karena
        // service menulis daftar kolomnya satu per satu.
        $this->actingAs($this->admin)->put(route('jenjang.update', 'SDTQ'), [
            'nama' => 'SDTQ', 'jumlah_tingkat' => 5, 'kode_jenjang_lanjutan' => 'SMP', 'status' => 'aktif',
        ])->assertRedirect();
        $this->assertSame('SMP', Jenjang::findOrFail('SDTQ')->kode_jenjang_lanjutan);
        $this->assertSame(5, Jenjang::findOrFail('SDTQ')->jumlah_tingkat, 'jumlah tingkat ikut tersimpan lewat form');

        // Jenjang baru lewat form juga membawa kedua kolom itu.
        $this->actingAs($this->admin)->post(route('jenjang.store'), [
            'kode' => 'MA', 'nama' => 'MA', 'jumlah_tingkat' => 3, 'kode_jenjang_lanjutan' => 'SMP', 'status' => 'aktif',
        ])->assertRedirect();
        $baru = Jenjang::findOrFail('MA');
        $this->assertSame(3, $baru->jumlah_tingkat);
        $this->assertSame('SMP', $baru->kode_jenjang_lanjutan);
        $this->assertNull(Jenjang::findOrFail('SMP')->kode_jenjang_lanjutan, 'jenjang terakhir tetap kosong');

        // Jalur: boleh menunjuk dirinya sendiri (Anak Karyawan tetap Anak Karyawan).
        $this->actingAs($this->admin)->put(route('jalur_pendaftaran.update', 'reguler'), [
            'nama' => 'Reguler', 'kode_jalur_lanjutan' => 'karyawan', 'bebas_uang_pangkal' => '0', 'status' => 'aktif',
        ])->assertRedirect();

        $reguler = JalurPendaftaran::findOrFail('reguler');
        $this->assertSame('karyawan', $reguler->kode_jalur_lanjutan);
        $this->assertFalse($reguler->bebas_uang_pangkal);
        $this->assertTrue(JalurPendaftaran::findOrFail('karyawan')->bebas_uang_pangkal);
    }
}
