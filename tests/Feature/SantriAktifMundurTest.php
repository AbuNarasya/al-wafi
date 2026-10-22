<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Level;
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
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pengunduran diri SANTRI AKTIF (keputusan user 22 Okt 2026): seluruh tagihan tetap ditagih; kelas, kartu, jadwal & lanjutan dibereskan. */
class SantriAktifMundurTest extends TestCase
{
    use \Tests\Concerns\MengaktifkanSantri;
    use RefreshDatabase;
    use \Tests\Concerns\MembuatTarif;

    private const GRP = 'ZZMD';
    private const KAS = '1.ZZMD.KAS';
    private const PEND_REG = '4.ZZMD.REG';
    private const PEND_UP = '4.ZZMD.UP';
    private const PIUT_UP = '1.ZZMD.PIUT';
    private const UNIT = 'ZZMDU';
    private const TA = '2026/2027';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'MD']);
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

        $this->jenjangUji();
        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000', 'kode_coa_pendapatan' => self::PEND_REG, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'UP', 'nama' => 'Uang Pangkal', 'tipe' => 'uang_pangkal', 'kode_coa_pendapatan' => self::PEND_UP, 'kode_coa_piutang' => self::PIUT_UP, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
    }

    /** Santri aktif (sudah daftar ulang) dengan uang pangkal 20 juta; $bayar dibayar SEBELUM daftar ulang. */
    private function santriAktif(string $bayarSebelum = '0'): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08'.random_int(100000, 999999)]);
        $svc = new SantriService;
        $santri = $svc->create(['id_wali' => $wali->id, 'nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'gelombang' => '1', 'kode_jenjang' => $this->jenjangUji()]);
        $santri->update(['status' => 'terbayar']);
        $svc->verifikasiBerkas($santri->id);
        $svc->seleksi($santri->id, []);
        $svc->pengumuman($santri->id, ['lulus' => true]);
        $svc->tagihkanUangPangkal($santri->id, ['nominal' => '20000000']);
        $svc->medcheck($santri->id, ['lolos' => true]);

        if (Money::gtZero(Money::of($bayarSebelum))) {
            $this->bayarUp($santri, $bayarSebelum);
        }
        $this->aktifkanSantri($santri->id, $this->admin);

        return $santri->refresh();
    }

    private function tagihanUp(Santri $santri): TagihanSantri
    {
        return TagihanSantri::where('id_santri', $santri->id)->where('kode_jenis', 'UP')->firstOrFail();
    }

    private function bayarUp(Santri $santri, string $nominal): void
    {
        $svc = new PembayaranSantriService;
        $p = $svc->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $this->tagihanUp($santri)->id,
            'tanggal' => now()->toDateString(), 'nominal' => $nominal, 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');
        $svc->verifikasi($p->id, $this->admin);
    }

    /** Saldo berjalan satu akun dari seluruh jurnal aktif. */
    private function saldo(string $kodeCoa): string
    {
        $rows = JournalLine::join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.status', 'aktif')->where('journal_lines.kode_coa', $kodeCoa)
            ->get(['journal_lines.debet', 'journal_lines.kredit']);

        return $rows->reduce(fn ($t, $l) => Money::add($t, Money::sub($l->debet, $l->kredit)), '0');
    }

    public function test_semua_tagihan_tetap_ditagih_tanpa_jurnal_pembalik(): void
    {
        $santri = $this->santriAktif();
        $this->bayarUp($santri, '5000000');
        $this->assertSame(15000000.0, (float) $this->saldo(self::PIUT_UP));

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah domisili', $this->admin);

        $this->assertSame('keluar', $santri->refresh()->status);
        $tagihan = $this->tagihanUp($santri);
        $this->assertSame(['sebagian', 15000000.0], [$tagihan->status, (float) $tagihan->sisa], 'sisa uang pangkal tetap ditagih');
        $this->assertSame(15000000.0, (float) $this->saldo(self::PIUT_UP), 'piutang utuh');
        $this->assertNull(JournalEntry::where('keterangan', 'like', 'Pembatalan sisa%')->first());
        $this->assertDatabaseHas('activity_log', ['aksi' => 'santri_aktif_mengundurkan_diri']);

        // Wali tetap bisa melunasinya dari portal.
        [$alur] = \App\Services\PortalWali\PembayaranPortal::alur($tagihan->load('santri'));
        $this->assertNotNull($alur);
    }

    public function test_keluar_melepas_kelas_kartu_jadwal_dan_lanjutan(): void
    {
        $santri = $this->santriAktif();
        $kelas = \App\Models\Kelas::create(['kode_jenjang' => $santri->kode_jenjang, 'tingkat' => 1, 'nama' => '7A', 'peruntukan' => 'L']);
        $santri->update(['id_kelas' => $kelas->id]);
        $kartu = \App\Models\KartuSantri::create(['uid' => 'AABBCCDD', 'id_santri' => $santri->id, 'status' => 'aktif']);
        $jadwal = \App\Models\JadwalPerubahanSantri::create(['id_santri' => $santri->id, 'tahun_ajaran' => self::TA, 'keputusan' => 'naik', 'status' => 'siap']);
        TahunAjaran::create(['kode' => '2027/2028', 'status' => 'nonaktif']);
        $lanjutan = \App\Models\Pendaftaran::create(['id_santri' => $santri->id, 'jenis' => 'lanjutan', 'status' => 'calon', 'nomor' => 'LJ-UJI-1', 'tanggal' => now()->toDateString(),
            'tahun_ajaran' => '2027/2028', 'kode_jenjang' => $santri->kode_jenjang]);

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);

        $this->assertNull($santri->refresh()->id_kelas);
        $this->assertSame(['diblokir', 'Santri keluar'], [$kartu->refresh()->status, $kartu->alasan_blokir]);
        $this->assertSame('dibatalkan', $jadwal->refresh()->status);
        $this->assertSame('mengundurkan_diri', $lanjutan->refresh()->status);
    }

    public function test_pembayaran_menunggu_verifikasi_tidak_menahan_keluar(): void
    {
        $santri = $this->santriAktif();
        (new PembayaranSantriService)->catat([
            'id_santri' => $santri->id, 'id_tagihan' => $this->tagihanUp($santri)->id,
            'tanggal' => now()->toDateString(), 'nominal' => '1000000', 'kode_rekening' => self::KAS,
        ], $this->admin, 'ppsb');

        // Tak ada sisa yang dihapuskan, jadi angka yang masih bergerak tak lagi jadi masalah.
        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);
        $this->assertSame('keluar', $santri->refresh()->status);
    }

    public function test_rencana_angsuran_tetap_berjalan(): void
    {
        $santri = $this->santriAktif();
        (new AngsuranUangPangkalService)->buatRencana($santri->id, [
            'disepakati_pada' => now()->toDateString(),
            'termin' => [
                ['urutan' => 1, 'nominal' => '10000000', 'jatuh_tempo' => now()->addMonth()->toDateString()],
                ['urutan' => 2, 'nominal' => '10000000', 'jatuh_tempo' => now()->addMonths(2)->toDateString()],
            ],
        ], $this->admin);

        (new SantriService)->mengundurkanDiri($santri->id, 'pindah domisili', $this->admin);

        $this->assertTrue(RencanaAngsuranUangPangkal::where('id_tagihan', $this->tagihanUp($santri)->id)->where('status', 'aktif')->exists(),
            'tagihannya tetap ditagih, jadwal cicilannya tetap berlaku');
    }

    public function test_auto_debet_tetap_memotong_tagihan_santri_yang_sudah_keluar(): void
    {
        $santri = $this->santriAktif();
        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);
        \App\Models\Wali::whereKey($santri->id_wali)->update(['auto_debet' => true]);
        CoaDetail::create(['kode_coa' => \App\Services\Ppsb\DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        \App\Models\DompetWali::create(['id_wali' => $santri->id_wali, 'saldo' => '20000000']);

        (new \App\Services\Modules\AutoDebetService)->jalankan($this->admin);

        $this->assertSame('lunas', $this->tagihanUp($santri)->status);
    }

    public function test_tombol_langsung_di_halaman_santri_ditolak_untuk_santri_aktif(): void
    {
        $santri = $this->santriAktif();
        $this->actingAs(User::find($this->admin))->post(route('santri.aksi', [$santri->id, 'undur-diri']), ['alasan' => 'pindah'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'alur Pengunduran Diri'));
        $this->assertSame('aktif', $santri->refresh()->status);
    }

    public function test_calon_belum_aktif_tetap_memakai_alur_lama(): void
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Budi', 'telepon_ayah' => '08123123']);
        $santri = (new SantriService)->create(['id_wali' => $wali->id, 'nama' => 'Calon', 'jenis_kelamin' => 'L', 'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $this->jenjangUji()]);

        (new SantriService)->mengundurkanDiri($santri->id, 'batal daftar', $this->admin);

        $this->assertSame('mengundurkan_diri', $santri->refresh()->status);
        $this->assertNull(JournalEntry::where('keterangan', 'like', 'Pembatalan sisa uang pangkal%')->first());
    }

    public function test_santri_sudah_keluar_tidak_bisa_mundur_lagi(): void
    {
        $santri = $this->santriAktif();
        (new SantriService)->mengundurkanDiri($santri->id, 'pindah', $this->admin);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah berakhir/i');
        (new SantriService)->mengundurkanDiri($santri->id, 'lagi', $this->admin);
    }
}
