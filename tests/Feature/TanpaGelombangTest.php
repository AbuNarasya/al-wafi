<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\PotonganUangPangkal;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PotonganGelombangService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Tanpa Gelombang" (santri pindahan & kasus khusus) tak pernah dapat potongan gelombang. */
class TanpaGelombangTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZTG';
    private const PEND = '4.ZZTG.REG';
    private const PIUT = '1.ZZTG.PIUT';
    private const UNIT = 'ZZTGU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'TG']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true])->id_pengguna;

        (new JenisBiayaService)->create(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        (new JenisBiayaService)->create(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);

        // Potongan gelombang 1 yang menggiurkan — tak boleh menempel ke santri tanpa gelombang.
        PotonganGelombang::create(['tahun_ajaran' => self::TA, 'gelombang' => 1, 'potongan' => '2000000', 'masa_berlaku_hari' => 7, 'aktif' => true]);
    }

    private function buatSantri(?int $gelombang): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create([
            'id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => $gelombang,
        ]);
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);

        return $santri->refresh();
    }

    public function test_santri_tanpa_gelombang_tidak_dapat_potongan(): void
    {
        $santri = $this->buatSantri(null);
        $this->assertNull($santri->gelombang);

        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '20000000'])['uang_pangkal'];

        // Terbit penuh — potongan gelombang 1 TIDAK menempel.
        $this->assertSame(20000000.0, (float) $tagihan->nominal);
        $this->assertNull(PotonganUangPangkal::where('id_tagihan', $tagihan->id)->first());
    }

    public function test_santri_bergelombang_tetap_dapat_potongan(): void
    {
        $santri = $this->buatSantri(1);

        $tagihan = (new SantriService)->tagihkanUangPangkal($santri->id, ['nominal' => '20000000'])['uang_pangkal'];

        $this->assertSame(18000000.0, (float) $tagihan->nominal); // 20jt − 2jt
        $this->assertSame(2000000.0, (float) PotonganUangPangkal::where('id_tagihan', $tagihan->id)->first()->potongan);
    }

    public function test_potongan_aktif_mengembalikan_null_untuk_gelombang_kosong(): void
    {
        $svc = new PotonganGelombangService;

        $this->assertNull($svc->potonganAktif(null, 'SD', self::TA));
        $this->assertNull($svc->potonganAktif(null, null, self::TA));
        $this->assertNotNull($svc->potonganAktif(1, null, self::TA));
    }

    public function test_form_menolak_bila_gelombang_tidak_dipilih_sadar(): void
    {
        $this->actingAs(User::find($this->admin));
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0899888']);
        $dasar = [
            'id_wali' => $wali->id, 'nama' => 'Zaid', 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
        ];

        // Tanpa memilih mode → ditolak.
        $this->post(route('santri.store'), $dasar)->assertSessionHasErrors('gelombang_mode');

        // Mode "nomor" tapi angkanya kosong → ditolak.
        $this->post(route('santri.store'), $dasar + ['gelombang_mode' => 'nomor'])->assertSessionHasErrors('gelombang');

        // Mode "tanpa" → lolos & tersimpan NULL.
        $this->post(route('santri.store'), $dasar + ['gelombang_mode' => 'tanpa', 'gelombang' => '9'])
            ->assertSessionHasNoErrors();
        $this->assertNull(Santri::where('nama', 'Zaid')->first()->gelombang);
    }
}
