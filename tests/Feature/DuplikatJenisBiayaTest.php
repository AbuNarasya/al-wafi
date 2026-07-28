<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\JenisBiayaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplikat master Jenis Biaya ke tahun ajaran baru.
 *
 * Yang dikunci: kode baru dibentuk dengan menukar dua digit tahun (kode adalah
 * primary key, jadi tak boleh sekadar disalin), baris yang bentrok DILEWATI
 * bukan menggagalkan seluruh proses, dan penjaga cakupan tunggal tetap berlaku.
 */
class DuplikatJenisBiayaTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZDP';
    private const PEND = '4.ZZDP.PEND';
    private const PIUT = '1.ZZDP.PIUT';
    private const UNIT = 'ZZDPU';
    private const LAMA = '2027/2028';
    private const BARU = '2028/2029';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'DP']);
        foreach ([[self::PEND, 'Pendapatan', 'kredit'], [self::PIUT, 'Piutang', 'debet']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::LAMA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        TahunAjaran::create(['kode' => self::BARU, 'status' => 'aktif']);
        JalurPendaftaran::create(['kode' => 'OSS', 'nama' => 'SMP (OSS)', 'tahun_ajaran' => self::LAMA]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);
        $this->admin = User::create([
            'username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        (new JenisBiayaService)->create([
            'kode' => 'REG-SMP27', 'nama' => 'Registrasi SMP 2027', 'tipe' => 'registrasi', 'nominal' => '750000',
            'kode_jenjang' => 'SMP', 'kode_coa_pendapatan' => self::PEND,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::LAMA,
        ]);
        (new JenisBiayaService)->create([
            'kode' => 'UP-SMP27-OSS', 'nama' => 'Uang Pangkal SMP OSS 2027', 'tipe' => 'uang_pangkal', 'nominal' => '70000000',
            'kode_jenjang' => 'SMP', 'kode_jalur' => 'OSS', 'kode_coa_pendapatan' => self::PEND,
            'kode_coa_piutang' => self::PIUT, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::LAMA,
        ]);
    }

    public function test_kode_dan_nama_menyesuaikan_tahun_baru(): void
    {
        $rencana = collect((new JenisBiayaService)->pratinjauDuplikat(self::LAMA, self::BARU))->keyBy('kode');

        $this->assertSame('REG-SMP28', $rencana['REG-SMP27']['kode_baru']);
        $this->assertSame('Registrasi SMP 2028', $rencana['REG-SMP27']['nama_baru']);
        // Dua digit tahun di TENGAH kode ikut ditukar, akhirannya tetap.
        $this->assertSame('UP-SMP28-OSS', $rencana['UP-SMP27-OSS']['kode_baru']);
        $this->assertSame('siap', $rencana['UP-SMP27-OSS']['status']);
    }

    public function test_menyalin_seluruh_atribut(): void
    {
        $hasil = (new JenisBiayaService)->duplikat(self::LAMA, self::BARU);
        $this->assertSame(2, $hasil['disalin']);

        $baru = JenisBiaya::findOrFail('UP-SMP28-OSS');
        $this->assertSame(self::BARU, $baru->tahun_ajaran);
        $this->assertSame('SMP', $baru->kode_jenjang);
        $this->assertSame('OSS', $baru->kode_jalur);
        $this->assertSame(70000000.0, (float) $baru->nominal);
        $this->assertSame(self::PIUT, $baru->kode_coa_piutang);
        $this->assertSame(self::UNIT, $baru->kode_unit);

        // Baris asalnya tak tersentuh.
        $this->assertSame(self::LAMA, JenisBiaya::findOrFail('UP-SMP27-OSS')->tahun_ajaran);
    }

    public function test_baris_bentrok_dilewati_bukan_menggagalkan(): void
    {
        // Cakupan registrasi SMP sudah diisi lebih dulu di T.A tujuan.
        (new JenisBiayaService)->create([
            'kode' => 'REG-SMP28', 'nama' => 'Registrasi SMP 2028', 'tipe' => 'registrasi', 'nominal' => '800000',
            'kode_jenjang' => 'SMP', 'kode_coa_pendapatan' => self::PEND,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::BARU,
        ]);

        $hasil = (new JenisBiayaService)->duplikat(self::LAMA, self::BARU);

        $this->assertSame(1, $hasil['disalin'], 'Uang pangkal tetap tersalin.');
        $this->assertSame(1, $hasil['dilewati']);
        // Yang sudah ada TIDAK ditimpa nominalnya.
        $this->assertSame(800000.0, (float) JenisBiaya::findOrFail('REG-SMP28')->nominal);
        $this->assertNotNull(JenisBiaya::find('UP-SMP28-OSS'));
    }

    public function test_menolak_tujuan_sama_dengan_sumber(): void
    {
        $this->expectException(AppException::class);
        (new JenisBiayaService)->duplikat(self::LAMA, self::LAMA);
    }

    public function test_menolak_bila_tak_ada_yang_bisa_disalin(): void
    {
        (new JenisBiayaService)->duplikat(self::LAMA, self::BARU);

        // Dijalankan kedua kalinya: semuanya sudah ada.
        try {
            (new JenisBiayaService)->duplikat(self::LAMA, self::BARU);
            $this->fail('Seharusnya menolak karena tak ada yang bisa disalin.');
        } catch (AppException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(4, JenisBiaya::count(), 'Tak ada baris ganda yang terlanjur dibuat.');
        }
    }

    public function test_alur_lengkap_lewat_halaman(): void
    {
        $this->actingAs($this->admin)
            ->get(route('jenis_biaya.duplikat_form', ['sumber' => self::LAMA, 'tujuan' => self::BARU]))
            ->assertOk()
            ->assertSee('REG-SMP28')
            ->assertSee('Akan disalin');

        $this->actingAs($this->admin)
            ->post(route('jenis_biaya.duplikat'), ['sumber' => self::LAMA, 'tujuan' => self::BARU])
            ->assertRedirect(route('jenis_biaya.index'))
            ->assertSessionHas('status');

        $this->assertSame(2, JenisBiaya::where('tahun_ajaran', self::BARU)->count());
    }

    public function test_kode_tanpa_pola_tahun_diberi_akhiran(): void
    {
        (new JenisBiayaService)->create([
            'kode' => 'SERAGAM', 'nama' => 'Seragam', 'tipe' => 'lain', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::LAMA,
        ]);

        $rencana = collect((new JenisBiayaService)->pratinjauDuplikat(self::LAMA, self::BARU))->keyBy('kode');

        $this->assertSame('SERAGAM-28', $rencana['SERAGAM']['kode_baru']);
    }
}
