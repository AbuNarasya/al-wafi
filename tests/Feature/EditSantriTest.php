<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUNTING DATA SANTRI.
 *
 * Sebelumnya data santri sama sekali tak bisa disunting dari aplikasi — satu-
 * satunya perubahan yang mungkin adalah tombol tahapan (status) dan tingkat.
 * Yang dijaga test ini bukan cuma "tersimpan", melainkan bahwa kolom yang
 * MENENTUKAN TARIF (tahun ajaran, jalur, gelombang) dan `status` TIDAK ikut
 * berubah: tagihan yang sudah terbit tidak dihitung ulang, jadi mengubahnya
 * lewat form biasa akan membuat data santri bertentangan dengan tagihannya.
 */
class EditSantriTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const TA = '2026/2027';

    private const GRP = 'ZZED';

    private User $admin;

    private Santri $santri;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzed_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'jumlah_tingkat' => 6, 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3, 'urutan' => 2]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => '4.ZZED.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZUNIT', 'nama_unit' => 'Unit']);
        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => '4.ZZED.1', 'kode_unit' => 'ZZUNIT', 'tahun_ajaran' => self::TA,
        ]);

        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '081200001']);
        $this->santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Zaid', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'gelombang' => 2,
            'kode_jenjang' => 'SDTQ', 'tingkat' => 5,
        ]);
        $this->santri->update(['status' => 'aktif', 'nis' => '230001']);
    }

    /** @return array<string,mixed> */
    private function isian(array $ganti = []): array
    {
        return array_merge([
            'id_wali' => $this->santri->id_wali, 'nama' => 'Zaid Abdullah', 'jenis_kelamin' => 'L',
            'nis' => '230001', 'nisn' => '0071234567',
            'tempat_lahir' => 'Bogor', 'tanggal_lahir' => '2012-03-04',
            'kode_jenjang' => 'SDTQ', 'tingkat' => 6,
            'asal_sekolah' => 'SDN 1',
        ], $ganti);
    }

    private function simpan(array $ganti = [])
    {
        return $this->actingAs($this->admin)
            ->put(route('santri.update', $this->santri->id), $this->isian($ganti));
    }

    public function test_form_ubah_bisa_dibuka_dan_terisi_data_lama(): void
    {
        $this->actingAs($this->admin)->get(route('santri.edit', $this->santri->id))->assertOk()
            ->assertSee('Zaid')
            ->assertSee($this->santri->no_pendaftaran)
            ->assertSee('Simpan Perubahan');
    }

    public function test_menyimpan_perubahan_data(): void
    {
        $this->simpan()->assertRedirect(route('santri.show', $this->santri->id))->assertSessionHas('status');

        $this->santri->refresh();
        $this->assertSame('Zaid Abdullah', $this->santri->nama);
        $this->assertSame('Bogor', $this->santri->tempat_lahir);
        $this->assertSame('0071234567', $this->santri->nisn);
        $this->assertSame(6, $this->santri->tingkat);
    }

    /** Inti penjagaannya: kolom penentu tarif & status tak tersentuh form ini. */
    public function test_kolom_penentu_tarif_dan_status_tak_ikut_berubah(): void
    {
        $this->simpan([
            'tahun_ajaran' => '2099/2100', 'jalur' => 'ngawur',
            'gelombang' => 9, 'status' => 'alumni', 'no_pendaftaran' => 'PALSU-1',
        ])->assertRedirect();

        $this->santri->refresh();
        $this->assertSame(self::TA, $this->santri->tahun_ajaran);
        $this->assertSame('reguler', $this->santri->jalur);
        $this->assertSame(2, $this->santri->gelombang);
        $this->assertSame('aktif', $this->santri->status);
        $this->assertStringStartsWith('PSB-', $this->santri->no_pendaftaran);
    }

    public function test_tingkat_tetap_dibatasi_jenjang_barunya(): void
    {
        // SMP hanya 1–3; tingkat 6 sah di SDTQ tetapi tidak di SMP.
        $this->simpan(['kode_jenjang' => 'SMP', 'tingkat' => 6])
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'hanya tingkat 1–3'));
        $this->assertSame('SDTQ', $this->santri->refresh()->kode_jenjang);

        // Pindah jenjang sekaligus tingkat yang sah → tersimpan.
        $this->simpan(['kode_jenjang' => 'SMP', 'tingkat' => 1])->assertRedirect();
        $this->santri->refresh();
        $this->assertSame('SMP', $this->santri->kode_jenjang);
        $this->assertSame(1, $this->santri->tingkat);
    }

    public function test_nis_ganda_ditolak(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Umar', 'telepon_ayah' => '081200002']);
        $lain = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => 'Umar', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
        ]);
        $lain->update(['status' => 'aktif', 'nis' => '230002']);

        $this->simpan(['nis' => '230002'])->assertSessionHasErrors('nis');
        $this->assertSame('230001', $this->santri->refresh()->nis);

        // NIS-nya sendiri tetap boleh disimpan ulang (tak dianggap bentrok).
        $this->simpan(['nis' => '230001'])->assertSessionHasNoErrors();
    }

    public function test_wajib_isi_nama_jenjang_dan_tingkat(): void
    {
        $this->simpan(['nama' => ''])->assertSessionHasErrors('nama');
        $this->simpan(['kode_jenjang' => ''])->assertSessionHasErrors('kode_jenjang');
        $this->simpan(['tingkat' => ''])->assertSessionHasErrors('tingkat');
    }

    public function test_tanpa_hak_ubah_ditolak(): void
    {
        $orang = User::create([
            'username' => 'zzed_biasa', 'nama' => 'Tanpa Hak', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);

        $this->actingAs($orang)->get(route('santri.edit', $this->santri->id))->assertForbidden();
        $this->actingAs($orang)->put(route('santri.update', $this->santri->id), $this->isian())->assertForbidden();
    }

    public function test_wali_bisa_dipindahkan(): void
    {
        $baru = Wali::create(['nama' => 'Wali Baru', 'telepon' => '081200003', 'status' => 'aktif']);

        $this->simpan(['id_wali' => $baru->id])->assertRedirect();

        $this->assertSame($baru->id, $this->santri->refresh()->id_wali);
    }
}
