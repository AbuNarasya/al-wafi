<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\BatchTagihanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * BATCH TAGIHAN — jalur SPP.
 *
 * SPP yang paling membuktikan gunanya angka dikunci: tarifnya hidup di master
 * dan boleh berubah kapan saja. Kalau rilis menghitung ulang, yang terbit bukan
 * yang diperiksa petugas — dan tak ada yang akan tahu sampai ada wali yang
 * memprotes tagihannya.
 */
class BatchTagihanSppTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZBS';

    private const PEND = '4.ZZBS.SPP';

    private const PIUT = '1.ZZBS.SPP';

    private const UNIT = 'ZZBSU';

    private const TA = '2026/2027';

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Batch SPP']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUT, 'nama_coa' => 'Piutang SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);

        // Tanggal mulai & selesai WAJIB: itulah yang memetakan periode "2026-09"
        // ke tahun ajarannya, bukan kemiripan tulisan kodenya.
        TahunAjaran::create([
            'kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true,
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2027-06-30',
        ]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);

        $this->petugas = User::create([
            'username' => 'zzbs_adm', 'nama' => 'Admin Batch', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        // Pendaftaran santri menerbitkan tagihan registrasi, jadi jenisnya harus
        // ada lebih dulu — tanpa ini fixture santrinya sendiri yang gagal.
        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);

        $this->buatBiaya([
            'kode' => 'SPP-SMP', 'nama' => 'SPP SMP', 'tipe' => 'spp', 'nominal' => '400000',
            'kode_jenjang' => 'SMP', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA, 'berulang' => true,
        ]);
    }

    private function santriAktif(string $nama): Santri
    {
        $wali = (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}",
            'telepon_ayah' => '08'.random_int(1000000, 9999999),
        ]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => 'SMP', 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif']);

        return $santri->refresh();
    }

    private function susun(string $periode = '2026-09'): \App\Models\BatchTagihan
    {
        return (new BatchTagihanService)->susun([
            'modul' => 'spp',
            'parameter' => ['periode' => $periode, 'jatuh_tempo' => '2026-09-25'],
        ], $this->petugas->id_pengguna);
    }

    public function test_nominal_tetap_yang_dikunci_walau_tarifnya_berubah_sebelum_rilis(): void
    {
        $this->santriAktif('Ahmad Fauzi');

        $svc = new BatchTagihanService;
        $batch = $this->susun();
        $this->assertSame('400000.00', $batch->total);

        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        // Tarif naik SETELAH petugas memeriksa & mengotorisasi.
        $this->pasangTarif(self::TA, 'SMP', null, 'spp', '650000');

        $svc->rilis($batch->id, $this->petugas->id_pengguna);

        $t = TagihanSantri::where('perilaku', 'spp')->firstOrFail();
        $this->assertSame('400000.00', $t->nominal, 'Yang terbit harus angka yang diperiksa petugas, bukan tarif terbaru.');
        $this->assertSame('2026-09', $t->periode);
        $this->assertSame('2026-09-25', $t->jatuh_tempo->toDateString());
        $this->assertTrue($t->sudah_akrual);
    }

    public function test_jurnal_bertanggal_waktu_rilis_terjadwal_bukan_saat_cron_benar_benar_jalan(): void
    {
        $this->santriAktif('Bilal Ramadhan');

        $svc = new BatchTagihanService;
        $batch = $this->susun();
        $jadwal = now()->addDays(2)->startOfHour();
        $svc->otorisasi($batch->id, ['rilis_pada' => $jadwal->toDateTimeString()], $this->petugas->id_pengguna);

        // Cron telat sehari — periode buku besarnya tak boleh ikut bergeser.
        $this->travelTo($jadwal->copy()->addDay());
        $svc->rilisYangJatuhTempo();

        $jurnal = JournalEntry::where('sumber_modul', 'TagihanSpp')->firstOrFail();
        $this->assertSame($jadwal->toDateString(), $jurnal->tanggal->toDateString());
    }

    public function test_santri_yang_sudah_ditagih_di_luar_batch_tidak_tertagih_dua_kali(): void
    {
        $a = $this->santriAktif('Hafizh Nur');
        $b = $this->santriAktif('Ilham Saputra');

        $svc = new BatchTagihanService;
        $batch = $this->susun();
        $this->assertSame(2, $batch->jumlah_baris);
        $svc->otorisasi($batch->id, [], $this->petugas->id_pengguna);

        // Petugas lain menerbitkan SPP lewat layar biasa di tengah jeda.
        (new \App\Services\Modules\SppService)->generate(
            ['periode' => '2026-09', 'tanggal' => '2026-09-01'], $this->petugas->id_pengguna,
        );
        $this->assertSame(2, TagihanSantri::where('perilaku', 'spp')->count());

        $hasil = $svc->rilis($batch->id, $this->petugas->id_pengguna);

        $this->assertSame(0, $hasil['terbit']);
        $this->assertSame(2, $hasil['dilewati']);
        // Bukan `gagal`: tak ada yang salah, pekerjaannya memang sudah selesai.
        $this->assertSame('sebagian', $hasil['status']);
        $this->assertSame(2, TagihanSantri::where('perilaku', 'spp')->count());

        unset($a, $b);
    }

    public function test_draft_menjepret_alasan_santri_yang_tak_bisa_ditagih(): void
    {
        $this->santriAktif('Junaidi Akbar');
        // Santri jenjang lain tanpa sel tarif — pratinjau menandainya terhalang.
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 2]);
        $lain = $this->santriAktif('Karim Abdullah');
        $lain->update(['kode_jenjang' => 'SMA']);

        $batch = $this->susun();

        $this->assertSame(1, $batch->jumlah_baris, 'Hanya yang bertarif yang dihitung akan terbit.');
        $terhalang = $batch->baris()->where('keputusan', 'terhalang')->first();
        $this->assertNotNull($terhalang);
        $this->assertNotEmpty($terhalang->alasan, 'Alasannya ikut dijepret supaya petugas tahu apa yang harus dibetulkan.');
    }
}
