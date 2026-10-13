<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\PerangkatWali;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\JenisBiayaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portal Wali — Tagihan, Dompet, Akun (murni baca, kecuali mencabut perangkat sendiri).
 */
class PortalKeluargaTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => 'ZZPK', 'nama_grup' => 'Portal']);
        CoaDetail::create(['kode_coa' => '4.ZZPK.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => 'ZZPK', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZPKU', 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->staf = User::create(['username' => 'zzpk', 'nama' => 'Staf', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        (new JenisBiayaService)->create(['kode' => 'REG-J001', 'nama' => 'Registrasi SDTQ', 'tipe' => 'registrasi',
            'kode_jenjang' => 'J001', 'kode_coa_pendapatan' => '4.ZZPK.1', 'kode_unit' => 'ZZPKU']);
    }

    private function keluarga(string $nama, string $telepon): Wali
    {
        return Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => $nama, 'telepon_ayah' => $telepon,
            'email_ayah' => strtolower(str_replace(' ', '', $nama)).'@contoh.id', 'nama' => $nama, 'telepon' => $telepon, 'status' => 'aktif']);
    }

    private function anak(Wali $wali, string $nama, string $status = 'aktif'): Santri
    {
        static $n = 0;

        return Santri::create(['no_pendaftaran' => 'PSB-ZK'.(++$n), 'nama' => $nama, 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J001', 'jalur' => '001', 'status' => $status, 'id_wali' => $wali->id]);
    }

    private function tagihan(Santri $s, string $ket, int $nominal, int $sisa, string $status = 'belum_bayar'): TagihanSantri
    {
        static $n = 0;

        return TagihanSantri::create(['id_santri' => $s->id, 'kode_jenis' => 'REG-J001', 'perilaku' => 'lain',
            'periode' => 'P'.(++$n), 'kode_jenjang' => 'J001', 'nominal' => $nominal, 'sisa' => $sisa,
            'status' => $status, 'keterangan' => $ket]);
    }

    private function sebagai(Wali $wali, string $peran = 'ayah'): static
    {
        return $this->actingAs($wali, 'wali')->withSession(['portal.peran' => $peran]);
    }

    public function test_tagihan_hanya_milik_anak_keluarga_sendiri(): void
    {
        $saya = $this->keluarga('Bapak Saya', '081200000001');
        $anakSaya = $this->anak($saya, 'Anak Saya');
        $this->tagihan($anakSaya, 'Tagihan Saya', 1000000, 1000000);

        $lain = $this->keluarga('Bapak Lain', '081200000002');
        $anakLain = $this->anak($lain, 'Anak Lain');
        $this->tagihan($anakLain, 'Tagihan Rahasia', 5000000, 5000000);

        // Nomor anak keluarga lain di alamat tak membuka apa pun — jatuh ke anak sendiri.
        $this->sebagai($saya)->get(route('portal.tagihan', ['anak' => $anakLain->id]))
            ->assertOk()->assertSee('Tagihan Saya')->assertDontSee('Tagihan Rahasia')->assertDontSee('ANAK LAIN');
    }

    public function test_status_tagihan_dan_yang_batal_disembunyikan(): void
    {
        $wali = $this->keluarga('Bapak Hasan', '081200000003');
        $anak = $this->anak($wali, 'Ahmad');
        $sebagian = $this->tagihan($anak, 'Uang Pangkal', 1000000, 400000, 'sebagian');
        $this->tagihan($anak, 'Daftar Ulang', 500000, 0, 'lunas');
        $this->tagihan($anak, 'Tagihan Dibatalkan', 700000, 700000, 'batal');
        PembayaranSantri::create(['nomor' => 'BYR-ZK1', 'id_santri' => $anak->id, 'id_tagihan' => $sebagian->id,
            'tanggal' => '2026-10-01', 'nominal' => 600000, 'kode_rekening' => '1.1', 'sumber' => 'dompet_wali',
            'status' => 'terverifikasi', 'dicatat_oleh' => $this->staf->id_pengguna]);

        $this->sebagai($wali)->get(route('portal.tagihan'))->assertOk()
            ->assertSee('Rp 400.000')->assertSee('Sebagian')
            // Tab Tagihan hanya yang belum lunas — yang lunas ada di Riwayat bayar (13 Okt 2026).
            ->assertDontSee('Daftar Ulang')
            ->assertDontSee('Tagihan Dibatalkan');

        $this->sebagai($wali)->get(route('portal.tagihan', ['tab' => 'riwayat']))->assertOk()
            ->assertSee('Rp 600.000')->assertSee('Dompet Wali')->assertDontSee('Staf');
    }

    /** Calon membayar dengan transfer langsung — yang belum dicek tampil terpisah, tak dihitung terbayar. */
    public function test_pembayaran_calon_menunggu_verifikasi_ditampilkan_terpisah(): void
    {
        $wali = $this->keluarga('Ibu Calon', '081200000004');
        $anak = $this->anak($wali, 'Calon', 'calon');
        $reg = $this->tagihan($anak, 'Registrasi', 500000, 500000);
        PembayaranSantri::create(['nomor' => 'BYR-ZK2', 'id_santri' => $anak->id, 'id_tagihan' => $reg->id,
            'tanggal' => '2026-10-02', 'nominal' => 500000, 'kode_rekening' => '1.1', 'sumber' => 'manual',
            'status' => 'menunggu_verifikasi', 'dicatat_oleh' => $this->staf->id_pengguna]);

        $this->sebagai($wali)->get(route('portal.tagihan'))->assertOk()
            ->assertSee('Belum')->assertSee('menunggu verifikasi');
    }

    public function test_dompet_tertutup_bagi_keluarga_calon(): void
    {
        $wali = $this->keluarga('Ibu Calon', '081200000005');
        $this->anak($wali, 'Calon', 'calon');

        $this->sebagai($wali)->get(route('portal.dompet'))->assertRedirect(route('portal.beranda'));
        $this->sebagai($wali)->get(route('portal.beranda'))->assertDontSee(route('portal.dompet'));
    }

    public function test_dompet_menampilkan_saldo_dan_mutasi_tanpa_yang_ditolak(): void
    {
        $wali = $this->keluarga('Bapak Hasan', '081200000006');
        $anak = $this->anak($wali, 'Ahmad');
        $dompet = DompetWali::create(['id_wali' => $wali->id, 'saldo' => 750000]);
        DompetSantri::create(['id_santri' => $anak->id, 'saldo' => 120000]);
        foreach ([['topup', 'terverifikasi', 500000], ['topup', 'menunggu_verifikasi', 300000], ['topup', 'ditolak', 999000]] as $i => [$jenis, $status, $nominal]) {
            MutasiDompet::create(['nomor' => "MUT-ZK{$i}", 'pemilik' => 'wali', 'id_dompet' => $dompet->id, 'jenis' => $jenis,
                'nominal' => $nominal, 'saldo_setelah' => 0, 'tanggal' => '2026-10-0'.($i + 1), 'status' => $status,
                'dicatat_oleh' => $this->staf->id_pengguna]);
        }

        $this->sebagai($wali)->get(route('portal.dompet'))->assertOk()
            ->assertSee('Rp 750.000')->assertSee('Rp 120.000')
            ->assertSee('+500.000')->assertSee('Menunggu verifikasi')->assertDontSee('999.000');
    }

    public function test_cabut_perangkat_hanya_milik_sendiri(): void
    {
        $saya = $this->keluarga('Bapak Saya', '081200000007');
        $lain = $this->keluarga('Bapak Lain', '081200000008');
        $buat = fn (Wali $w, string $peran, string $t) => PerangkatWali::create(['id_wali' => $w->id, 'peran' => $peran,
            'token_hash' => hash('sha256', $t), 'nama_perangkat' => 'Android · Chrome', 'kedaluwarsa_pada' => now()->addDays(90)]);
        $milikSaya = $buat($saya, 'ayah', 'a');
        $milikIstri = $buat($saya, 'ibu', 'b');
        $milikLain = $buat($lain, 'ayah', 'c');

        $this->sebagai($saya)->post(route('portal.cabut_perangkat', $milikLain->id))->assertSessionHas('error');
        $this->sebagai($saya)->post(route('portal.cabut_perangkat', $milikIstri->id))->assertSessionHas('error');
        $this->sebagai($saya)->post(route('portal.cabut_perangkat', $milikSaya->id))->assertSessionHas('status');

        $this->assertNull($milikLain->refresh()->dicabut_pada);
        $this->assertNull($milikIstri->refresh()->dicabut_pada, 'perangkat ibu bukan urusan ayah');
        $this->assertNotNull($milikSaya->refresh()->dicabut_pada);
    }

    /**
     * Dua anak: halaman Tagihan dibuka dengan RINGKASAN keluarga, bukan rincian anak
     * pertama — yang dulu membuat wali mengira itulah seluruh tagihannya.
     */
    public function test_tagihan_dua_anak_dimulai_dari_ringkasan_keluarga(): void
    {
        $wali = $this->keluarga('Bapak Dua Anak', '081200000011');
        $kakak = $this->anak($wali, 'Kakak');
        $adik = $this->anak($wali, 'Adik');
        $spp = $this->tagihan($kakak, 'x', 4200000, 4200000);
        $spp->update(['perilaku' => 'spp', 'periode' => '2026-11', 'keterangan' => 'SPP SMP 2026-11 · akrual SPP-2610-0002']);
        $this->tagihan($adik, 'Daftar Ulang Adik', 1500000, 1500000);

        $this->sebagai($wali)->get(route('portal.tagihan'))->assertOk()
            ->assertSee('Total sisa tagihan keluarga')->assertSee('Rp 5.700.000')
            ->assertSee('KAKAK')->assertSee('ADIK')->assertDontSee('Daftar Ulang Adik');

        // Rincian satu anak: nama tagihan untuk wali, tanpa catatan pembukuan.
        $this->sebagai($wali)->get(route('portal.tagihan', ['anak' => $kakak->id]))->assertOk()
            ->assertSee('November 2026')->assertDontSee('akrual')->assertSee('Semua anak');
    }

    public function test_riwayat_tagihan_seluruh_anak_termasuk_yang_lunas(): void
    {
        $wali = $this->keluarga('Bapak Riwayat', '081200000012');
        $kakak = $this->anak($wali, 'Kakak Riwayat');
        $adik = $this->anak($wali, 'Adik Riwayat');
        $this->tagihan($kakak, 'Daftar Ulang Lunas', 500000, 0, 'lunas');
        $this->tagihan($adik, 'Laundry Belum', 100000, 100000);
        $this->tagihan($this->anak($this->keluarga('Lain', '081200000013'), 'Orang Lain'), 'Tagihan Asing', 9000000, 9000000);

        $this->sebagai($wali)->get(route('portal.tagihan.riwayat'))->assertOk()
            ->assertSee('Rp 600.000')->assertSee('Daftar Ulang Lunas')->assertSee('Laundry Belum')
            ->assertSee('KAKAK RIWAYAT')->assertDontSee('Tagihan Asing');
    }

    public function test_mutasi_anak_bisa_disaring_per_anak(): void
    {
        $wali = $this->keluarga('Bapak Mutasi', '081200000014');
        $kakak = $this->anak($wali, 'Kakak Mutasi');
        $adik = $this->anak($wali, 'Adik Mutasi');
        foreach ([[$kakak, 'Jajan kakak'], [$adik, 'Jajan adik']] as $i => [$s, $ket]) {
            $d = DompetSantri::create(['id_santri' => $s->id, 'saldo' => 50000]);
            MutasiDompet::create(['nomor' => "MUT-MA{$i}", 'pemilik' => 'santri', 'id_dompet' => $d->id, 'jenis' => 'jajan',
                'nominal' => -10000, 'saldo_setelah' => 50000, 'tanggal' => '2026-10-10', 'status' => 'terverifikasi',
                'keterangan' => $ket, 'dicatat_oleh' => $this->staf->id_pengguna]);
        }

        $this->sebagai($wali)->get(route('portal.dompet.mutasi_anak'))->assertOk()->assertSee('Jajan kakak')->assertSee('Jajan adik');
        $this->sebagai($wali)->get(route('portal.dompet.mutasi_anak', ['anak' => $adik->id]))->assertOk()
            ->assertSee('Jajan adik')->assertDontSee('Jajan kakak');
    }

    public function test_detail_anak_hanya_untuk_anak_keluarga_sendiri(): void
    {
        $saya = $this->keluarga('Bapak Saya', '081200000009');
        $anakSaya = $this->anak($saya, 'Anak Saya');
        $this->tagihan($anakSaya, 'Daftar Ulang Saya', 750000, 750000);
        DompetSantri::create(['id_santri' => $anakSaya->id, 'saldo' => 85000]);

        $lain = $this->keluarga('Bapak Lain', '081200000010');
        $anakLain = $this->anak($lain, 'Anak Rahasia');

        $this->sebagai($saya)->get(route('portal.anak', $anakSaya->id))->assertOk()
            ->assertSee('ANAK SAYA')->assertSee('Daftar Ulang Saya')->assertSee('Rp 750.000')->assertSee('Rp 85.000');
        $this->sebagai($saya)->get(route('portal.anak', $anakLain->id))->assertNotFound();

        // Kartu anak di beranda menaut ke halaman detailnya.
        $this->sebagai($saya)->get(route('portal.beranda'))->assertSee(route('portal.anak', $anakSaya->id));
    }

    public function test_tamu_diarahkan_ke_halaman_masuk(): void
    {
        foreach (['portal.tagihan', 'portal.dompet', 'portal.akun'] as $rute) {
            $this->get(route($rute))->assertRedirect(route('portal.masuk'));
        }
    }
}
