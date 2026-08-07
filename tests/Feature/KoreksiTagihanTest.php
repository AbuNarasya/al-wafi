<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetWali;
use App\Models\Jenjang;
use App\Models\JenisBiaya;
use App\Models\JournalLine;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\KoreksiTagihanService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KOREKSI NOMINAL TAGIHAN — buku besar & buku pembantu bergerak bersama.
 *
 * Sebelum ini keduanya hanya bisa dibetulkan sendiri-sendiri, dan hasilnya
 * neraca benar sementara wali tetap ditagih angka yang keliru. Yang dijaga di
 * sini bukan hanya nominalnya berubah, melainkan bahwa JURNALNYA ikut bergerak
 * sebesar selisihnya — kalau tidak, koreksi ini justru menciptakan selisih baru.
 */
class KoreksiTagihanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZKT';

    private const PIUTANG = '1.ZZKT.1';

    private const PENDAPATAN = '4.ZZKT.1';

    private const TA = '2026/2027';

    private User $keuangan;

    private Santri $santri;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->keuangan = User::create([
            'username' => 'zzkt_keu', 'nama' => 'Kepala Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZKTU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Koreksi Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);
        JenisBiaya::create([
            'kode' => 'SPP-UJI', 'nama' => 'SPP Uji', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZKTU', 'status' => 'aktif',
        ]);

        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => 'Bapak Uji', 'telepon_ayah' => '0811',
            'nama' => 'Bapak Uji', 'telepon' => '0811', 'status' => 'aktif',
        ]);
        $this->santri = Santri::create([
            'no_pendaftaran' => 'UJI-0001', 'nis' => '990001', 'nama' => 'Santri Uji',
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    private function tagihan(string $nominal, bool $akrual = true): TagihanSantri
    {
        return TagihanSantri::create([
            'id_santri' => $this->santri->id, 'kode_jenis' => 'SPP-UJI', 'perilaku' => 'lain',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA,
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar',
            'sudah_akrual' => $akrual, 'jatuh_tempo' => '2026-09-01',
        ]);
    }

    private function bayar(TagihanSantri $t, string $nominal): void
    {
        PembayaranSantri::create([
            'nomor' => 'BYR-'.uniqid(), 'id_tagihan' => $t->id, 'id_santri' => $t->id_santri,
            'tanggal' => '2026-08-01', 'nominal' => $nominal, 'metode' => 'tunai',
            'kode_rekening' => self::PIUTANG, 'status' => 'terverifikasi',
            'dicatat_oleh' => $this->keuangan->id_pengguna,
        ]);
        $sisa = bcsub((string) $t->sisa, $nominal, 2);
        $t->update(['sisa' => $sisa, 'status' => $sisa === '0.00' ? 'lunas' : 'sebagian']);
    }

    private function svc(): KoreksiTagihanService
    {
        return new KoreksiTagihanService;
    }

    private function saldo(string $coa): float
    {
        $r = JournalLine::where('kode_coa', $coa)
            ->selectRaw('COALESCE(SUM(debet),0) - COALESCE(SUM(kredit),0) AS s')->value('s');

        return (float) $r;
    }

    /** Koreksi TURUN: pendapatan & piutang sama-sama berkurang sebesar selisihnya. */
    public function test_koreksi_turun_menyesuaikan_jurnalnya(): void
    {
        $t = $this->tagihan('1000000');
        $this->bayar($t, '200000');

        $this->svc()->koreksi($t->id, '600000', 'Salah baca berkas impor', $this->keuangan->id_pengguna);

        $t->refresh();
        $this->assertSame(600000.0, (float) $t->nominal);
        $this->assertSame(400000.0, (float) $t->sisa, '600rb dikurangi 200rb yang sudah dibayar');
        $this->assertSame('sebagian', $t->status);

        // Piutang turun 400rb, pendapatan turun 400rb.
        $this->assertSame(-400000.0, $this->saldo(self::PIUTANG));
        $this->assertSame(400000.0, $this->saldo(self::PENDAPATAN), 'pendapatan didebet, jadi saldo debet-kreditnya positif');
    }

    /** Koreksi NAIK: kebalikannya. */
    public function test_koreksi_naik_menyesuaikan_jurnalnya(): void
    {
        $t = $this->tagihan('1000000');

        $this->svc()->koreksi($t->id, '1250000', 'Tarif tahun berjalan ternyata naik', $this->keuangan->id_pengguna);

        $this->assertSame(1250000.0, (float) $t->refresh()->nominal);
        // Belum ada pembayaran, jadi sisa = seluruh nominal barunya.
        $this->assertSame(1250000.0, (float) $t->sisa);
        // Yang bergerak di buku besar hanya SELISIHNYA — akrual awalnya sudah
        // terbit sebelum koreksi ini (di test ini tagihannya dibuat langsung,
        // jadi yang terlihat di buku besar memang cuma penyesuaiannya).
        $this->assertSame(250000.0, $this->saldo(self::PIUTANG));
    }

    /**
     * INTI PERMINTAANNYA: boleh turun DI BAWAH yang sudah dibayar.
     *
     * Kelebihannya tak dibiarkan jadi sisa negatif — setiap laporan menganggap
     * sisa ≥ 0. Ia dipindahkan ke Dompet Wali sebagai titipan, karena memang
     * itulah sifatnya: kewajiban yayasan kepada keluarga.
     */
    public function test_koreksi_di_bawah_terbayar_memindahkan_kelebihan_ke_dompet_wali(): void
    {
        $t = $this->tagihan('1000000');
        $this->bayar($t, '800000');

        $hasil = $this->svc()->koreksi($t->id, '500000', 'Dobel dengan tagihan lain', $this->keuangan->id_pengguna);

        $t->refresh();
        $this->assertSame(500000.0, (float) $t->nominal);
        $this->assertSame(0.0, (float) $t->sisa, 'sisa tak boleh negatif');
        $this->assertSame('lunas', $t->status);
        $this->assertSame(300000.0, (float) $hasil['koreksi']->kelebihan_ke_dompet);

        // Titipannya benar-benar ada — di dompet, di buku mutasi, dan di buku besar.
        $dompet = DompetWali::where('id_wali', $this->santri->id_wali)->sole();
        $this->assertSame(300000.0, (float) $dompet->saldo);
        $this->assertSame(1, MutasiDompet::where('pemilik', 'wali')->where('id_dompet', $dompet->id)->count());
        $this->assertSame(-300000.0, $this->saldo(DompetPolicy::COA_TITIPAN['wali']), 'titipan = kewajiban, saldo normalnya kredit');

        // Piutang bergerak dua kali: turun 500rb oleh koreksinya, lalu naik 300rb
        // saat kelebihannya dipindahkan keluar menjadi titipan. Bersihnya −200rb.
        $this->assertSame(-200000.0, $this->saldo(self::PIUTANG));
    }

    /** Jejaknya menyimpan jurnalnya, supaya angkanya bisa ditelusuri balik. */
    public function test_koreksi_menyimpan_jejak_beserta_jurnalnya(): void
    {
        $t = $this->tagihan('1000000');
        $hasil = $this->svc()->koreksi($t->id, '750000', 'Kelebihan input', $this->keuangan->id_pengguna);

        $k = $hasil['koreksi'];
        $this->assertSame(1000000.0, (float) $k->nominal_lama);
        $this->assertSame(750000.0, (float) $k->nominal_baru);
        $this->assertSame('Kelebihan input', $k->alasan);
        $this->assertNotNull($k->journal_entry_id);
        $this->assertSame($this->keuangan->id_pengguna, $k->dikoreksi_oleh);
    }

    /**
     * TAHAP 2 — tagihan berjadwal angsuran.
     *
     * Jadwalnya DIGUGURKAN, bukan dihitung ulang diam-diam: rencana angsuran
     * punya `disepakati_pada` dan `disepakati_oleh`, jadi ia kesepakatan dengan
     * wali. Menulis ulang angkanya sendiri menghasilkan jadwal yang tak pernah
     * disetujui siapa pun.
     */
    public function test_koreksi_menggugurkan_jadwal_angsuran(): void
    {
        $t = $this->tagihan('12000000');
        $rencana = RencanaAngsuranUangPangkal::create([
            'id_tagihan' => $t->id, 'versi' => 1, 'status' => 'aktif',
            'disepakati_pada' => '2026-07-01', 'disepakati_oleh' => $this->keuangan->id_pengguna,
        ]);

        $hasil = $this->svc()->koreksi($t->id, '10000000', 'Potongan gelombang terlewat', $this->keuangan->id_pengguna);

        $this->assertTrue($hasil['jadwal_dibatalkan']);
        $rencana->refresh();
        $this->assertSame('digantikan', $rencana->status);
        $this->assertStringContainsString('disusun ulang', $rencana->alasan);
    }

    /** Tagihan belum akrual tak punya piutang di buku besar — tak ada yang dijurnal. */
    public function test_tagihan_belum_akrual_tidak_menjurnal_selisihnya(): void
    {
        $t = $this->tagihan('1000000', akrual: false);

        $hasil = $this->svc()->koreksi($t->id, '800000', 'Salah tarif', $this->keuangan->id_pengguna);

        $this->assertSame(800000.0, (float) $t->refresh()->nominal);
        $this->assertNull($hasil['koreksi']->journal_entry_id, 'tak ada akrual, jadi tak ada yang perlu disesuaikan');
        $this->assertSame(0.0, $this->saldo(self::PIUTANG));
    }

    public function test_penjagaan(): void
    {
        $t = $this->tagihan('1000000');

        foreach ([
            // Nol TIDAK lagi ditolak — itu penghapusan penuh, lihat
            // KoreksiTagihanNolTest. Yang mustahil tinggal nominal negatif.
            ['-1', 'Alasan ada', 'tidak boleh negatif'],
            ['500000', '   ', 'Alasan koreksi wajib'],
            ['1000000', 'Alasan ada', 'sama dengan yang sekarang'],
        ] as [$nominal, $alasan, $pesan]) {
            try {
                $this->svc()->koreksi($t->id, $nominal, $alasan, $this->keuangan->id_pengguna);
                $this->fail("seharusnya ditolak: {$pesan}");
            } catch (AppException $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }
        }

        $this->assertSame(1000000.0, (float) $t->refresh()->nominal, 'tak ada yang bergeser oleh percobaan yang gagal');
    }

    /**
     * Jalur HTTP-nya, dan yang paling penting: WEWENANGNYA BERDIRI SENDIRI.
     *
     * Hak `santri,ubah` tidak cukup. Aksi ini mengubah piutang yang sudah
     * dibukukan dan menerbitkan jurnal penyesuaian — yang boleh menyunting nama
     * dan alamat santri belum tentu boleh menggeser angka di buku besar.
     */
    public function test_wewenangnya_terpisah_dari_hak_ubah_santri(): void
    {
        $t = $this->tagihan('1000000');

        $petugas = User::create([
            'username' => 'zzkt_ptg', 'nama' => 'Petugas Santri', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        \App\Models\HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'santri',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true,
        ]);

        $this->actingAs($petugas)
            ->post(route('tagihan.koreksi', $t->id), ['nominal_baru' => '500000', 'alasan' => 'Coba'])
            ->assertForbidden();
        $this->assertSame(1000000.0, (float) $t->refresh()->nominal);

        // Kepala keuangan — pemegang hak `koreksi-tagihan`.
        \App\Models\HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'koreksi-tagihan',
            'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => false,
        ]);

        $this->actingAs($petugas)
            ->post(route('tagihan.koreksi', $t->id), ['nominal_baru' => '500000', 'alasan' => 'Salah tarif'])
            ->assertRedirect();
        $this->assertSame(500000.0, (float) $t->refresh()->nominal);
    }

    /**
     * Tombolnya di halaman santri — dan yang lebih penting, KETIADAANNYA bagi
     * yang tak berwenang.
     *
     * Menyembunyikan tombol bukan pengganti penjagaan di rute (itu sudah diuji
     * terpisah), melainkan supaya petugas tak menemukan pintu yang selalu
     * tertutup untuknya dan mengira ada yang rusak.
     */
    public function test_tombol_koreksi_hanya_tampak_bagi_yang_berwenang(): void
    {
        $t = $this->tagihan('1000000');

        $petugas = User::create([
            'username' => 'zzkt_lihat', 'nama' => 'Petugas Santri', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);
        \App\Models\HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'santri',
            'lihat' => true, 'buat' => true, 'ubah' => true, 'hapus' => true, 'menu' => true,
        ]);

        $this->actingAs($petugas)->get(route('santri.show', $this->santri->id))
            ->assertOk()
            ->assertDontSee(route('tagihan.koreksi', $t->id));

        \App\Models\HakAksesModul::create([
            'id_pengguna' => $petugas->id_pengguna, 'kode_modul' => 'koreksi-tagihan',
            'lihat' => true, 'buat' => false, 'ubah' => true, 'hapus' => false, 'menu' => false,
        ]);
        // Hak dibaca sekali per proses; di lapangan tiap permintaan HTTP adalah
        // proses baru, tapi di test keduanya berbagi satu proses.
        \App\Support\Akses::lupakan();

        $this->actingAs($petugas)->get(route('santri.show', $this->santri->id))
            ->assertOk()
            ->assertSee(route('tagihan.koreksi', $t->id))
            ->assertSee('Dompet Wali');
    }

    /** Pembayaran yang belum diputuskan membuat sisa masih bergerak. */
    public function test_ditolak_bila_ada_pembayaran_menunggu_verifikasi(): void
    {
        $t = $this->tagihan('1000000');
        PembayaranSantri::create([
            'nomor' => 'BYR-TUNGGU', 'id_tagihan' => $t->id, 'id_santri' => $t->id_santri,
            'tanggal' => '2026-08-01', 'nominal' => '100000', 'metode' => 'tunai',
            'kode_rekening' => self::PIUTANG, 'status' => 'menunggu_verifikasi',
            'dicatat_oleh' => $this->keuangan->id_pengguna,
        ]);

        try {
            $this->svc()->koreksi($t->id, '500000', 'Coba', $this->keuangan->id_pengguna);
            $this->fail('seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('menunggu verifikasi', $e->getMessage());
        }
    }
}
