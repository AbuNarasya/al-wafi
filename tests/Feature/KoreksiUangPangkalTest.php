<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Level;
use App\Models\PotonganGelombang;
use App\Models\PotonganUangPangkal;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\AngsuranUangPangkalService;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Koreksi nominal uang pangkal (salah input): hitung ulang + pagar keselamatan. */
class KoreksiUangPangkalTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZKU';
    private const KAS = '1.ZZKU.KAS';
    private const PEND_REG = '4.ZZKU.REG';
    private const PEND_UP = '4.ZZKU.UP';
    private const PIUT_UP = '1.ZZKU.PIUT';
    private const UNIT = 'ZZKUU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'KU']);
        foreach ([
            [self::KAS, 'Kas', 'debet'], [self::PEND_REG, 'Pend Reg', 'kredit'],
            [self::PEND_UP, 'Pend UP', 'kredit'], [self::PIUT_UP, 'Piutang UP', 'debet'],
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas', 'jenis_rekening' => 'tunai']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true])->id_pengguna;

        (new JenisBiayaService)->create(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND_REG, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        (new JenisBiayaService)->create(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_coa_pendapatan' => self::PEND_UP, 'kode_coa_piutang' => self::PIUT_UP, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    /** Calon yang sudah lulus + uang pangkal tertagih. */
    private function calonDenganUangPangkal(string $nominalNormal = '20000000'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'gelombang' => 1]);
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);
        $svc->tagihkanUangPangkal($santri->id, ['nominal' => $nominalNormal]);

        return $santri->refresh();
    }

    private function tagihanUp(Santri $santri): TagihanSantri
    {
        return TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'UP')->firstOrFail();
    }

    public function test_koreksi_menghitung_ulang_nominal_dan_sisa(): void
    {
        $santri = $this->calonDenganUangPangkal('20000000');
        $tagihan = $this->tagihanUp($santri);
        $this->assertSame(20000000.0, (float) $tagihan->nominal);

        // Bayar 5 juta (terverifikasi) lalu koreksi 20jt → 15jt.
        $bayar = (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $tagihan->id, 'tanggal' => now()->toDateString(),
            'nominal' => '5000000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');
        (new PembayaranSantriService)->verifikasi($bayar->id, $this->admin);

        $hasil = (new SantriService)->koreksiNominalUangPangkal($santri->id, [
            'nominal' => '15000000', 'alasan' => 'salah ketik nol',
        ], $this->admin);

        $this->assertSame(15000000.0, (float) $hasil->nominal);
        $this->assertSame(10000000.0, (float) $hasil->sisa); // 15jt − 5jt terbayar
        $this->assertSame('sebagian', $hasil->status);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'koreksi_nominal_uang_pangkal', 'id_pengguna' => $this->admin]);
    }

    public function test_koreksi_menghormati_potongan_gelombang(): void
    {
        PotonganGelombang::create(['tahun_ajaran' => self::TA, 'gelombang' => 1, 'potongan' => '2000000', 'masa_berlaku_hari' => 7, 'aktif' => true]);
        $santri = $this->calonDenganUangPangkal('20000000');
        $tagihan = $this->tagihanUp($santri);
        $this->assertSame(18000000.0, (float) $tagihan->nominal); // 20jt − 2jt

        $hasil = (new SantriService)->koreksiNominalUangPangkal($santri->id, [
            'nominal' => '25000000', 'alasan' => 'nominal normal keliru',
        ], $this->admin);

        $this->assertSame(23000000.0, (float) $hasil->nominal); // 25jt − 2jt potongan
        $this->assertSame(23000000.0, (float) $hasil->sisa);
        $potongan = PotonganUangPangkal::where('id_tagihan', $tagihan->id)->first();
        $this->assertSame(25000000.0, (float) $potongan->nominal_normal); // ikut terkoreksi
        $this->assertSame(2000000.0, (float) $potongan->potongan);        // potongan tak berubah
    }

    public function test_tolak_bila_sudah_diakrualkan(): void
    {
        $santri = $this->calonDenganUangPangkal('10000000');
        $svc = new SantriService;
        $svc->medcheck($santri->id, ['lolos' => true]);
        $svc->daftarUlang($santri->id, $this->admin);
        $this->assertTrue((bool) $this->tagihanUp($santri)->sudah_akrual);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah diakrualkan/i');
        $svc->koreksiNominalUangPangkal($santri->id, ['nominal' => '9000000', 'alasan' => 'x'], $this->admin);
    }

    public function test_tolak_bila_lebih_kecil_dari_yang_sudah_dibayar(): void
    {
        $santri = $this->calonDenganUangPangkal('20000000');
        $tagihan = $this->tagihanUp($santri);
        $bayar = (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $tagihan->id, 'tanggal' => now()->toDateString(),
            'nominal' => '8000000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');
        (new PembayaranSantriService)->verifikasi($bayar->id, $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/lebih kecil dari yang sudah dibayar/i');
        (new SantriService)->koreksiNominalUangPangkal($santri->id, ['nominal' => '5000000', 'alasan' => 'x'], $this->admin);
    }

    public function test_tolak_bila_ada_pembayaran_menunggu_verifikasi(): void
    {
        $santri = $this->calonDenganUangPangkal('20000000');
        (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $this->tagihanUp($santri)->id, 'tanggal' => now()->toDateString(),
            'nominal' => '1000000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/menunggu verifikasi/i');
        (new SantriService)->koreksiNominalUangPangkal($santri->id, ['nominal' => '18000000', 'alasan' => 'x'], $this->admin);
    }

    public function test_rencana_angsuran_aktif_dinonaktifkan(): void
    {
        $santri = $this->calonDenganUangPangkal('20000000');
        $tagihan = $this->tagihanUp($santri);
        (new AngsuranUangPangkalService)->buatRencana($santri->id, [
            'disepakati_pada' => now()->toDateString(),
            'termin' => [
                ['urutan' => 1, 'nominal' => '10000000', 'jatuh_tempo' => now()->addMonth()->toDateString()],
                ['urutan' => 2, 'nominal' => '10000000', 'jatuh_tempo' => now()->addMonths(2)->toDateString()],
            ],
        ], $this->admin);
        $this->assertTrue(RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->exists());

        (new SantriService)->koreksiNominalUangPangkal($santri->id, ['nominal' => '16000000', 'alasan' => 'koreksi'], $this->admin);

        $this->assertFalse(RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->exists());
        $lama = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->first();
        $this->assertSame('digantikan', $lama->status);
        $this->assertStringContainsString('dikoreksi', $lama->alasan);
    }

    public function test_tolak_bila_uang_pangkal_belum_ditagihkan(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '0812999']);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Belum', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler']);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/belum ditagihkan/i');
        (new SantriService)->koreksiNominalUangPangkal($santri->id, ['nominal' => '1000000', 'alasan' => 'x'], $this->admin);
    }
}
