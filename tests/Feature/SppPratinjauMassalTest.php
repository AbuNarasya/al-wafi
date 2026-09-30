<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\KebijakanKhusus;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * PRATINJAU SPP MASSAL — bahan diambil sekaligus, hitungan tetap satu.
 *
 * Dulu pratinjau memanggil nominalSppSantri() per santri (±7 kueri masing-
 * masing). Dengan 649 santri hasil impor ISE itu 4.550 kueri, dan di produksi
 * halaman Terbitkan SPP diputus batas waktu sebelum sempat tampil.
 *
 * Dua janji yang dijaga di sini:
 *  1. Jumlah kueri TIDAK tumbuh bersama jumlah santri.
 *  2. Angka & pesan tiap baris SAMA PERSIS dengan hitungan per santri — untuk
 *     setiap cabang aturan (tarif, kebijakan persen, kebijakan nominal khusus,
 *     nominal khusus cara lama, tarif bebas, sel kosong, jenis biaya tak ada).
 */
class SppPratinjauMassalTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZPM';

    private const PEND = '4.ZZPM.SPP';

    private const PIUT = '1.ZZPM.SPP';

    private const UNIT = 'ZZPMU';

    private const TA = '2026/2027';

    private const PERIODE = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();

        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Pratinjau SPP']);
        CoaDetail::create(['kode_coa' => self::PEND, 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => self::PIUT, 'nama_coa' => 'Piutang SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        User::create(['username' => 'zzpm_adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);

        TahunAjaran::create([
            'kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true,
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2027-06-30',
        ]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler', 'tahun_ajaran' => self::TA]);

        // Empat jenjang, empat keadaan sel SPP.
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'urutan' => 1]);   // bertarif
        Jenjang::create(['kode' => 'SD', 'nama' => 'SD', 'urutan' => 2]);     // bertanda bebas
        Jenjang::create(['kode' => 'SMA', 'nama' => 'SMA', 'urutan' => 3]);   // jenis ada, sel kosong
        Jenjang::create(['kode' => 'TK', 'nama' => 'TK', 'urutan' => 4]);     // jenis biaya SPP tak ada

        $this->buatBiaya([
            'kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA,
        ]);

        $spp = ['tipe' => 'spp', 'kode_coa_pendapatan' => self::PEND, 'kode_coa_piutang' => self::PIUT,
            'kode_unit' => self::UNIT, 'berulang' => true];
        $this->buatBiaya($spp + ['kode' => 'SPP-SMP', 'nama' => 'SPP SMP', 'kode_jenjang' => 'SMP', 'nominal' => '400000', 'tahun_ajaran' => self::TA]);
        $this->buatBiaya($spp + ['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'kode_jenjang' => 'SD', 'bebas' => true, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya($spp + ['kode' => 'SPP-SMA', 'nama' => 'SPP SMA', 'kode_jenjang' => 'SMA']);
    }

    private function santriAktif(string $nama, string $jenjang, array $ubah = []): Santri
    {
        $wali = (new WaliService)->create([
            'kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}",
            'telepon_ayah' => '08'.random_int(1000000, 9999999),
        ]);
        $santri = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L',
            'tahun_ajaran' => self::TA, 'jalur' => 'reguler', 'kode_jenjang' => $jenjang, 'gelombang' => 1,
        ]);
        $santri->update(['status' => 'aktif'] + $ubah);

        return $santri->refresh();
    }

    private function kebijakan(Santri $s, string $cara, string $besaran, ?string $ta = self::TA): void
    {
        KebijakanKhusus::create([
            'id_santri' => $s->id, 'jenis' => 'keringanan', 'perilaku' => 'spp', 'cara' => $cara,
            'besaran' => $besaran, 'tahun_ajaran' => $ta, 'alasan' => 'Uji', 'status' => 'disetujui',
        ]);
    }

    /** Satu santri untuk tiap cabang aturan. */
    private function isiSemuaCabang(): void
    {
        $this->santriAktif('A Biasa', 'SMP');
        $this->kebijakan($this->santriAktif('B Persen', 'SMP'), 'persen', '50');
        $this->kebijakan($this->santriAktif('C Nominal Khusus', 'SMP'), 'nominal_khusus', '100000');
        // Kebijakan umum (tanpa T.A) kalah oleh yang menyebut T.A-nya.
        $d = $this->santriAktif('D Dua Kebijakan', 'SMP');
        $this->kebijakan($d, 'nominal', '50000', null);
        $this->kebijakan($d, 'nominal', '75000');
        // Kebijakan tahun LAIN tak berlaku.
        $this->kebijakan($this->santriAktif('E Kebijakan Tahun Lain', 'SMP'), 'nominal', '90000', '2025/2026');
        $this->santriAktif('F Cara Lama', 'SMP', ['nominal_spp' => '250000', 'keterangan_spp' => 'Anak guru']);
        $this->santriAktif('G Bebas', 'SD');
        $this->santriAktif('H Bebas Tapi Khusus', 'SD', ['nominal_spp' => '50000']);
        $this->santriAktif('I Sel Kosong', 'SMA');
        $this->kebijakan($this->santriAktif('J Sel Kosong Berkebijakan', 'SMA'), 'nominal_khusus', '120000');
        $this->santriAktif('K Tanpa Jenis', 'TK');
    }

    public function test_setiap_baris_sama_persis_dengan_hitungan_per_santri(): void
    {
        $this->isiSemuaCabang();
        $svc = new SppService;

        $pratinjau = $svc->pratinjau(self::PERIODE);
        $this->assertCount(11, $pratinjau);

        foreach ($pratinjau as $baris) {
            try {
                $satu = $svc->nominalSppSantri($baris['id'], self::TA);
            } catch (AppException $e) {
                $this->assertSame('tanpa_tarif', $baris['status'], "{$baris['nama']}: per santri melempar, massal tidak");
                $this->assertSame($e->getMessage(), $baris['pesan'], $baris['nama']);

                continue;
            }

            $this->assertSame('siap', $baris['status'], "{$baris['nama']}: ".($baris['pesan'] ?? ''));
            foreach (['nominal', 'asal', 'asal_label', 'kode_jenis'] as $kolom) {
                $this->assertSame($satu[$kolom], $baris[$kolom], "{$baris['nama']} — {$kolom}");
            }
        }

        // Dan angkanya memang yang diharapkan, bukan sekadar sama-sama keliru.
        $nominal = collect($pratinjau)->pluck('nominal', 'nama');
        $this->assertSame('400000.00', $nominal['A Biasa']);
        $this->assertSame('200000.00', $nominal['B Persen']);
        $this->assertSame('100000.00', $nominal['C Nominal Khusus']);
        $this->assertSame('325000.00', $nominal['D Dua Kebijakan']);
        $this->assertSame('400000.00', $nominal['E Kebijakan Tahun Lain']);
        $this->assertSame('250000.00', $nominal['F Cara Lama']);
        $this->assertNull($nominal['G Bebas']);
        $this->assertSame('50000.00', $nominal['H Bebas Tapi Khusus']);
        $this->assertNull($nominal['I Sel Kosong']);
        $this->assertSame('120000.00', $nominal['J Sel Kosong Berkebijakan']);
        $this->assertNull($nominal['K Tanpa Jenis']);
    }

    public function test_jumlah_kueri_tidak_tumbuh_bersama_jumlah_santri(): void
    {
        $this->isiSemuaCabang();
        $sedikit = $this->hitungKueri();

        for ($i = 1; $i <= 15; $i++) {
            $this->santriAktif("Tambahan {$i}", $i % 2 ? 'SMP' : 'SMA');
        }
        $banyak = $this->hitungKueri();

        // Yang boleh bertambah hanyalah kueri yang tak bergantung pada banyaknya
        // santri. Dulu: +15 santri = +±105 kueri.
        $this->assertSame($sedikit, $banyak, "11 santri = {$sedikit} kueri, 26 santri = {$banyak} kueri");
        $this->assertLessThan(20, $banyak);
    }

    private function hitungKueri(): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        (new SppService)->pratinjau(self::PERIODE);

        // Salinan: pendengar ini tetap hidup sesudahnya dan akan terus menambah $n.
        return $n + 0;
    }
}
