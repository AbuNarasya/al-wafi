<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\AutoDebetService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LUNAS SEKALIGUS untuk tagihan lain-lain.
 *
 * Sebelum ini laundry Rp 87.500 dengan saldo dompet Rp 40.000 TERPOTONG
 * Rp 40.000 dan berubah jadi tagihan cicilan — tanpa siapa pun menekan apa pun,
 * dan tanpa modul angsuran yang menaunginya. Sisanya lalu menggantung sebagai
 * "sebagian" yang tak pernah direncanakan siapa-siapa.
 *
 * Yang dijaga di sini termasuk batasnya: uang pangkal TETAP boleh dicicil (ia
 * punya modul Angsuran), dan SPP tetap boleh dibayar sebagian DI LOKET meski
 * auto-debetnya penuh-atau-tidak.
 */
class TagihanLainLunasSekaligusTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZLS';

    private const PENDAPATAN = '4.ZZLS.1';

    private const PIUTANG = '1.ZZLS.1';

    private const KAS = '1.ZZLS.9';

    private const TA = '2026/2027';

    private User $petugas;

    private Wali $wali;

    private Santri $santri;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zzls_petugas', 'nama' => 'Petugas', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZLSU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Lunas Sekaligus Uji']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Kas', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Loket', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);

        foreach ([['lain', 'Lain-lain', 4], ['uang_pangkal', 'Uang Pangkal', 2]] as [$kode, $nama, $urut]) {
            TipeBiaya::firstOrCreate(['kode' => $kode],
                ['nama' => $nama, 'perilaku' => $kode, 'urutan' => $urut, 'bawaan' => true, 'status' => 'aktif']);
        }

        foreach ([['LDR', 'Laundry', 'lain'], ['UP', 'Uang Pangkal', 'uang_pangkal']] as [$kode, $nama, $tipe]) {
            JenisBiaya::create([
                'kode' => $kode, 'nama' => $nama, 'tipe' => $tipe,
                'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
                'kode_unit' => 'ZZLSU', 'status' => 'aktif', 'pengakuan' => 'akrual',
                'cara_tagih' => $tipe === 'lain' ? 'pemakaian' : null,
            ]);
        }

        $this->wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Uji', 'telepon_ayah' => '0899',
            'nama' => 'Bapak Uji', 'telepon' => '0899', 'status' => 'aktif', 'auto_debet' => true,
        ]);
        $this->santri = Santri::create([
            'no_pendaftaran' => 'UJI-1', 'nis' => '770001', 'nama' => 'Santri Uji',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id,
        ]);
    }

    private function tagihan(string $kodeJenis, string $perilaku, string $nominal): TagihanSantri
    {
        return TagihanSantri::create([
            'id_santri' => $this->santri->id, 'kode_jenis' => $kodeJenis, 'perilaku' => $perilaku,
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar', 'sudah_akrual' => true,
        ]);
    }

    private function isiDompet(string $saldo): DompetWali
    {
        return DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => $saldo]);
    }

    public function test_auto_debet_tidak_memotong_tagihan_lain_bila_saldo_kurang(): void
    {
        $t = $this->tagihan('LDR', 'lain', '87500');
        $this->isiDompet('40000');

        $hasil = (new AutoDebetService)->jalankan($this->petugas->id_pengguna, '2026-09-01');

        $this->assertSame(0, $hasil['tagihan'], 'Saldo kurang: tak boleh terpotong sebagian.');
        $this->assertSame('belum_bayar', $t->fresh()->status);
        $this->assertSame(0, bccomp($t->fresh()->sisa, '87500', 2));
        $this->assertSame(0, bccomp(DompetWali::first()->saldo, '40000', 2), 'Saldonya utuh.');
    }

    public function test_auto_debet_melunasi_tagihan_lain_begitu_saldo_cukup(): void
    {
        $t = $this->tagihan('LDR', 'lain', '87500');
        $this->isiDompet('100000');

        $hasil = (new AutoDebetService)->jalankan($this->petugas->id_pengguna, '2026-09-01');

        $this->assertSame(1, $hasil['tagihan']);
        $this->assertSame('lunas', $t->fresh()->status);
    }

    public function test_uang_pangkal_tetap_boleh_terpotong_sebagian(): void
    {
        // Batas aturannya: uang pangkal punya modul Angsuran tersendiri, jadi
        // cicilan di sana memang disengaja dan tak boleh ikut terlarang.
        $t = $this->tagihan('UP', 'uang_pangkal', '1000000');
        $this->isiDompet('250000');

        $hasil = (new AutoDebetService)->jalankan($this->petugas->id_pengguna, '2026-09-01');

        $this->assertSame(1, $hasil['tagihan']);
        $this->assertSame('sebagian', $t->fresh()->status);
        $this->assertSame(0, bccomp($t->fresh()->sisa, '750000', 2));
    }

    public function test_pembayaran_manual_sebagian_untuk_tagihan_lain_ditolak(): void
    {
        $t = $this->tagihan('LDR', 'lain', '87500');

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/harus dilunasi sekaligus/');
        (new PembayaranSantriService)->catat([
            'id_tagihan' => $t->id, 'id_santri' => $this->santri->id,
            'tanggal' => '2026-09-01', 'nominal' => '40000',
            'kode_rekening' => self::KAS, 'metode' => 'tunai',
        ], $this->petugas->id_pengguna, 'kesantrian');
    }

    public function test_pembayaran_manual_penuh_untuk_tagihan_lain_diterima(): void
    {
        $t = $this->tagihan('LDR', 'lain', '87500');

        $bayar = (new PembayaranSantriService)->catat([
            'id_tagihan' => $t->id, 'id_santri' => $this->santri->id,
            'tanggal' => '2026-09-01', 'nominal' => '87500',
            'kode_rekening' => self::KAS, 'metode' => 'tunai',
        ], $this->petugas->id_pengguna, 'kesantrian');

        $this->assertSame(0, bccomp($bayar->nominal, '87500', 2));
    }
}
