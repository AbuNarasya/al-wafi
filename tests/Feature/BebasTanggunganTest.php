<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\BebasTanggunganService;
use App\Services\Modules\DompetService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * BEBAS TANGGUNGAN + PENARIKAN TITIPAN.
 *
 * Dua lubang yang saling melengkapi. Sebelum ini santri bisa diluluskan sambil
 * menyisakan piutang DAN menyisakan saldo dompet — dan saldo itu tak punya
 * jalan keluar sama sekali, karena modul dompet hanya bisa diisi. Nilai enum
 * `tarik` dan kolom `kunci_tarik` sudah ada di skema sejak awal; kodenya yang
 * tak pernah ditulis.
 */
class BebasTanggunganTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZBT';

    private const PEND = '4.ZZBT.PEND';

    private const PIUT = '1.ZZBT.PIUT';

    private const KAS = '1.ZZBT.KAS';

    private const UNIT = 'ZZBTU';

    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji']);
        foreach ([
            [self::PEND, 'Pendapatan SPP', 'kredit'],
            [self::PIUT, 'Piutang Santri', 'debet'],
            [self::KAS, 'Kas', 'debet'],
            [DompetPolicy::COA_TITIPAN['wali'], 'Titipan Dompet Wali', 'kredit'],
            [DompetPolicy::COA_TITIPAN['santri'], 'Titipan Dompet Santri', 'kredit'],
            [DompetPolicy::COA_TITIPAN['tabungan'], 'Titipan Tabungan Santri', 'kredit'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Level::create(['kode_level' => 'LBT', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);

        $this->admin = User::create([
            'username' => 'admbt', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LBT', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '250000',
            'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'berulang' => true]);
    }

    private function santri(string $nama = 'Ahmad'): Santri
    {
        $wali = (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Budi',
            'telepon_ayah' => '08'.random_int(100000, 999999),
        ]);
        $s = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SD', 'gelombang' => 1,
        ]);
        $s->update(['status' => 'aktif', 'tingkat' => 1]);

        // Tagihan registrasi bawaan dilunaskan supaya titik awalnya bersih.
        DB::table('tagihan_santri')->where('id_santri', $s->id)->update(['sisa' => 0, 'status' => 'lunas']);

        return $s->refresh();
    }

    private function dompet(Santri $s, string $saldo, bool $kunci = false): DompetSantri
    {
        return DompetSantri::create(['id_santri' => $s->id, 'saldo' => $saldo, 'kunci_tarik' => $kunci]);
    }

    // ---- Pemeriksaan dua arah ----

    public function test_santri_tanpa_tagihan_dan_tanpa_titipan_dinyatakan_bersih(): void
    {
        $s = $this->santri();

        $this->assertTrue((new BebasTanggunganService)->periksa($s->id)['bersih']);
    }

    public function test_tagihan_bersisa_membuat_belum_bebas(): void
    {
        $s = $this->santri();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);

        $h = (new BebasTanggunganService)->periksa($s->id);

        $this->assertFalse($h['bersih']);
        $this->assertSame('250000.00', $h['tagihan']['total']);
    }

    public function test_titipan_yang_belum_dikembalikan_juga_membuat_belum_bebas(): void
    {
        // Inilah arah yang paling sering terlewat: bukan santri yang berhutang,
        // melainkan pesantren yang masih memegang uangnya.
        $s = $this->santri();
        $this->dompet($s, '75000');
        TabunganSantri::create(['id_santri' => $s->id, 'saldo' => '25000']);

        $h = (new BebasTanggunganService)->periksa($s->id);

        $this->assertFalse($h['bersih']);
        $this->assertSame('0.00', $h['tagihan']['total']);
        $this->assertSame('100000.00', $h['titipan']['total']);
    }

    public function test_pesan_penolakan_menyebutkan_angkanya(): void
    {
        $s = $this->santri();
        $this->dompet($s, '75000');

        try {
            (new BebasTanggunganService)->assertTitipanDikembalikan($s->id);
            $this->fail('seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('75.000', $e->getMessage());
            $this->assertStringContainsString('menitipkan', $e->getMessage());
        }
    }

    public function test_boleh_dilepas_dengan_alasan_tertulis(): void
    {
        $s = $this->santri();
        $this->dompet($s, '75000');

        // Walinya kadang memang tak bisa dihubungi lagi — jalan lewatnya ada,
        // tetapi menuntut alasan.
        $h = (new BebasTanggunganService)->assertTitipanDikembalikan($s->id, true, 'Keluarganya pindah kota, sisa titipan dihibahkan.');
        $this->assertFalse($h['bersih']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/alasannya wajib ditulis/');
        (new BebasTanggunganService)->assertTitipanDikembalikan($s->id, true, '   ');
    }

    /**
     * TAGIHAN tidak ditahan — `keluarkanSantriAktif()` memang sudah membalik
     * akrual sisa uang pangkal & perlengkapan. Menahan kepergian karena tagihan
     * berarti menahan santri yang justru keluar karena tak sanggup membayar.
     */
    public function test_tagihan_bersisa_tidak_menahan_kepergian(): void
    {
        $s = $this->santri();
        (new SppService)->generate(['periode' => '2026-07', 'tanggal' => '2026-07-01'], $this->admin);

        (new SantriService)->mengundurkanDiri($s->id, 'Pindah sekolah.', $this->admin);

        $this->assertSame('keluar', Santri::find($s->id)->status);
    }

    public function test_titipan_menahan_kepergian_sampai_dikembalikan(): void
    {
        $s = $this->santri();
        $this->dompet($s, '50000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/masih menitipkan/');
        (new SantriService)->mengundurkanDiri($s->id, 'Pindah sekolah.', $this->admin);
    }

    public function test_santri_bersih_bisa_diundurkan_seperti_biasa(): void
    {
        $s = $this->santri();

        (new SantriService)->mengundurkanDiri($s->id, 'Pindah sekolah.', $this->admin);

        $this->assertSame('keluar', Santri::find($s->id)->status);
    }

    // ---- Penarikan titipan ----

    public function test_penarikan_mengurangi_saldo_dan_berjurnal(): void
    {
        $s = $this->santri();
        $d = $this->dompet($s, '100000');

        $mutasi = (new DompetService)->tarik([
            'pemilik' => 'santri', 'id_dompet' => $d->id, 'nominal' => '40000',
            'tanggal' => '2026-08-01', 'kode_rekening' => self::KAS, 'penerima' => 'Budi (ayah)',
        ], $this->admin);

        $this->assertSame('tarik', $mutasi->jenis);
        $this->assertSame('terverifikasi', $mutasi->status);
        $this->assertSame('60000.00', $mutasi->saldo_setelah);
        $this->assertSame('60000.00', DompetSantri::find($d->id)->saldo);

        // D Titipan / K Kas — kebalikan persis dari top-up.
        $lines = JournalLine::where('entry_id', $mutasi->journal_entry_id)->get()->keyBy('kode_coa');
        $this->assertSame('40000.00', $lines[DompetPolicy::COA_TITIPAN['santri']]->debet);
        $this->assertSame('40000.00', $lines[self::KAS]->kredit);
    }

    public function test_penarikan_melebihi_saldo_ditolak(): void
    {
        $s = $this->santri();
        $d = $this->dompet($s, '30000');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/tidak cukup/');
        (new DompetService)->tarik([
            'pemilik' => 'santri', 'id_dompet' => $d->id, 'nominal' => '50000',
            'tanggal' => '2026-08-01', 'kode_rekening' => self::KAS,
        ], $this->admin);
    }

    public function test_kunci_tarik_dihormati(): void
    {
        // Kolomnya sudah ada di skema sejak awal justru untuk ini.
        $s = $this->santri();
        $d = $this->dompet($s, '100000', kunci: true);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/dikunci dari penarikan/');
        (new DompetService)->tarik([
            'pemilik' => 'santri', 'id_dompet' => $d->id, 'nominal' => '10000',
            'tanggal' => '2026-08-01', 'kode_rekening' => self::KAS,
        ], $this->admin);
    }

    public function test_penarikan_penuh_membuat_santri_jadi_bebas_tanggungan(): void
    {
        // Alur yang sesungguhnya dituju: kembalikan titipannya, lalu santrinya
        // boleh pergi.
        $s = $this->santri();
        $d = $this->dompet($s, '100000');

        $this->assertFalse((new BebasTanggunganService)->periksa($s->id)['bersih']);

        (new DompetService)->tarik([
            'pemilik' => 'santri', 'id_dompet' => $d->id, 'nominal' => '100000',
            'tanggal' => '2026-08-01', 'kode_rekening' => self::KAS, 'penerima' => 'Budi (ayah)',
        ], $this->admin);

        $this->assertTrue((new BebasTanggunganService)->periksa($s->id)['bersih']);
        (new SantriService)->mengundurkanDiri($s->id, 'Pindah sekolah.', $this->admin);
        $this->assertSame('keluar', Santri::find($s->id)->status);
    }

    public function test_penarikan_meninggalkan_jejak_audit(): void
    {
        $s = $this->santri();
        $d = $this->dompet($s, '100000');

        (new DompetService)->tarik([
            'pemilik' => 'santri', 'id_dompet' => $d->id, 'nominal' => '20000',
            'tanggal' => '2026-08-01', 'kode_rekening' => self::KAS, 'penerima' => 'Budi',
        ], $this->admin);

        $this->assertDatabaseHas('activity_log', ['aksi' => 'tarik_dompet', 'modul' => 'dompet']);
        $this->assertSame(1, MutasiDompet::where('jenis', 'tarik')->count());
    }

    // ---- Layar ----

    public function test_halaman_bebas_tanggungan_menyorot_yang_belum_bersih(): void
    {
        $bersih = $this->santri('Santri Bersih');
        $bermasalah = $this->santri('Santri Bertitipan');
        $this->dompet($bermasalah, '90000');

        $this->actingAs(User::find($this->admin))
            ->get(route('bebas_tanggungan.index'))
            ->assertOk()
            ->assertSee('Bebas Tanggungan')
            ->assertSee('Santri Bertitipan')
            // Yang sudah bersih disembunyikan secara bawaan — daftar ini gunanya
            // memperlihatkan pekerjaan yang tersisa, bukan seluruh santri.
            ->assertDontSee('Santri Bersih');

        $this->actingAs(User::find($this->admin))
            ->get(route('bebas_tanggungan.index', ['semua' => 1]))
            ->assertOk()
            ->assertSee('Santri Bersih');
    }
}
