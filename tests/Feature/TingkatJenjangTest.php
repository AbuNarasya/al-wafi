<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TINGKAT (kelas) — jumlahnya milik master Jenjang, nilainya milik santri.
 *
 * Yang dijaga: tingkat tak boleh melampaui jenjangnya (SMP tak punya tingkat 6),
 * pendaftaran PPSB mewajibkannya, dan menu master data siswa terpecah per
 * jenjang mengikuti masternya — bukan daftar yang dipaku di kode.
 */
class TingkatJenjangTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZTK';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zztk_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Navigation::lupakan(); // menu per jenjang dimemo — jangan bocor antar-test
        Jenjang::create(['kode' => 'SDTQ', 'nama' => 'SDTQ', 'jumlah_tingkat' => 6, 'urutan' => 1]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3, 'urutan' => 2]);
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'jumlah_tingkat' => 3, 'urutan' => 3]);

        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'status' => 'aktif']);

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => '4.ZZTK.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZUNIT', 'nama_unit' => 'Unit']);
        (new JenisBiayaService)->create([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => '4.ZZTK.1', 'kode_unit' => 'ZZUNIT', 'tahun_ajaran' => self::TA,
        ]);
    }

    private function daftar(array $ganti = [])
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812'.rand(100000, 999999)]);

        return $this->actingAs($this->admin)->post(route('santri.store'), array_merge([
            'id_wali' => $wali->id, 'nama' => 'Zaid', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
            'kode_jenjang' => 'SMP', 'tingkat' => 2,
            'gelombang_mode' => 'tanpa',
        ], $ganti));
    }

    public function test_jumlah_tingkat_tersimpan_di_master_jenjang(): void
    {
        $this->assertSame(6, Jenjang::findOrFail('SDTQ')->jumlah_tingkat);
        $this->assertSame(3, Jenjang::findOrFail('SMP')->jumlah_tingkat);
        $this->assertSame(3, Jenjang::findOrFail('SMA')->jumlah_tingkat);

        // Dipakai form sebagai peta jenjang → jumlah tingkat.
        $this->assertSame(['SDTQ' => 6, 'SMP' => 3, 'SMA' => 3], Jenjang::petaTingkat());
        $this->assertSame([1 => 'Tingkat 1', 2 => 'Tingkat 2', 3 => 'Tingkat 3'], Jenjang::findOrFail('SMA')->opsiTingkat());
    }

    public function test_pendaftaran_menyimpan_tingkat(): void
    {
        $this->daftar()->assertSessionHasNoErrors()->assertRedirect();

        $santri = Santri::firstOrFail();
        $this->assertSame('SMP', $santri->kode_jenjang);
        $this->assertSame(2, $santri->tingkat);
    }

    public function test_pendaftaran_menolak_tanpa_jenjang_atau_tingkat(): void
    {
        $this->daftar(['tingkat' => ''])->assertSessionHasErrors('tingkat');
        $this->assertSame(0, Santri::count());

        // Tingkat tak punya arti tanpa jenjangnya, jadi jenjang ikut wajib.
        $this->daftar(['kode_jenjang' => ''])->assertSessionHasErrors('kode_jenjang');
        $this->assertSame(0, Santri::count());
    }

    /** Inti aturannya: tingkat dibatasi jenjang masing-masing. */
    public function test_tingkat_di_luar_jangkauan_jenjang_ditolak(): void
    {
        // SMP hanya 1–3; tingkat 6 milik SDTQ.
        $this->daftar(['kode_jenjang' => 'SMP', 'tingkat' => 6])
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'hanya tingkat 1–3'));
        $this->assertSame(0, Santri::count());

        // Angka yang sama sah di SDTQ.
        $this->daftar(['kode_jenjang' => 'SDTQ', 'tingkat' => 6])->assertSessionHasNoErrors();
        $this->assertSame(6, Santri::firstOrFail()->tingkat);
    }

    public function test_jenjang_tanpa_jumlah_tingkat_ditolak_dengan_pesan_menuntun(): void
    {
        Jenjang::create(['kode' => 'MA', 'nama' => 'MA', 'urutan' => 4]); // jumlah_tingkat kosong

        $this->daftar(['kode_jenjang' => 'MA', 'tingkat' => 1])
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'Jumlah tingkat jenjang "MA" belum diisi'));
    }

    /** Santri lama (impor) belum bertingkat — diisi lewat halaman detailnya. */
    public function test_tingkat_bisa_diisi_belakangan_lewat_aksi(): void
    {
        $this->daftar()->assertSessionHasNoErrors();
        $santri = Santri::firstOrFail();
        $santri->update(['tingkat' => null]);

        $this->actingAs($this->admin)
            ->post(route('santri.aksi', ['id' => $santri->id, 'aksi' => 'set-tingkat']), ['tingkat' => 3])
            ->assertRedirect();
        $this->assertSame(3, $santri->refresh()->tingkat);

        // Batas jenjangnya tetap berlaku di jalur ini.
        $this->expectException(AppException::class);
        (new SantriService)->setTingkat($santri->id, 5);
    }

    public function test_menu_master_data_terpecah_per_jenjang(): void
    {
        $this->actingAs($this->admin);
        $menu = collect(Navigation::items())->where('group', 'KEPENDIDIKAN')->where('sub', 'Master');

        $this->assertSame(
            ['Santri SDTQ', 'Santri SMP', 'Santri SMA'],
            $menu->pluck('label')->values()->all(),
            'urut mengikuti urutan master Jenjang',
        );
        $this->assertSame('/kesantrian/santri?jenjang=SDTQ', $menu->first()['url']);
        // Ketiganya memakai modul hak akses yang sama — tak ada hak baru.
        $this->assertSame(['santri'], $menu->pluck('modul')->unique()->values()->all());

        // Jenjang baru langsung menambah menunya, tanpa menyunting kode.
        Jenjang::create(['kode' => 'MA', 'nama' => 'MA', 'jumlah_tingkat' => 3, 'urutan' => 4]);
        Navigation::lupakan(); // menu dimemo per request
        $this->assertContains('Santri MA', collect(Navigation::items())->pluck('label')->all());
    }

    public function test_daftar_santri_tersaring_per_jenjang(): void
    {
        $this->daftar(['kode_jenjang' => 'SMP', 'tingkat' => 1])->assertSessionHasNoErrors();
        $this->daftar(['nama' => 'Umar', 'kode_jenjang' => 'SDTQ', 'tingkat' => 4])->assertSessionHasNoErrors();
        Santri::query()->update(['status' => 'aktif']);

        $this->actingAs($this->admin)->get('/kesantrian/santri?jenjang=SDTQ')->assertOk()
            ->assertSee('Umar')->assertDontSee('Zaid')
            ->assertSee('Tingkat 4');

        // Penyaring tingkat berdiri sendiri.
        $this->actingAs($this->admin)->get('/kesantrian/santri?tingkat=1')->assertOk()
            ->assertSee('Zaid')->assertDontSee('Umar');
    }
}
