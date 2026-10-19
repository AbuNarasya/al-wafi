<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\HakAksesModul;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\NotifikasiWali;
use App\Models\PengajuanTarikTunai;
use App\Models\Santri;
use App\Models\SesiTarikTunai;
use App\Models\TabunganSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\DompetService;
use App\Services\Modules\PenarikanTunaiService;
use App\Services\PortalWali\PinTransaksi;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Akses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PENARIKAN TUNAI KEGIATAN — tahap E1: sesi, pengajuan wali ber-PIN dengan cadangan saldo,
 * persetujuan kesantrian, gugur, dan penahan cadangan di jalur dompet lain.
 */
class PenarikanTunaiTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482915';

    private Wali $wali;

    private Santri $ahmad;

    private Santri $fatimah;

    private User $keuangan;

    private User $kesantrian;

    private PenarikanTunaiService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Level::create(['kode_level' => 'L1', 'nama_level' => 'Staf', 'max_transaksi' => null]);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        CoaGroup::create(['kode_grup' => 'ZZTT', 'nama_grup' => 'Tarik']);
        foreach (DompetPolicy::COA_TITIPAN as $pemilik => $kode) {
            CoaDetail::create(['kode_coa' => $kode, 'nama_coa' => "Titipan {$pemilik}", 'kode_grup' => 'ZZTT', 'jenis_saldo' => 'kredit']);
        }
        foreach (['1.ZZTT.BNK' => 'Bank Operasional', '1.ZZTT.KAS' => 'Kas Tunai Kegiatan'] as $kd => $n) {
            CoaDetail::create(['kode_coa' => $kd, 'nama_coa' => $n, 'kode_grup' => 'ZZTT', 'jenis_saldo' => 'debet']);
            BankAccount::create(['kode_coa' => $kd, 'nama_rekening' => $n, 'jenis_rekening' => 'bank', 'status' => 'aktif']);
        }

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811', 'email_ibu' => 'aminah@contoh.id',
            'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->ahmad = $this->santri('AHMAD', '1001', 3, 300000);
        $this->fatimah = $this->santri('FATIMAH', '1002', 1, 100000);
        (new PinTransaksi($this->wali, 'ibu'))->simpan(self::PIN);

        $this->keuangan = $this->pengguna('keu', ['sesi-tarik-tunai' => ['buat', 'ubah', 'hapus']]);
        $this->keuangan->update(['tim_keuangan' => true]);
        $this->kesantrian = $this->pengguna('ksn', ['penarikan-tunai' => ['ubah']]);
        $this->svc = new PenarikanTunaiService;
    }

    private function santri(string $nama, string $nis, int $tingkat, int $saldo): Santri
    {
        $s = Santri::create(['no_pendaftaran' => "ZZTT-{$nis}", 'nis' => $nis, 'nama' => $nama, 'jenis_kelamin' => 'L', 'kode_jenjang' => 'SMP',
            'tingkat' => $tingkat, 'jalur' => 'reguler', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
        DompetSantri::create(['id_santri' => $s->id, 'saldo' => $saldo]);

        return $s;
    }

    private function pengguna(string $u, array $hak): User
    {
        $user = User::create(['username' => $u, 'nama' => ucfirst($u), 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif'])->fresh();
        foreach ($hak as $modul => $aksi) {
            HakAksesModul::create(['id_pengguna' => $user->id_pengguna, 'kode_modul' => $modul, 'lihat' => true,
                'buat' => in_array('buat', $aksi, true), 'ubah' => in_array('ubah', $aksi, true), 'hapus' => in_array('hapus', $aksi, true), 'menu' => true]);
        }
        Akses::lupakan();

        return $user;
    }

    private function jadwal(array $timpa = []): array
    {
        return $timpa + [
            'nama' => 'Rihlah Kelas 9', 'keterangan' => 'Ke Yogyakarta', 'batas_pengajuan' => now()->addDays(2)->format('Y-m-d H:i'),
            'batas_persetujuan' => now()->addDays(3)->format('Y-m-d H:i'), 'tanggal_ambil' => now()->addDays(4)->toDateString(),
            'tanggal_distribusi' => now()->addDays(5)->toDateString(), 'maks_per_santri' => 200000,
            'kode_coa_bank' => '1.ZZTT.BNK', 'kode_coa_kas' => '1.ZZTT.KAS',
        ];
    }

    private function sesiTerbuka(): SesiTarikTunai
    {
        $sesi = $this->svc->buatSesi($this->jadwal(), [$this->ahmad->id], $this->keuangan->id_pengguna);

        return $this->svc->aturDibuka($sesi->id_sesi, true);
    }

    private function sebagaiWali(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    private function ajukan(SesiTarikTunai $sesi, int $nominal, ?Santri $s = null): PengajuanTarikTunai
    {
        return $this->svc->ajukan($this->wali, 'ibu', $sesi->id_sesi, ($s ?? $this->ahmad)->id, (string) $nominal, self::PIN);
    }

    private function galat(\Closure $f, string $pola): void
    {
        try {
            $f();
            $this->fail("Seharusnya ditolak: {$pola}");
        } catch (AppException $e) {
            $this->assertMatchesRegularExpression($pola, $e->getMessage());
        }
    }

    public function test_tahap_dihitung_dari_tanggal(): void
    {
        $sesi = $this->sesiTerbuka();
        $this->assertSame('pengajuan', $sesi->tahap());
        $this->travel(2)->days();
        $this->travel(1)->minutes();
        $this->assertSame('persetujuan', $sesi->fresh()->tahap());
        $this->travel(1)->days();
        $this->assertSame('siap_ambil', $sesi->fresh()->tahap());
    }

    public function test_pengajuan_mencadangkan_saldo_dan_menahan_jalur_dompet_lain(): void
    {
        $sesi = $this->sesiTerbuka();
        $p = $this->ajukan($sesi, 200000);

        $this->assertSame(['diajukan', '200000.00'], [$p->status, $p->nominal]);
        $this->assertSame('200000.00', PenarikanTunaiService::cadangan($this->ahmad->id));
        $this->assertSame('100000.00', PenarikanTunaiService::tersedia($this->ahmad->id));
        $this->assertSame('300000.00', DompetSantri::where('id_santri', $this->ahmad->id)->value('saldo'));   // tanpa jurnal, saldo utuh

        DompetWali::create(['id_wali' => $this->wali->id, 'saldo' => 0]);
        TabunganSantri::create(['id_santri' => $this->ahmad->id, 'saldo' => 0]);
        $dompet = new DompetService;
        $pola = '/dicadangkan untuk penarikan tunai kegiatan/';
        $this->galat(fn () => $dompet->pindah(['dari' => 'santri', 'ke' => 'wali', 'id_wali' => $this->wali->id, 'id_santri' => $this->ahmad->id,
            'nominal' => '150000', 'tanggal' => now()->toDateString()], $this->keuangan->id_pengguna), $pola);
        $this->galat(fn () => $dompet->pindahAntarSantri(['id_santri_asal' => $this->ahmad->id, 'id_santri_tujuan' => $this->fatimah->id,
            'nominal' => '150000', 'tanggal' => now()->toDateString(), 'keterangan' => 'x'], $this->keuangan->id_pengguna), $pola);
        $this->galat(fn () => $dompet->tarik(['pemilik' => 'santri', 'id_dompet' => DompetSantri::where('id_santri', $this->ahmad->id)->value('id'),
            'nominal' => '150000', 'kode_rekening' => '1.ZZTT.KAS', 'tanggal' => now()->toDateString()], $this->keuangan->id_pengguna), $pola);

        // Yang tidak dicadangkan tetap bisa dipakai.
        $dompet->pindahAntarSantri(['id_santri_asal' => $this->ahmad->id, 'id_santri_tujuan' => $this->fatimah->id,
            'nominal' => '100000', 'tanggal' => now()->toDateString(), 'keterangan' => 'x'], $this->keuangan->id_pengguna);
        $this->assertSame('0.00', PenarikanTunaiService::tersedia($this->ahmad->id));
    }

    public function test_aturan_pengajuan(): void
    {
        $sesi = $this->sesiTerbuka();
        $this->galat(fn () => $this->ajukan($sesi, 75000), '/kelipatan Rp 50.000/');
        $this->galat(fn () => $this->ajukan($sesi, 250000), '/Melebihi batas/');
        $this->galat(fn () => $this->ajukan($sesi, 50000, $this->fatimah), '/bukan peserta/');

        DompetSantri::where('id_santri', $this->ahmad->id)->update(['kunci_tarik' => true]);
        $this->galat(fn () => $this->ajukan($sesi, 50000), '/dikunci dari penarikan/');
        DompetSantri::where('id_santri', $this->ahmad->id)->update(['kunci_tarik' => false, 'saldo' => 40000]);
        $this->galat(fn () => $this->ajukan($sesi, 50000), '/tidak cukup/');
        DompetSantri::where('id_santri', $this->ahmad->id)->update(['saldo' => 300000]);

        $this->galat(fn () => $this->svc->ajukan($this->wali, 'ibu', $sesi->id_sesi, $this->ahmad->id, '50000', '111222'), '/PIN salah/');
        $this->ajukan($sesi, 50000);
        $this->galat(fn () => $this->ajukan($sesi, 50000), '/Sudah ada pengajuan/');

        $this->svc->aturDibuka($sesi->id_sesi, false);
        $this->galat(fn () => $this->ajukan($sesi, 50000), '/tidak dibuka/');
        $this->svc->aturDibuka($sesi->id_sesi, true);
        $this->travel(3)->days();
        $this->galat(fn () => $this->ajukan($sesi, 50000), '/Batas pengajuan/');
        $this->assertSame(1, PengajuanTarikTunai::count());
    }

    public function test_setujui_lebih_kecil_tolak_dan_kabar_wali(): void
    {
        $sesi = $this->svc->buatSesi($this->jadwal(), [$this->ahmad->id, $this->fatimah->id], $this->keuangan->id_pengguna);
        $this->svc->aturDibuka($sesi->id_sesi, true);
        $a = $this->ajukan($sesi, 200000);
        $f = $this->ajukan($sesi, 100000, $this->fatimah);

        $this->galat(fn () => $this->svc->setujui($a->id, '250000', $this->kesantrian->id_pengguna), '/tak boleh melebihi/');
        $this->galat(fn () => $this->svc->setujui($a->id, '120000', $this->kesantrian->id_pengguna), '/kelipatan/');
        $this->svc->setujui($a->id, '150000', $this->kesantrian->id_pengguna);
        $this->svc->tolak($f->id, 'Fatimah tidak ikut', $this->kesantrian->id_pengguna);

        $this->assertSame(['disetujui', '150000.00'], [$a->fresh()->status, $a->fresh()->nominal_disetujui]);
        $this->assertSame('150000.00', PenarikanTunaiService::cadangan($this->ahmad->id));
        $this->assertSame('0.00', PenarikanTunaiService::cadangan($this->fatimah->id));
        $this->assertSame(['Penarikan tunai kegiatan disetujui', 'Penarikan tunai kegiatan ditolak'],
            NotifikasiWali::orderBy('id')->pluck('judul')->all());
        $this->galat(fn () => $this->svc->tolak($a->id, 'x', $this->kesantrian->id_pengguna), '/sudah berstatus/');

        // Wali mengubah sesudah disetujui → kembali menunggu, putusan lama gugur.
        $this->svc->ubahPengajuan($this->wali, 'ibu', $a->id, '100000', self::PIN);
        $this->assertSame(['diajukan', null, '100000.00'], [$a->fresh()->status, $a->fresh()->nominal_disetujui, PenarikanTunaiService::cadangan($this->ahmad->id)]);
        // Fatimah ditolak → boleh mengajukan ulang.
        $this->assertSame('diajukan', $this->ajukan($sesi, 50000, $this->fatimah)->status);
    }

    public function test_yang_tak_diputus_sampai_batas_gugur_dan_cadangannya_lepas(): void
    {
        $sesi = $this->sesiTerbuka();
        $p = $this->ajukan($sesi, 100000);
        $this->travel(3)->days();
        $this->travel(1)->minutes();

        $this->assertSame(['gugur', 'Gugur'], [$p->fresh()->statusEfektif(), $p->fresh()->labelStatus()]);
        $this->assertSame('0.00', PenarikanTunaiService::cadangan($this->ahmad->id));
        $this->galat(fn () => $this->svc->setujui($p->id, null, $this->kesantrian->id_pengguna), '/gugur/');
        $this->galat(fn () => $this->svc->batalPengajuan($this->wali, $p->id), '/Batas pengajuan sudah lewat/');
    }

    public function test_batal_pengajuan_dan_batal_sesi_melepas_cadangan(): void
    {
        $sesi = $this->svc->buatSesi($this->jadwal(), [$this->ahmad->id, $this->fatimah->id], $this->keuangan->id_pengguna);
        $this->svc->aturDibuka($sesi->id_sesi, true);
        $a = $this->ajukan($sesi, 100000);
        $this->ajukan($sesi, 50000, $this->fatimah);

        $this->svc->batalPengajuan($this->wali, $a->id);
        $this->assertSame('0.00', PenarikanTunaiService::cadangan($this->ahmad->id));
        $this->galat(fn () => $this->svc->hapusPeserta($sesi->id_sesi, $this->fatimah->id), '/sudah punya pengajuan/');

        $this->svc->batalSesi($sesi->id_sesi, 'Kegiatan diundur', $this->keuangan->id_pengguna);
        $this->assertSame(['batal', '0.00'], [$sesi->fresh()->status, PenarikanTunaiService::cadangan($this->fatimah->id)]);
        $this->assertSame(1, NotifikasiWali::where('judul', 'Penarikan tunai kegiatan dibatalkan')->count());
        $this->galat(fn () => $this->svc->aturDibuka($sesi->id_sesi, true), '/tak bisa diubah/');
    }

    public function test_jadwal_sesi_harus_berurutan(): void
    {
        $this->galat(fn () => $this->svc->buatSesi($this->jadwal(['batas_persetujuan' => now()->addDay()->format('Y-m-d H:i')]), [$this->ahmad->id], 1), '/sesudah batas pengajuan/');
        $this->galat(fn () => $this->svc->buatSesi($this->jadwal(['tanggal_distribusi' => now()->addDays(3)->toDateString()]), [$this->ahmad->id], 1), '/distribusi/');
        $this->galat(fn () => $this->svc->buatSesi($this->jadwal(['maks_per_santri' => 120000]), [$this->ahmad->id], 1), '/kelipatan/');
        $this->galat(fn () => $this->svc->buatSesi($this->jadwal(['kode_coa_kas' => '1.ZZTT.BNK']), [$this->ahmad->id], 1), '/harus berbeda/');
    }

    public function test_layar_staf_dan_hak_akses(): void
    {
        $this->actingAs($this->kesantrian)->get(route('tarik_tunai.index'))->assertForbidden();

        $this->actingAs($this->keuangan)->post(route('tarik_tunai.store'), $this->jadwal() + ['kode_jenjang' => 'SMP', 'tingkat' => [3]])
            ->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'dengan 1 peserta'));
        $sesi = SesiTarikTunai::sole();
        $this->assertSame([$this->ahmad->id], $sesi->santri()->pluck('santri.id')->all());

        $this->actingAs($this->keuangan)->post(route('tarik_tunai.peserta.tambah', $sesi->id_sesi), ['nis' => '1002, 9999'])
            ->assertSessionHas('status', '1 peserta ditambahkan. 1 NIS tidak ditemukan.');
        $this->actingAs($this->keuangan)->post(route('tarik_tunai.dibuka', $sesi->id_sesi), ['dibuka' => '1']);
        $this->assertTrue($sesi->fresh()->dibuka);
        $this->actingAs($this->keuangan)->get(route('tarik_tunai.show', $sesi->id_sesi))->assertOk()
            ->assertSee('AHMAD')->assertSee('FATIMAH')->assertSee('Terbuka untuk wali');

        $p = $this->ajukan($sesi->fresh(), 150000);
        $this->actingAs($this->keuangan)->get(route('persetujuan_tarik_tunai.index'))->assertForbidden();
        $this->actingAs($this->kesantrian)->get(route('persetujuan_tarik_tunai.index'))->assertOk()->assertSee('AHMAD')->assertSee('Rp 150.000');
        $this->actingAs($this->kesantrian)->post(route('persetujuan_tarik_tunai.setujui', $p->id), ['nominal' => 100000])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Rp 100.000'));
        $this->assertSame('100000.00', $p->fresh()->nominal_disetujui);
    }

    public function test_portal_wali(): void
    {
        $this->sebagaiWali()->get(route('portal.tarik_tunai.index'))->assertOk()->assertSee('Tidak ada kegiatan');
        $sesi = $this->sesiTerbuka();

        $this->sebagaiWali()->get(route('portal.dompet'))->assertOk()->assertSee('Uang saku kegiatan')->assertSee('Rihlah Kelas 9');
        $halaman = $this->sebagaiWali()->get(route('portal.tarik_tunai.index'))->assertOk()->assertSee('AHMAD')->assertDontSee('FATIMAH');
        $this->assertStringContainsString('<option value="200000"', $halaman->getContent());
        $this->assertStringNotContainsString('<option value="250000"', $halaman->getContent());

        $this->sebagaiWali()->post(route('portal.tarik_tunai.ajukan'), ['id_sesi' => $sesi->id_sesi, 'id_santri' => $this->ahmad->id, 'nominal' => 150000, 'pin' => self::PIN])
            ->assertRedirect(route('portal.tarik_tunai.index'))->assertSessionHas('status', fn ($s) => str_contains($s, 'dicadangkan'));
        $p = PengajuanTarikTunai::sole();
        $this->sebagaiWali()->get(route('portal.dompet'))->assertSee('dicadangkan untuk kegiatan');

        $this->sebagaiWali()->post(route('portal.tarik_tunai.ubah', $p->id), ['nominal' => 100000, 'pin' => '111222'])->assertSessionHas('error');
        $this->assertSame('150000.00', $p->fresh()->nominal);
        $this->sebagaiWali()->post(route('portal.tarik_tunai.batal', $p->id))->assertSessionHas('status');
        $this->assertSame('dibatalkan', $p->fresh()->status);
    }
}
