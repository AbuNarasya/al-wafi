<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\TagihanLainService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TAGIHAN LAIN-LAIN — modul ini berjalan berbulan-bulan tanpa satu pun test.
 *
 * Yang dijaga di sini bukan "tagihannya terbit", melainkan tiga hal yang selama
 * ini diam-diam hilang di jalan: jatuh tempo & keterangan yang dibaca service
 * tetapi tak pernah dikirim layarnya, hasil penerbitan yang dihitung lalu
 * dibuang, dan sumber jurnal yang menyamar sebagai SPP.
 */
class TagihanLainTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTL';

    private const PIUTANG = '1.ZZTL.1';

    private const PENDAPATAN = '4.ZZTL.1';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zztl_petugas', 'nama' => 'Petugas Uji', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZTLU', 'nama_unit' => 'Unit Uji']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Tagihan Lain Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Santri Lainnya', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Lain-lain', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        // Berakun piutang ⇒ akrual, mengikuti aturan yang berlaku hari ini.
        JenisBiaya::create([
            'kode' => 'LDR', 'nama' => 'Laundry', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZTLU', 'status' => 'aktif',
        ]);
    }

    private function santri(string $nis, string $nama): Santri
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}", 'telepon_ayah' => '08'.$nis,
            'nama' => "Ayah {$nama}", 'telepon' => '08'.$nis, 'status' => 'aktif',
        ]);

        return Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    public function test_jatuh_tempo_dan_keterangan_dari_layar_ikut_tersimpan(): void
    {
        $santri = $this->santri('990001', 'Ahmad Fauzi');

        $this->actingAs($this->petugas)->post(route('tagihan_lain.store'), [
            'kode_jenis' => 'LDR',
            'id_santri' => [$santri->id],
            'nominal' => '87500',
            'periode' => '2026-08',
            'tanggal' => '2026-09-01',
            'jatuh_tempo' => '2026-09-10',
            'keterangan' => 'Laundry Agustus 2026',
        ])->assertRedirect(route('tagihan_lain.index'));

        $t = TagihanSantri::where('id_santri', $santri->id)->firstOrFail();
        $this->assertSame('2026-09-10', $t->jatuh_tempo->toDateString());
        $this->assertSame('Laundry Agustus 2026', $t->keterangan);
    }

    public function test_tanpa_keterangan_memakai_nama_jenis_biayanya(): void
    {
        $santri = $this->santri('990002', 'Bilal Ramadhan');

        $this->actingAs($this->petugas)->post(route('tagihan_lain.store'), [
            'kode_jenis' => 'LDR', 'id_santri' => [$santri->id],
            'nominal' => '50000', 'tanggal' => '2026-09-01',
        ])->assertRedirect(route('tagihan_lain.index'));

        $t = TagihanSantri::where('id_santri', $santri->id)->firstOrFail();
        $this->assertSame('Laundry', $t->keterangan);
        $this->assertNull($t->jatuh_tempo);
    }

    public function test_jatuh_tempo_sebelum_tanggal_tagihan_ditolak(): void
    {
        $santri = $this->santri('990003', 'Hafizh Nur Rahman');

        $this->actingAs($this->petugas)->post(route('tagihan_lain.store'), [
            'kode_jenis' => 'LDR', 'id_santri' => [$santri->id],
            'nominal' => '50000', 'tanggal' => '2026-09-01', 'jatuh_tempo' => '2026-08-20',
        ])->assertSessionHasErrors('jatuh_tempo');

        $this->assertSame(0, TagihanSantri::count());
    }

    public function test_jurnal_akrualnya_bersumber_dari_modul_tagihan_lain(): void
    {
        $santri = $this->santri('990004', 'Ikhsan Maulana');

        (new TagihanLainService)->terbitkan([
            'kode_jenis' => 'LDR', 'id_santri' => [$santri->id],
            'nominal' => '75000', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        // Dulu 'TagihanSpp', sehingga jurnal laundry tak bisa dipisahkan dari
        // jurnal SPP saat ditelusuri per modul.
        $this->assertSame(1, JournalEntry::where('sumber_modul', 'TagihanLain')->count());
        $this->assertSame(0, JournalEntry::where('sumber_modul', 'TagihanSpp')->count());
    }

    public function test_hasil_penerbitan_menyebut_jumlah_total_dan_nama_yang_dilewati(): void
    {
        $terbit = $this->santri('990005', 'Naufal Hakim');
        $sudahPunya = $this->santri('990006', 'Rizky Pratama');

        // Sudah punya tagihan Laundry periode yang sama ⇒ harus dilewati, dan
        // namanya harus disebut supaya petugas tahu itu wajar atau salah pilih.
        TagihanSantri::create([
            'id_santri' => $sudahPunya->id, 'kode_jenis' => 'LDR', 'perilaku' => 'lain',
            'periode' => '2026-08', 'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => '75000', 'sisa' => '75000', 'status' => 'belum_bayar', 'sudah_akrual' => true,
        ]);

        $pesan = $this->actingAs($this->petugas)->post(route('tagihan_lain.store'), [
            'kode_jenis' => 'LDR', 'id_santri' => [$terbit->id, $sudahPunya->id],
            'nominal' => '75000', 'periode' => '2026-08', 'tanggal' => '2026-09-01',
        ])->assertRedirect(route('tagihan_lain.index'))->getSession()->get('status');

        $this->assertStringContainsString('1 tagihan terbit', $pesan);
        $this->assertStringContainsString('Rp 75.000', $pesan);
        $this->assertStringContainsString('Rizky Pratama', $pesan);
        $this->assertStringNotContainsString('berhasil diterbitkan', $pesan);
    }

    public function test_santri_tidak_aktif_dilewati_dan_disebutkan(): void
    {
        $aktif = $this->santri('990007', 'Salman Alfarisi');
        $keluar = $this->santri('990008', 'Umar Abdullah');
        $keluar->update(['status' => 'keluar']);

        $pesan = $this->actingAs($this->petugas)->post(route('tagihan_lain.store'), [
            'kode_jenis' => 'LDR', 'id_santri' => [$aktif->id, $keluar->id],
            'nominal' => '60000', 'tanggal' => '2026-09-01',
        ])->assertRedirect(route('tagihan_lain.index'))->getSession()->get('status');

        $this->assertStringContainsString('1 dilewati karena sudah tidak berstatus aktif', $pesan);
        $this->assertSame(1, TagihanSantri::count());
    }

    public function test_pembatalan_tagihan_berakrual_menunjuk_koreksi_nominal_tagihan(): void
    {
        $santri = $this->santri('990009', 'Zaid Fadhlan');
        (new TagihanLainService)->terbitkan([
            'kode_jenis' => 'LDR', 'id_santri' => [$santri->id],
            'nominal' => '75000', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);
        $t = TagihanSantri::where('id_santri', $santri->id)->firstOrFail();

        // Pesan lamanya menyuruh "lewat jurnal balik di modul keuangan" — tempat
        // yang saat itu tak ada wujudnya sama sekali.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Koreksi Nominal Tagihan/');
        (new TagihanLainService)->batalkan($t->id);
    }
}
