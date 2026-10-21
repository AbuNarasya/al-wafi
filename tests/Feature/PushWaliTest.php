<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetSantri;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\NotifikasiWali;
use App\Models\PushLanggananWali;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Modules\JenisBiayaService;
use App\Services\PortalWali\KabarKeluarga;
use App\Services\PortalWali\PushWaliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUSH NOTIFICATION WALI per kategori pilihan (keputusan user 21 Okt 2026) + sumber kabar keluarga baru:
 * mutasi dompet anak, pembayaran diverifikasi, tagihan baru terbit.
 */
class PushWaliTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    private Wali $wali;

    private Santri $anak;

    protected function setUp(): void
    {
        parent::setUp();
        CoaGroup::create(['kode_grup' => 'ZZPW', 'nama_grup' => 'Portal']);
        CoaDetail::create(['kode_coa' => '4.ZZPW.1', 'nama_coa' => 'Pendapatan', 'kode_grup' => 'ZZPW', 'jenis_saldo' => 'kredit']);
        BusinessUnit::create(['kode_unit' => 'ZZPWU', 'nama_unit' => 'Unit']);
        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->staf = User::create(['username' => 'zzpw', 'nama' => 'Staf', 'password_hash' => 'x', 'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'J001', 'nama' => 'SDTQ', 'urutan' => 1, 'jumlah_tingkat' => 6]);
        JalurPendaftaran::create(['kode' => '001', 'nama' => 'Reguler']);
        (new JenisBiayaService)->create(['kode' => 'REG-J001', 'nama' => 'Registrasi SDTQ', 'tipe' => 'registrasi',
            'kode_jenjang' => 'J001', 'kode_coa_pendapatan' => '4.ZZPW.1', 'kode_unit' => 'ZZPWU']);

        // Ayah & ibu bisa masuk portal (ber-email); wali tidak.
        $this->wali = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '0811', 'email_ayah' => 'ayah@contoh.id',
            'nama_ibu' => 'Ibu', 'email_ibu' => 'ibu@contoh.id', 'nama' => 'Ayah', 'telepon' => '0811', 'status' => 'aktif']);
        $this->anak = Santri::create(['no_pendaftaran' => 'PSB-PW1', 'nama' => 'AHMAD', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'J001', 'jalur' => '001', 'status' => 'aktif', 'id_wali' => $this->wali->id]);
    }

    private function sebagai(string $peran = 'ayah'): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => $peran, 'portal.punya_sandi' => true]);
    }

    private function kabar(): \Illuminate\Support\Collection
    {
        return NotifikasiWali::orderBy('id')->get();
    }

    public function test_kategori_ditentukan_dari_jenis_kabar(): void
    {
        $buat = fn ($jenis) => NotifikasiWali::create(['id_wali' => $this->wali->id, 'peran' => 'ayah', 'judul' => 'x', 'pesan' => 'x', 'jenis' => $jenis]);
        $this->assertSame(['pengajuan', 'tagihan', 'lainnya'],
            [$buat('tarik_tabungan')->kategori, $buat('midtrans_lunas')->kategori, $buat('pin_terkunci')->kategori]);
    }

    public function test_mutasi_dompet_anak_dikabarkan_ke_setiap_orang_ber_email_sesudah_terverifikasi(): void
    {
        $d = DompetSantri::create(['id_santri' => $this->anak->id, 'saldo' => 45000]);
        $mutasi = fn (array $isi) => MutasiDompet::create($isi + ['pemilik' => 'santri', 'id_dompet' => $d->id, 'tanggal' => now()->toDateString(),
            'dicatat_oleh' => $this->staf->id_pengguna]);

        $mutasi(['nomor' => 'M1', 'jenis' => 'jajan', 'nominal' => -5000, 'saldo_setelah' => 45000, 'status' => 'terverifikasi', 'keterangan' => 'Belanja Koperasi Banin']);
        $this->assertSame([['ayah', 'dompet'], ['ibu', 'dompet']], $this->kabar()->map(fn ($n) => [$n->peran, $n->kategori])->all());
        $this->assertSame('AHMAD: Belanja −Rp 5.000', $this->kabar()->first()->judul);
        $this->assertStringContainsString('Sisa uang saku Rp 45.000', $this->kabar()->first()->pesan);

        // Top-up menunggu verifikasi belum dikabarkan; begitu diverifikasi, baru.
        $m = $mutasi(['nomor' => 'M2', 'jenis' => 'topup', 'nominal' => 100000, 'saldo_setelah' => 145000, 'status' => 'menunggu_verifikasi']);
        $this->assertCount(2, $this->kabar());
        $m->update(['status' => 'terverifikasi']);
        $this->assertCount(4, $this->kabar());
        $this->assertSame('AHMAD: Uang saku masuk +Rp 100.000', $this->kabar()->last()->judul);
    }

    public function test_tagihan_baru_dikabarkan_sekali_per_anak_dan_impor_dilewati(): void
    {
        $tagihan = fn ($periode, $nominal, $batch = null) => TagihanSantri::create(['id_santri' => $this->anak->id, 'kode_jenis' => 'REG-J001',
            'perilaku' => 'lain', 'periode' => $periode, 'kode_jenjang' => 'J001', 'nominal' => $nominal, 'sisa' => $nominal,
            'status' => 'belum_bayar', 'id_batch' => $batch]);
        $tagihan('P1', 150000);
        $tagihan('P2', 50000);
        $impor = \App\Models\ImporBatch::query()->insertGetId(['kunci' => 'santri-lama', 'nama_berkas' => 'x.xlsx',
            'dijalankan_oleh' => $this->staf->id_pengguna, 'dijalankan_pada' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $lama = $tagihan('P3', 999000, $impor);

        $this->assertSame(2, KabarKeluarga::sapuTagihanBaru());
        $this->assertSame([['ayah', 'tagihan'], ['ibu', 'tagihan']], $this->kabar()->map(fn ($n) => [$n->peran, $n->kategori])->all());
        $this->assertSame('2 tagihan baru — AHMAD', $this->kabar()->first()->judul);
        $this->assertStringContainsString('total Rp 200.000', $this->kabar()->first()->pesan);
        $this->assertNull($lama->fresh()->dikabarkan_pada);
        $this->assertSame(0, KabarKeluarga::sapuTagihanBaru());
    }

    public function test_kategori_yang_dimatikan_tak_didorong_tapi_tetap_di_kabar(): void
    {
        config(['push.vapid.public' => 'kunci-uji', 'push.vapid.private' => 'rahasia-uji']);
        $this->sebagai('ayah')->postJson(route('portal.push.langganan'), ['endpoint' => 'https://push.contoh/ayah', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])
            ->assertOk();
        $this->sebagai('ibu')->postJson(route('portal.push.langganan'), ['endpoint' => 'https://push.contoh/ibu', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])
            ->assertOk();
        $this->assertSame([['ayah', 'https://push.contoh/ayah'], ['ibu', 'https://push.contoh/ibu']],
            PushLanggananWali::orderBy('id')->get()->map(fn ($l) => [$l->peran, $l->endpoint])->all());

        // Ayah mematikan "Mutasi dompet santri".
        $semua = array_keys(NotifikasiWali::KATEGORI);
        $this->sebagai('ayah')->post(route('portal.push.kategori'), ['kategori' => array_values(array_diff($semua, ['dompet']))])
            ->assertRedirect(route('portal.akun'));
        $s = app(PushWaliService::class);
        $this->assertNotContains('dompet', $s->kategoriNyala($this->wali->id, 'ayah'));
        $this->assertContains('dompet', $s->kategoriNyala($this->wali->id, 'ibu'));
        $this->sebagai('ayah')->get(route('portal.akun'))->assertOk()->assertSee('Mutasi dompet santri')->assertSee('Notifikasi di HP ini');

        foreach (['ayah', 'ibu'] as $p) {
            foreach (['mutasi_dompet', 'tagihan_terbit'] as $jenis) {
                NotifikasiWali::create(['id_wali' => $this->wali->id, 'peran' => $p, 'judul' => $jenis, 'pesan' => 'x', 'jenis' => $jenis]);
            }
        }
        $sasaran = collect($s->sasaran(NotifikasiWali::orderBy('id')->get()))->map(fn ($x) => [$x[0]->peran, $x[1]->jenis])->all();
        $this->assertSame([['ayah', 'tagihan_terbit'], ['ibu', 'mutasi_dompet'], ['ibu', 'tagihan_terbit']], $sasaran);

        // Berhenti hanya di perangkat & orang itu sendiri.
        $this->sebagai('ibu')->deleteJson(route('portal.push.langganan'), ['endpoint' => 'https://push.contoh/ayah'])->assertOk();
        $this->assertSame(2, PushLanggananWali::count());
        $this->sebagai('ibu')->deleteJson(route('portal.push.langganan'), ['endpoint' => 'https://push.contoh/ibu'])->assertOk();
        $this->assertSame(['ayah'], PushLanggananWali::pluck('peran')->all());
    }

    public function test_tanpa_kunci_antrean_dibereskan_tanpa_mengirim(): void
    {
        config(['push.vapid.public' => '', 'push.vapid.private' => '']);
        NotifikasiWali::create(['id_wali' => $this->wali->id, 'peran' => 'ayah', 'judul' => 'x', 'pesan' => 'x', 'jenis' => 'tagihan_terbit']);
        $h = app(PushWaliService::class)->kirimYangBelum();
        $this->assertSame([1, 0], [$h['disapu'], $h['terkirim']]);
        $this->assertSame(0, NotifikasiWali::whereNull('didorong_pada')->count());
    }
}
