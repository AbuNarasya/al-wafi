<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BatchTagihan;
use App\Models\BatchTagihanBaris;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\BatchTagihanService;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BATCH TAGIHAN — tahap 1: susun, otorisasi, rilis manual.
 *
 * Yang dijaga di sini adalah janji-janji yang membedakan batch dari penerbitan
 * biasa, dan yang kalau ingkar tak akan ketahuan sampai ada yang menagih:
 *  • selama draft & otorisasi, TIDAK ADA tagihan maupun jurnal yang lahir;
 *  • nominalnya DIKUNCI — yang diperiksa petugas itulah yang terbit, walau
 *    angka sumbernya berubah di antara otorisasi dan rilis;
 *  • baris yang tak lagi layak saat rilis DILEWATI berikut alasannya, bukan
 *    membatalkan seluruh batch.
 */
class BatchTagihanTest extends TestCase
{
    use RefreshDatabase;

    private const GRP = 'ZZBT';

    private const PIUTANG = '1.ZZBT.1';

    private const PENDAPATAN = '4.ZZBT.1';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->petugas = User::create([
            'username' => 'zzbt_petugas', 'nama' => 'Petugas Batch', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZBTU', 'nama_unit' => 'Unit Uji']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Batch Uji']);
        CoaDetail::create(['kode_coa' => self::PIUTANG, 'nama_coa' => 'Piutang Santri Lainnya', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        CoaDetail::create(['kode_coa' => self::PENDAPATAN, 'nama_coa' => 'Pendapatan Lain-lain', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'nama_coa' => 'Titipan Wali', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);

        TipeBiaya::firstOrCreate(['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif']);

        JenisBiaya::create([
            'kode' => 'EKS', 'nama' => 'Ekskul Memanah', 'tipe' => 'lain',
            'kode_coa_pendapatan' => self::PENDAPATAN, 'kode_coa_piutang' => self::PIUTANG,
            'kode_unit' => 'ZZBTU', 'status' => 'aktif',
            'pengakuan' => 'akrual', 'cara_tagih' => 'kepesertaan',
        ]);
    }

    private function santri(string $nis, string $nama): Santri
    {
        $wali = Wali::create([
            'kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}", 'telepon_ayah' => '08'.$nis,
            'nama' => "Ayah {$nama}", 'telepon' => '08'.$nis, 'status' => 'aktif',
        ]);

        return Santri::create([
            'no_pendaftaran' => "UJI-{$nis}", 'nis' => $nis, 'nama' => $nama,
            'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP', 'tingkat' => 1,
            'tahun_ajaran' => self::TA, 'tahun_ajaran_berjalan' => self::TA,
            'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $wali->id,
        ]);
    }

    /** Draft manual untuk dua santri, Rp 75.000 masing-masing. */
    private function susunDraft(array $santri, string $nominal = '75000'): BatchTagihan
    {
        return (new BatchTagihanService)->susun([
            'modul' => 'tagihan_lain',
            'judul' => 'Ekskul Memanah September',
            'parameter' => [
                'kode_jenis' => 'EKS', 'sumber' => 'manual',
                'id_santri' => array_map(fn ($s) => $s->id, $santri),
                'nominal' => $nominal, 'periode' => '2026-09',
                'jatuh_tempo' => '2026-09-20', 'keterangan' => 'Ekskul Memanah September',
            ],
        ], $this->petugas->id_pengguna);
    }

    public function test_draft_tidak_menerbitkan_tagihan_maupun_jurnal(): void
    {
        $a = $this->santri('880001', 'Ahmad Fauzi');
        $b = $this->santri('880002', 'Bilal Ramadhan');

        $batch = $this->susunDraft([$a, $b]);

        $this->assertSame('draft', $batch->status);
        $this->assertSame(2, $batch->jumlah_baris);
        $this->assertSame('150000.00', $batch->total);

        // Inti seluruh modul ini: jeda antara "diperiksa" dan "terbit" harus
        // benar-benar kosong. Satu baris pun di sini berarti piutang hantu.
        $this->assertSame(0, TagihanSantri::count());
        $this->assertSame(0, \App\Models\JournalEntry::count());
    }

    public function test_otorisasi_pun_belum_menerbitkan_apa_pun(): void
    {
        $a = $this->santri('880003', 'Hafizh Nur');
        $batch = $this->susunDraft([$a]);

        $batch = (new BatchTagihanService)->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        $this->assertSame('diotorisasi', $batch->status);
        $this->assertSame($this->petugas->id_pengguna, $batch->diotorisasi_oleh);
        $this->assertNotNull($batch->diotorisasi_pada);
        $this->assertSame(0, TagihanSantri::count());
    }

    public function test_rilis_menerbitkan_tagihan_dengan_nominal_yang_dikunci(): void
    {
        $a = $this->santri('880004', 'Ilham Saputra');
        $b = $this->santri('880005', 'Junaidi Akbar');

        $svc = new BatchTagihanService;
        $batch = $this->susunDraft([$a, $b]);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        $hasil = $svc->rilis($batch->id, $this->petugas->id_pengguna);

        $this->assertSame(2, $hasil['terbit']);
        $this->assertSame(0, $hasil['dilewati']);
        $this->assertSame('dirilis', $hasil['status']);

        $this->assertSame(2, TagihanSantri::count());
        $t = TagihanSantri::where('id_santri', $a->id)->firstOrFail();
        $this->assertSame('75000.00', $t->nominal);
        $this->assertSame('2026-09', $t->periode);
        $this->assertSame('2026-09-20', $t->jatuh_tempo->toDateString());
        $this->assertSame('Ekskul Memanah September', $t->keterangan);

        // Barisnya menunjuk tagihan yang benar-benar lahir — tanpa ini, jejak
        // "apa yang terjadi pada batch ini" berhenti di angka ringkasan saja.
        $baris = BatchTagihanBaris::where('id_batch', $batch->id)->where('id_santri', $a->id)->firstOrFail();
        $this->assertSame('terbit', $baris->hasil);
        $this->assertSame($t->id, $baris->id_tagihan);
    }

    public function test_santri_yang_keluar_setelah_otorisasi_dilewati_bukan_membatalkan_batch(): void
    {
        $a = $this->santri('880006', 'Karim Abdullah');
        $b = $this->santri('880007', 'Luthfi Hakim');

        $svc = new BatchTagihanService;
        $batch = $this->susunDraft([$a, $b]);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        // Dunia bergerak selama jeda.
        $b->update(['status' => 'keluar']);

        $hasil = $svc->rilis($batch->id, $this->petugas->id_pengguna);

        $this->assertSame(1, $hasil['terbit']);
        $this->assertSame(1, $hasil['dilewati']);
        $this->assertSame('sebagian', $hasil['status']);
        $this->assertSame(1, TagihanSantri::count());

        $gugur = BatchTagihanBaris::where('id_batch', $batch->id)->where('id_santri', $b->id)->firstOrFail();
        $this->assertSame('dilewati', $gugur->hasil);
        $this->assertNotEmpty($gugur->hasil_alasan);
    }

    public function test_tagihan_yang_telanjur_terbit_dari_jalur_lain_tidak_jadi_dobel(): void
    {
        $a = $this->santri('880008', 'Musa Prasetyo');

        $svc = new BatchTagihanService;
        $batch = $this->susunDraft([$a]);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        // Petugas lain menerbitkannya lewat layar biasa sebelum batch dirilis.
        (new \App\Services\Modules\TagihanLainService)->terbitkan([
            'kode_jenis' => 'EKS', 'id_santri' => [$a->id], 'nominal' => '75000',
            'periode' => '2026-09', 'tanggal' => '2026-09-01',
        ], $this->petugas->id_pengguna);

        $hasil = $svc->rilis($batch->id, $this->petugas->id_pengguna);

        // Satu tagihan saja — bukan dua.
        $this->assertSame(1, TagihanSantri::where('id_santri', $a->id)->count());
        $this->assertContains($hasil['status'], ['dirilis', 'sebagian']);
    }

    public function test_batch_draft_tidak_bisa_langsung_dirilis(): void
    {
        $a = $this->santri('880009', 'Naufal Rizki');
        $batch = $this->susunDraft([$a]);

        $this->expectException(AppException::class);
        (new BatchTagihanService)->rilis($batch->id, $this->petugas->id_pengguna);
    }

    public function test_waktu_rilis_di_masa_lalu_ditolak(): void
    {
        $a = $this->santri('880010', 'Umar Fadhil');
        $batch = $this->susunDraft([$a]);

        $this->expectException(AppException::class);
        (new BatchTagihanService)->otorisasi($batch->id, ['rilis_pada' => now()->subDay()->toDateTimeString()], $this->petugas->id_pengguna);
    }

    public function test_batch_yang_dibatalkan_tak_bisa_dirilis(): void
    {
        $a = $this->santri('880011', 'Zaid Anwar');

        $svc = new BatchTagihanService;
        $batch = $this->susunDraft([$a]);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);
        $svc->batalkan($batch->id, $this->petugas->id_pengguna, 'Salah periode');

        $this->expectException(AppException::class);
        $svc->rilis($batch->id, $this->petugas->id_pengguna);
    }

    public function test_perilis_terjadwal_hanya_mengambil_yang_waktunya_sudah_lewat(): void
    {
        $a = $this->santri('880012', 'Yusuf Maulana');

        $svc = new BatchTagihanService;
        $batch = $this->susunDraft([$a]);
        $svc->otorisasi($batch->id, ['rilis_pada' => now()->addDays(2)->toDateTimeString()], $this->petugas->id_pengguna);

        // Belum waktunya — tak boleh tersentuh.
        $this->assertSame(0, $svc->rilisYangJatuhTempo()['diproses']);
        $this->assertSame(0, TagihanSantri::count());

        // Waktu berjalan.
        $this->travel(3)->days();
        $ringkas = $svc->rilisYangJatuhTempo();

        $this->assertSame(1, $ringkas['diproses']);
        $this->assertSame(1, $ringkas['terbit']);
        $this->assertSame(1, TagihanSantri::count());

        // Dirilis penjadwal, bukan orang.
        $this->assertNull(BatchTagihan::find($batch->id)->dirilis_oleh);
    }

    public function test_batch_yang_sudah_dirilis_tak_dirilis_dua_kali(): void
    {
        $a = $this->santri('880013', 'Salman Haidar');

        $svc = new BatchTagihanService;
        $batch = $this->susunDraft([$a]);
        $svc->otorisasi($batch->id, ['rilis_pada' => now()->addHour()->toDateTimeString()], $this->petugas->id_pengguna);

        $this->travel(2)->hours();
        $svc->rilisYangJatuhTempo();
        $svc->rilisYangJatuhTempo();

        $this->assertSame(1, TagihanSantri::count());
    }
}
