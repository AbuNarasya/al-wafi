<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\KebijakanKhusus;
use App\Models\LampiranDokumen;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Modules\KebijakanKhususService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SppService;
use App\Services\Modules\WaliService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\SumberLampiran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/**
 * KEBIJAKAN KHUSUS SANTRI — keringanan, potongan, beasiswa.
 *
 * Menggantikan `santri.nominal_spp`, sebuah angka yang dulu ditimpa begitu saja
 * tanpa alasan, tanpa pemberi izin, tanpa masa berlaku, dan tanpa selembar
 * surat pun.
 *
 * Yang paling penting diuji: syarat DUA SURAT benar-benar menahan persetujuan,
 * dan kebijakan yang disetujui benar-benar mengubah angka penagihan berikutnya.
 */
class KebijakanKhususTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private const GRP = 'ZZKK';

    private const PEND = '4.ZZKK.PEND';

    private const PIUT = '1.ZZKK.PIUT';

    private const KAS = '1.ZZKK.KAS';

    private const UNIT = 'ZZKKU';

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
        ] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }

        BankAccount::create(['kode_coa' => self::KAS, 'nama_rekening' => 'Kas Uji', 'jenis_rekening' => 'tunai', 'status' => 'aktif']);
        BusinessUnit::create(['kode_unit' => self::UNIT, 'nama_unit' => 'Unit Uji']);
        Level::create(['kode_level' => 'LKK', 'nama_level' => 'Admin', 'max_transaksi' => null]);
        TahunAjaran::create(['kode' => self::TA, 'status' => 'aktif', 'default_pendaftaran' => true]);
        JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        Jenjang::create(['kode' => 'SD', 'nama' => 'Sekolah Dasar', 'urutan' => 1, 'jumlah_tingkat' => 6]);

        $this->admin = User::create([
            'username' => 'admkk', 'nama' => 'Admin', 'password_hash' => 'x',
            'kode_level' => 'LKK', 'is_admin' => true, 'tim_keuangan' => true,
        ])->id_pengguna;

        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '500000',
            'kode_coa_pendapatan' => self::PEND, 'kode_unit' => self::UNIT, 'tahun_ajaran' => self::TA]);
        $this->buatBiaya(['kode' => 'SPP-SD', 'nama' => 'SPP SD', 'tipe' => 'spp', 'nominal' => '500000',
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

        return $s->refresh();
    }

    private function ajukan(Santri $s, array $ubah = []): KebijakanKhusus
    {
        return (new KebijakanKhususService)->ajukan(array_merge([
            'id_santri' => $s->id, 'jenis' => 'keringanan', 'perilaku' => 'spp',
            'cara' => 'nominal_khusus', 'besaran' => '200000',
            'alasan' => 'Ayahnya sakit, penghasilan keluarga menurun.',
        ], $ubah), $this->admin);
    }

    private function lampirkan(KebijakanKhusus $k, string $jenis): void
    {
        LampiranDokumen::create([
            'jenis_dokumen' => $jenis, 'id_dokumen' => (string) $k->id,
            // `path` unik di tabelnya — tiap surat harus punya berkasnya sendiri.
            'nama_asli' => 'surat.pdf', 'path' => "uji/{$jenis}-{$k->id}.pdf", 'disk' => 'local',
            'mime' => 'application/pdf', 'ukuran' => 1024,
            'hash_sha256' => hash('sha256', $jenis.$k->id), 'diunggah_oleh' => $this->admin,
        ]);
    }

    // ---- Syarat dua surat ----

    public function test_tanpa_surat_sama_sekali_tak_bisa_disetujui(): void
    {
        $k = $this->ajukan($this->santri());

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/surat permohonan wali dan surat persetujuan yayasan/');
        (new KebijakanKhususService)->setujui($k->id, $this->admin);
    }

    public function test_satu_surat_saja_belum_cukup(): void
    {
        $k = $this->ajukan($this->santri());
        $this->lampirkan($k, SumberLampiran::KEBIJAKAN_PERMOHONAN);

        // Inilah sebabnya dua JENIS dokumen dipakai, bukan satu: kalau digabung,
        // dua permohonan tanpa persetujuan pun akan terbaca lengkap.
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/surat persetujuan yayasan/');
        (new KebijakanKhususService)->setujui($k->id, $this->admin);
    }

    public function test_dua_surat_lengkap_baru_bisa_disetujui(): void
    {
        $k = $this->ajukan($this->santri());
        $this->lampirkan($k, SumberLampiran::KEBIJAKAN_PERMOHONAN);
        $this->lampirkan($k, SumberLampiran::KEBIJAKAN_PERSETUJUAN);

        $hasil = (new KebijakanKhususService)->setujui($k->id, $this->admin, 'Disetujui yayasan.');

        $this->assertSame('disetujui', $hasil->status);
        $this->assertSame([], (new KebijakanKhususService)->suratYangKurang($k->id));
    }

    public function test_alasan_wajib_diisi(): void
    {
        $s = $this->santri();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/Alasan wajib/');
        $this->ajukan($s, ['alasan' => '   ']);
    }

    // ---- Penerapan pada penagihan ----

    private function setujuiPenuh(Santri $s, array $ubah = []): KebijakanKhusus
    {
        $k = $this->ajukan($s, $ubah);
        $this->lampirkan($k, SumberLampiran::KEBIJAKAN_PERMOHONAN);
        $this->lampirkan($k, SumberLampiran::KEBIJAKAN_PERSETUJUAN);

        return (new KebijakanKhususService)->setujui($k->id, $this->admin);
    }

    public function test_kebijakan_yang_belum_disetujui_tak_mengubah_apa_pun(): void
    {
        $s = $this->santri();
        $this->ajukan($s); // dibiarkan berstatus diajukan

        $this->assertSame('500000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_nominal_khusus_mengganti_tarif_grid(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s);

        $hasil = (new SppService)->nominalSppSantri($s->id);

        $this->assertSame('200000.00', $hasil['nominal']);
        $this->assertSame('khusus', $hasil['asal']);
        $this->assertStringContainsString('Keringanan', $hasil['asal_label']);
        $this->assertStringContainsString('Ayahnya sakit', $hasil['keterangan']);
    }

    public function test_potongan_persen_mengurangi_tarif_grid(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s, ['cara' => 'persen', 'besaran' => '30']);

        // 500.000 − 30% = 350.000
        $this->assertSame('350000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_potongan_nominal_mengurangi_tarif_grid(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s, ['cara' => 'nominal', 'besaran' => '150000']);

        $this->assertSame('350000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_potongan_tak_pernah_membuat_tagihan_negatif(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s, ['cara' => 'nominal', 'besaran' => '900000']);

        // Nol adalah batas bawahnya — dan nol tetap angka yang sah.
        $this->assertSame('0.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_kebijakan_yang_diakhiri_mengembalikan_tarif_normal(): void
    {
        $s = $this->santri();
        $k = $this->setujuiPenuh($s);

        (new KebijakanKhususService)->akhiri($k->id, $this->admin, 'Keadaan walinya sudah membaik.');

        $this->assertSame('500000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_masa_berlaku_dihormati(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s, ['berlaku_mulai' => '2027-01-01']);

        // Hari ini masih di luar masa berlakunya.
        $this->assertNull((new KebijakanKhususService)->berlaku($s->id, 'spp', self::TA, '2026-08-01'));
        $this->assertNotNull((new KebijakanKhususService)->berlaku($s->id, 'spp', self::TA, '2027-02-01'));
    }

    public function test_kebijakan_perilaku_lain_tak_mengenai_spp(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s, ['perilaku' => 'daftar_ulang', 'besaran' => '1000']);

        $this->assertSame('500000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_tak_boleh_dua_kebijakan_berlaku_untuk_sel_yang_sama(): void
    {
        $s = $this->santri();
        $this->setujuiPenuh($s);

        $kedua = $this->ajukan($s, ['besaran' => '100000']);
        $this->lampirkan($kedua, SumberLampiran::KEBIJAKAN_PERMOHONAN);
        $this->lampirkan($kedua, SumberLampiran::KEBIJAKAN_PERSETUJUAN);

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/sudah punya kebijakan yang BERLAKU/');
        (new KebijakanKhususService)->setujui($kedua->id, $this->admin);
    }

    // ---- Warisan nominal_spp ----

    public function test_kebijakan_khusus_menang_atas_nominal_spp_lama(): void
    {
        $s = $this->santri();
        $s->update(['nominal_spp' => '400000', 'keterangan_spp' => 'cara lama']);
        $this->setujuiPenuh($s);

        $this->assertSame('200000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    public function test_nominal_spp_lama_masih_dipakai_bila_tak_ada_kebijakan(): void
    {
        // Cadangan masa peralihan: keringanan yang sudah dijanjikan ke wali tak
        // boleh hilang diam-diam hanya karena modulnya diganti.
        $s = $this->santri();
        $s->update(['nominal_spp' => '400000', 'keterangan_spp' => 'cara lama']);

        $this->assertSame('400000.00', (new SppService)->nominalSppSantri($s->id)['nominal']);
    }

    // ---- Layar ----

    public function test_halaman_terbuka_dan_menyebut_surat_yang_kurang(): void
    {
        $s = $this->santri();
        $this->ajukan($s);

        $this->actingAs(User::find($this->admin))
            ->get(route('kebijakan_khusus.index'))
            ->assertOk()
            ->assertSee('Kebijakan Khusus Santri')
            ->assertSee('Ahmad')
            ->assertSee('surat permohonan wali');
    }
}
