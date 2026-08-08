<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Level;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PENGAKUAN & CARA TAGIH di master Jenis Biaya.
 *
 * Sejak keduanya berdiri sendiri, `pengakuan` dan `kode_coa_piutang` BISA
 * BERSELISIH — dan selisih itu tak lagi tertangkap oleh dirinya sendiri seperti
 * dulu, ketika terisinya akun piutang memang berarti akrual. Yang dijaga di sini
 * adalah penjaganya: akrual menuntut akun piutang, dan perilaku `lain` menuntut
 * cara tagihnya dinyatakan.
 */
class JenisBiayaPengakuanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZJB';

    private const PIUTANG = '1.ZZJB.1';

    private const PENDAPATAN = '4.ZZJB.1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzjb_admin', 'nama' => 'Admin Uji', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        BusinessUnit::create(['kode_unit' => 'ZZJBU', 'nama_unit' => 'Unit Uji']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Jenis Biaya Uji']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        TipeBiaya::firstOrCreate(['kode' => 'spp'],
            ['nama' => 'SPP', 'perilaku' => 'spp', 'urutan' => 3, 'bawaan' => true, 'status' => 'aktif']);
    }

    /** @param array<string,mixed> $ganti */
    private function kirim(array $ganti = [])
    {
        return $this->actingAs($this->admin)->post(route('jenis_biaya.store'), array_merge([
            'kode' => 'UJI-1', 'nama' => 'Uji', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN,
            'kode_unit' => 'ZZJBU', 'status' => 'aktif',
            'pengakuan' => 'kas', 'cara_tagih' => 'kepesertaan',
        ], $ganti));
    }

    public function test_pengakuan_akrual_menuntut_akun_piutang(): void
    {
        $this->kirim(['pengakuan' => 'akrual', 'kode_coa_piutang' => ''])
            ->assertSessionHasErrors('kode_coa_piutang');

        $this->assertNull(JenisBiaya::find('UJI-1'));
    }

    public function test_pengakuan_akrual_dengan_akun_piutang_diterima(): void
    {
        $this->kirim(['pengakuan' => 'akrual', 'kode_coa_piutang' => self::PIUTANG])
            ->assertSessionHasNoErrors();

        $this->assertSame('akrual', JenisBiaya::find('UJI-1')?->pengakuan);
    }

    public function test_perilaku_lain_wajib_menyatakan_cara_tagihnya(): void
    {
        $this->kirim(['cara_tagih' => ''])->assertSessionHasErrors('cara_tagih');
    }

    public function test_perilaku_selain_lain_tidak_dituntut_cara_tagih(): void
    {
        // SPP punya alur penagihannya sendiri; memaksanya memilih "pemakaian /
        // kepesertaan" hanya melahirkan isian yang tak berarti apa-apa.
        $this->kirim(['tipe' => 'spp', 'cara_tagih' => '', 'pengakuan' => 'akrual', 'kode_coa_piutang' => self::PIUTANG])
            ->assertSessionHasNoErrors();

        $this->assertNull(JenisBiaya::find('UJI-1')?->cara_tagih);
    }

    public function test_perilaku_selain_lain_tidak_menyimpan_cara_tagih(): void
    {
        // Isian yang disembunyikan di layar TETAP terkirim peramban. Sisa nilai
        // "pemakaian" tak boleh menempel pada baris SPP — ia tak dipakai siapa
        // pun, tapi terbaca sebagai kesengajaan oleh pembaca berikutnya.
        $this->kirim(['tipe' => 'spp', 'cara_tagih' => 'pemakaian',
            'pengakuan' => 'akrual', 'kode_coa_piutang' => self::PIUTANG])
            ->assertSessionHasNoErrors();

        $this->assertNull(JenisBiaya::find('UJI-1')?->cara_tagih);
    }

    public function test_pengakuan_bawaannya_kas_bukan_akrual(): void
    {
        // Baris yang lahir tanpa menyebut pengakuannya lebih baik tidak menjurnal
        // apa pun daripada diam-diam menaruh piutang di buku besar.
        $jb = JenisBiaya::create([
            'kode' => 'UJI-2', 'nama' => 'Tanpa Pengakuan', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_unit' => 'ZZJBU', 'status' => 'aktif',
        ]);

        $this->assertSame('kas', $jb->fresh()->pengakuan);
    }

    public function test_tagihan_lain_punya_grup_sidebar_sendiri(): void
    {
        $grup = 'TAGIHAN LAIN-LAIN';

        $this->assertContains($grup, Navigation::GROUP_ORDER);
        $this->assertSame(['Setting Tagihan Lain Lain', 'Transaksi'], Navigation::SUB_ORDER[$grup]);

        // Sampai sebelum ini modul tagihan lain-lain sama sekali tak punya item
        // sidebar — satu-satunya jalan masuk adalah mengetik URL-nya.
        $item = array_values(array_filter(Navigation::ITEMS, fn ($m) => ($m['group'] ?? null) === $grup));
        $this->assertNotSame([], $item);

        // Dua matriks besaran memakai MODUL BERBEDA: keduanya menetapkan uang,
        // tapi atas dasar yang berbeda dan biasanya diurus orang yang berbeda.
        $modul = array_column($item, 'modul');
        $this->assertContains('tarif-kepesertaan', $modul);
        $this->assertContains('tarif-pemakaian', $modul);
        $this->assertContains('setoran-laundry', $modul);
    }
}
