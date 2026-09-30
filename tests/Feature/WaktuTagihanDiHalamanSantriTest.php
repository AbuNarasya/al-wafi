<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kartu Tagihan di halaman santri menampilkan KAPAN tagihan terbit dan KAPAN
 * dibayar — supaya petugas bisa menelusurinya ke rekening koran tanpa membuka
 * modul pembayaran satu per satu.
 */
class WaktuTagihanDiHalamanSantriTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZWT';

    private const KAS = '1.ZZWT.BCA';

    private User $admin;

    private Santri $santri;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzwt_adm', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => '2026/2027', 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZWTU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Uji']);
        CoaDetail::create(['kode_coa' => self::KAS, 'nama_coa' => 'Bank BCA', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => '4.ZZWT.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'BCA Operasional', 'jenis_rekening' => 'bank', 'status' => 'aktif']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        JenisBiaya::create([
            'kode' => 'LDR-UJI', 'nama' => 'Laundry', 'tipe' => 'lain',
            'kode_coa_pendapatan' => '4.ZZWT.1', 'kode_unit' => 'ZZWTU', 'status' => 'aktif',
        ]);

        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Uji', 'telepon_ayah' => '0812',
            'nama' => 'Bapak Uji', 'telepon' => '0812', 'status' => 'aktif',
        ]);
        $this->santri = Santri::create([
            'no_pendaftaran' => 'UJI-WT1', 'nis' => '990101', 'nama' => 'Santri Uji',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 7,
            'tahun_ajaran' => '2026/2027', 'tahun_ajaran_berjalan' => '2026/2027',
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    private function tagihan(string $dibuat): TagihanSantri
    {
        Carbon::setTestNow($dibuat);
        $t = TagihanSantri::create([
            'id_santri' => $this->santri->id, 'kode_jenis' => 'LDR-UJI', 'perilaku' => 'lain',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => '2026/2027',
            'nominal' => '300000', 'sisa' => '300000', 'status' => 'belum_bayar',
            'sudah_akrual' => false, 'jatuh_tempo' => '2026-09-25',
        ]);
        Carbon::setTestNow();

        return $t;
    }

    private function bayar(TagihanSantri $t, string $tanggal, string $nominal, string $dicatat, string $status = 'terverifikasi', ?string $diverifikasi = null): void
    {
        Carbon::setTestNow($dicatat);
        PembayaranSantri::create([
            'nomor' => 'BYR-'.uniqid(), 'id_tagihan' => $t->id, 'id_santri' => $t->id_santri,
            'tanggal' => $tanggal, 'nominal' => $nominal, 'metode' => 'transfer',
            'kode_rekening' => self::KAS, 'status' => $status,
            'dicatat_oleh' => $this->admin->id_pengguna,
            'diverifikasi_oleh' => $diverifikasi ? $this->admin->id_pengguna : null,
            'diverifikasi_pada' => $diverifikasi,
        ]);
        Carbon::setTestNow();
    }

    public function test_waktu_terbit_dan_setiap_pembayaran_tampil_beserta_rekeningnya(): void
    {
        $t = $this->tagihan('2026-09-01 08:15:00');
        $this->bayar($t, '2026-09-03', '100000', '2026-09-03 10:21:00', 'terverifikasi', '2026-09-03 14:05:00');
        $this->bayar($t, '2026-09-10', '50000', '2026-09-10 09:02:00', 'menunggu_verifikasi');
        // Yang ditolak tak pernah menjadi uang — tak boleh ikut ditelusuri.
        $this->bayar($t, '2026-09-11', '77777', '2026-09-11 09:00:00', 'ditolak');

        $this->actingAs($this->admin)->get(route('santri.show', $this->santri->id))
            ->assertOk()
            ->assertSee('Diterbitkan')
            ->assertSee('01/09/2026 08:15')
            ->assertSee('jatuh tempo 25/09/2026')
            // Pembayaran pertama: tanggal bayar, nominal, rekening TUJUAN (namanya,
            // bukan kode COA), jam dicatat & jam diverifikasi.
            ->assertSeeInOrder(['03/09/2026', '100.000', 'BCA Operasional', 'dicatat 03/09/2026 10:21', 'diverifikasi 03/09/2026 14:05'])
            // Pembayaran kedua masih menunggu — tampil, dan ditandai.
            ->assertSeeInOrder(['10/09/2026', '50.000', 'menunggu', 'dicatat 10/09/2026 09:02'])
            ->assertDontSee('77.777');
    }

    public function test_tagihan_tanpa_pembayaran_bertanda_strip(): void
    {
        $this->tagihan('2026-09-01 08:15:00');

        $this->actingAs($this->admin)->get(route('santri.show', $this->santri->id))
            ->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('01/09/2026 08:15')
            ->assertDontSee('dicatat ');
    }
}
