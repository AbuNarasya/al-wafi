<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CatatanPenagihan;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\Level;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Services\Modules\PenagihanTunggakanService;
use App\Services\Modules\SantriService;
use App\Services\Modules\WaliService;
use App\Support\Akses;
use App\Support\TugasSaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatTarif;
use Tests\TestCase;

/** Penagihan Tunggakan: daftar per santri, catatan per tagihan, janji bayar lewat → Tugas Saya (keputusan user 3 Nov 2026). */
class PenagihanTunggakanTest extends TestCase
{
    use MembuatTarif;
    use RefreshDatabase;

    private User $admin;

    private User $petugas;

    private Santri $ahmad;

    private Santri $budi;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create(['username' => 'adm', 'nama' => 'Admin', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif'])->fresh();
        $this->petugas = User::create(['username' => 'tu', 'nama' => 'Admin Sekolah', 'password_hash' => 'x', 'kode_level' => 'L1', 'status' => 'aktif'])->fresh();

        CoaGroup::create(['kode_grup' => 'ZZPN', 'nama_grup' => 'PN']);
        CoaDetail::create(['kode_coa' => '4.ZZPN.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => 'ZZPN', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZPNU', 'nama_unit' => 'Unit']);
        \App\Models\TahunAjaran::create(['kode' => '2026/2027', 'status' => 'aktif', 'default_pendaftaran' => true]);
        \App\Models\JalurPendaftaran::create(['kode' => 'reguler', 'nama' => 'Reguler']);
        $this->jenjangUji();   // dulu: tarif bawaan dicerminkan ke jenjang yang SUDAH ada
        $this->buatBiaya(['kode' => 'REG', 'nama' => 'Registrasi', 'tipe' => 'registrasi', 'nominal' => '150000',
            'kode_coa_pendapatan' => '4.ZZPN.1', 'kode_unit' => 'ZZPNU', 'tahun_ajaran' => '2026/2027']);
        $this->buatBiaya(['kode' => 'SPP', 'nama' => 'SPP', 'tipe' => 'spp', 'nominal' => '500000',
            'kode_coa_pendapatan' => '4.ZZPN.1', 'kode_unit' => 'ZZPNU', 'tahun_ajaran' => '2026/2027']);

        $this->ahmad = $this->santri('Ahmad', '081200000001');
        $this->budi = $this->santri('Budi', '081200000002');
    }

    private function santri(string $nama, string $hp): Santri
    {
        $wali = (new WaliService)->create(['kontak_utama' => 'ayah', 'nama_ayah' => "Ayah {$nama}", 'telepon_ayah' => $hp]);
        $s = (new SantriService)->create([
            'id_wali' => $wali->id, 'nama' => $nama, 'jenis_kelamin' => 'L', 'tanggal_lahir' => '2012-05-01',
            'tahun_ajaran' => '2026/2027', 'jalur' => 'reguler', 'kode_jenjang' => $this->jenjangUji(),
        ]);
        $s->update(['status' => 'aktif']);

        return $s->fresh();
    }

    private function tagihan(Santri $s, string $periode, string $jatuhTempo, string $sisa = '500000'): TagihanSantri
    {
        return TagihanSantri::create([
            'id_santri' => $s->id, 'kode_jenis' => 'SPP', 'periode' => $periode,
            'nominal' => '500000', 'sisa' => $sisa, 'status' => $sisa === '0' ? 'lunas' : 'belum_bayar', 'jatuh_tempo' => $jatuhTempo,
        ]);
    }

    private function beriHak(User $u, array $aksi): void
    {
        HakAksesModul::create(['id_pengguna' => $u->id_pengguna, 'kode_modul' => 'penagihan-tunggakan', 'menu' => true] + $aksi);
        Akses::lupakan();
    }

    public function test_daftar_hanya_santri_yang_lewat_jatuh_tempo_semua_jenjang(): void
    {
        $this->tagihan($this->ahmad, '2026-09', now()->subDays(40)->toDateString());
        $this->tagihan($this->ahmad, '2026-10', now()->subDays(10)->toDateString());
        $this->tagihan($this->budi, '2026-11', now()->addDays(5)->toDateString());     // belum jatuh tempo
        $this->tagihan($this->budi, '2026-08', now()->subDays(70)->toDateString(), '0'); // lunas

        $daftar = (new PenagihanTunggakanService)->daftar();
        $this->assertSame([$this->ahmad->id], array_column($daftar, 'id_santri'));
        $this->assertSame(2, $daftar[0]['jumlah']);
        $this->assertSame(40, $daftar[0]['hari_terlama']);
        $this->assertSame('1000000.00', $daftar[0]['sisa']);

        $this->assertSame([], (new PenagihanTunggakanService)->daftar(['umur' => '60']));
        $this->actingAs($this->admin)->get(route('penagihan_tunggakan.index'))->assertOk()
            ->assertSee('AHMAD')->assertDontSee('BUDI')->assertSee('belum pernah ditagih');
    }

    public function test_catatan_per_tagihan_yang_dicentang(): void
    {
        $sep = $this->tagihan($this->ahmad, '2026-09', now()->subDays(40)->toDateString());
        $okt = $this->tagihan($this->ahmad, '2026-10', now()->subDays(10)->toDateString());
        $milikBudi = $this->tagihan($this->budi, '2026-10', now()->subDays(10)->toDateString());

        $this->actingAs($this->admin)->get(route('penagihan_tunggakan.show', $this->ahmad->id))->assertOk()
            ->assertSee('SPP 2026-09')->assertSee('https://wa.me/6281200000001', false);

        $isi = ['ditagih_pada' => now()->toDateString(), 'cara' => 'whatsapp', 'tanggapan' => 'Ayah janji bayar setelah gajian',
            'janji_tanggal' => now()->addDays(7)->toDateString(), 'janji_nominal' => '500000', 'tindak_lanjut' => 'Telepon ulang'];
        // Tagihan santri lain tak boleh ikut dicatat; centang wajib; tanggapan wajib.
        $this->post(route('penagihan_tunggakan.catat', $this->ahmad->id), $isi + ['tagihan' => [$sep->id, $milikBudi->id]])->assertSessionHas('error');
        $this->post(route('penagihan_tunggakan.catat', $this->ahmad->id), $isi)->assertSessionHasErrors('tagihan');
        $this->post(route('penagihan_tunggakan.catat', $this->ahmad->id), ['tanggapan' => ''] + $isi + ['tagihan' => [$sep->id]])->assertSessionHasErrors('tanggapan');
        $this->post(route('penagihan_tunggakan.catat', $this->ahmad->id), ['janji_tanggal' => now()->subDays(3)->toDateString()] + $isi + ['tagihan' => [$sep->id]])
            ->assertSessionHas('error');
        $this->assertSame(0, CatatanPenagihan::count());

        $this->post(route('penagihan_tunggakan.catat', $this->ahmad->id), $isi + ['tagihan' => [$sep->id, $okt->id]])
            ->assertRedirect(route('penagihan_tunggakan.show', $this->ahmad->id))->assertSessionHas('status');
        $this->assertEqualsCanonicalizing([$sep->id, $okt->id], CatatanPenagihan::pluck('id_tagihan')->all());
        $c = CatatanPenagihan::first();
        $this->assertSame($this->admin->id_pengguna, $c->dicatat_oleh);
        $this->assertSame('Telepon ulang', $c->tindak_lanjut);

        $this->get(route('penagihan_tunggakan.show', $this->ahmad->id))->assertSee('Ayah janji bayar setelah gajian')->assertSee('Admin');
        $baris = (new PenagihanTunggakanService)->daftar(['status' => 'janji_berjalan']);
        $this->assertSame(2, $baris[0]['kali_ditagih']);
        $this->assertSame([$this->budi->id], array_column((new PenagihanTunggakanService)->daftar(['status' => 'belum_ditagih']), 'id_santri'));
    }

    public function test_janji_bayar_lewat_masuk_tugas_saya_dan_hilang_saat_lunas_atau_ditagih_lagi(): void
    {
        $sep = $this->tagihan($this->ahmad, '2026-09', now()->subDays(40)->toDateString());
        $this->beriHak($this->petugas, ['lihat' => true, 'buat' => true]);
        $catat = fn (string $janji) => CatatanPenagihan::create(['id_tagihan' => $sep->id, 'id_santri' => $this->ahmad->id,
            'ditagih_pada' => now()->subDays(10)->toDateString(), 'cara' => 'telepon', 'tanggapan' => 'x', 'janji_tanggal' => $janji]);
        $tugas = function () {
            $this->actingAs($this->petugas);
            TugasSaya::lupakan();

            return TugasSaya::untukUrl('/kesantrian/penagihan-tunggakan');
        };

        $catat(now()->subDay()->toDateString());
        $this->assertSame(1, PenagihanTunggakanService::jumlahJanjiLewat());
        $this->assertSame(1, $tugas());
        $this->assertSame([$this->ahmad->id], array_column((new PenagihanTunggakanService)->daftar(['status' => 'janji_lewat']), 'id_santri'));

        // Ditagih lagi dengan janji baru: catatan TERAKHIR yang menentukan.
        $catat(now()->addDays(3)->toDateString());
        $this->assertSame(0, PenagihanTunggakanService::jumlahJanjiLewat());

        $catat(now()->subDay()->toDateString());
        $sep->update(['sisa' => 0, 'status' => 'lunas']);
        $this->assertSame(0, PenagihanTunggakanService::jumlahJanjiLewat());
        $this->assertSame([], (new PenagihanTunggakanService)->daftar());
    }

    public function test_hak_akses_lihat_saja_tak_bisa_mencatat(): void
    {
        $sep = $this->tagihan($this->ahmad, '2026-09', now()->subDays(40)->toDateString());
        $this->actingAs($this->petugas)->get(route('penagihan_tunggakan.index'))->assertForbidden();

        $this->beriHak($this->petugas, ['lihat' => true]);
        $this->actingAs($this->petugas)->get(route('penagihan_tunggakan.show', $this->ahmad->id))->assertOk()
            ->assertDontSee('name="tanggapan"', false);
        $this->post(route('penagihan_tunggakan.catat', $this->ahmad->id), ['tagihan' => [$sep->id], 'ditagih_pada' => now()->toDateString(),
            'cara' => 'telepon', 'tanggapan' => 'x'])->assertForbidden();
        $this->assertSame(0, CatatanPenagihan::count());
        $this->assertContains('Penagihan Tunggakan', collect(\App\Support\Navigation::tree())->flatMap(fn ($g) => collect($g['subs'])->flatMap(fn ($s) => array_column($s['items'], 'label')))->all());
    }
}
