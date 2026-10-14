<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\DompetWali;
use App\Models\JenisBiaya;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Jenjang;
use App\Models\Level;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\TransaksiMidtrans;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PAYMENT GATEWAY MIDTRANS (Snap, Virtual Account) — keputusan user 13 Okt 2026.
 *
 * Yang dijaga: pesanan tak menyentuh buku besar; hanya status yang ditanyakan ulang
 * ke API (bukan isi notifikasi) yang membukukan; notifikasi ganda tak membukukan dua
 * kali; biaya VA ditanggung wali & potongan admin Midtrans dijurnal otomatis.
 */
class MidtransTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';

    private const GRP = 'ZZMT';

    private const KUNCI = 'SB-Mid-server-UJI';

    private const REK = '1.1.01.003';

    private const BIAYA = '2.1.01.010';

    private Wali $wali;

    /** @var array<string,array<string,mixed>> jawaban API status per order_id / transaction_id */
    private array $statusApi = [];

    /** @var array<string,array<string,mixed>> jawaban status Snap per token */
    private array $statusSnap = [];

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();
        config(['services.midtrans.server_key' => self::KUNCI, 'services.midtrans.client_key' => 'SB-Mid-client-UJI',
            'services.midtrans.production' => false, 'services.midtrans.biaya_va' => 4440]);

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        User::create(['username' => 'zzmt_keu', 'nama' => 'Keuangan', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'tim_keuangan' => true, 'status' => 'aktif']);
        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP', 'jumlah_tingkat' => 3]);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        BusinessUnit::create(['kode_unit' => 'ZZMTU', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Midtrans']);
        foreach ([['4.ZZMT.1', 'Pendapatan', 'kredit'], ['1.ZZMT.2', 'Piutang', 'debet'], [self::REK, 'Rekening Midtrans', 'debet'],
            [self::BIAYA, 'Titipan Biaya Admin PG', 'kredit'], [DompetPolicy::COA_TITIPAN['wali'], 'Titipan Wali', 'kredit']] as [$k, $n, $s]) {
            CoaDetail::create(['kode_coa' => $k, 'nama_coa' => $n, 'kode_grup' => self::GRP, 'jenis_saldo' => $s]);
        }
        BankAccount::create(['kode_coa' => self::REK, 'nama_rekening' => 'Rekening Midtrans', 'jenis_rekening' => 'bank',
            'nama_bank' => 'Midtrans', 'status' => 'aktif']);
        foreach ([['spp', 'SPP'], ['registrasi', 'Registrasi']] as [$kode, $nama]) {
            TipeBiaya::firstOrCreate(['kode' => $kode], ['nama' => $nama, 'perilaku' => $kode, 'urutan' => 1, 'bawaan' => true, 'status' => 'aktif']);
            JenisBiaya::create(['kode' => strtoupper($kode), 'nama' => $nama, 'tipe' => $kode, 'kode_coa_pendapatan' => '4.ZZMT.1',
                'kode_coa_piutang' => '1.ZZMT.2', 'kode_unit' => 'ZZMTU', 'status' => 'aktif']);
        }

        $this->wali = Wali::create(['kontak_utama' => 'ibu', 'nama_ibu' => 'Ibu Aminah', 'telepon_ibu' => '0811',
            'email_ibu' => 'aminah@contoh.id', 'nama' => 'Ibu Aminah', 'telepon' => '0811', 'status' => 'aktif']);

        Http::fake(function (PermintaanHttp $r) {
            if (preg_match('#/snap/v1/transactions/([^/]+)/status$#', $r->url(), $m)) {
                return Http::response($this->statusSnap[urldecode($m[1])] ?? ['status_code' => '404'], isset($this->statusSnap[urldecode($m[1])]) ? 200 : 404);
            }
            if (str_contains($r->url(), '/snap/v1/transactions')) {
                return Http::response(['token' => 'TOKEN-'.$r['transaction_details']['order_id'], 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/x'], 201);
            }
            if (preg_match('#/v2/([^/]+)/status$#', $r->url(), $m)) {
                return Http::response($this->statusApi[urldecode($m[1])] ?? ['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
            }

            return Http::response(['status_code' => '200']);
        });
    }

    private function santri(string $nama, string $status): Santri
    {
        static $n = 0;

        return Santri::create(['no_pendaftaran' => 'ZZMT-'.(++$n), 'nama' => $nama, 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'tingkat' => 1, 'tahun_ajaran' => self::TA, 'jalur' => 'reguler',
            'status' => $status, 'id_wali' => $this->wali->id]);
    }

    private function tagihan(Santri $s, string $perilaku, int $nominal): TagihanSantri
    {
        return TagihanSantri::create(['id_santri' => $s->id, 'kode_jenis' => strtoupper($perilaku), 'perilaku' => $perilaku,
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA, 'periode' => 'P1',
            'nominal' => $nominal, 'sisa' => $nominal, 'status' => 'belum_bayar']);
    }

    private function sebagaiIbu(): static
    {
        return $this->actingAs($this->wali, 'wali')->withSession(['portal.peran' => 'ibu', 'portal.punya_sandi' => true]);
    }

    /** Midtrans mencatat status ini untuk pesanan tsb (yang dijawab API status). */
    private function apiMenjawab(TransaksiMidtrans $t, string $status, array $tambahan = []): void
    {
        $this->statusApi[$t->order_id] = $tambahan + [
            'status_code' => $status === 'settlement' ? '200' : '201', 'order_id' => $t->order_id,
            'transaction_status' => $status, 'transaction_id' => 'TRX-'.$t->order_id,
            'gross_amount' => number_format((float) $t->total, 2, '.', ''), 'payment_type' => 'bank_transfer',
            'va_numbers' => [['bank' => 'bca', 'va_number' => '12345678901']],
            'settlement_time' => '2026-10-13 10:00:00',
        ];
    }

    /** Notifikasi bertanda tangan sah — isinya boleh saja berbohong. */
    private function notifikasi(TransaksiMidtrans $t, string $statusDiKlaim = 'settlement', ?string $tandaTangan = null)
    {
        $kode = '200';
        $gross = number_format((float) $t->total, 2, '.', '');

        return $this->postJson(route('midtrans.notifikasi'), [
            'order_id' => $t->order_id, 'status_code' => $kode, 'gross_amount' => $gross,
            'transaction_status' => $statusDiKlaim,
            'signature_key' => $tandaTangan ?? hash('sha512', $t->order_id.$kode.$gross.self::KUNCI),
        ]);
    }

    private function saldoAkun(string $kode): float
    {
        return (float) JournalLine::where('kode_coa', $kode)->sum('debet') - (float) JournalLine::where('kode_coa', $kode)->sum('kredit');
    }

    // ---- Isi dompet ----

    public function test_isi_dompet_online_membuat_pesanan_tanpa_menyentuh_buku_besar(): void
    {
        $this->santri('Ahmad', 'aktif');

        $res = $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);

        $t = TransaksiMidtrans::sole();
        $res->assertRedirect(route('portal.online.lihat', $t->order_id));
        $this->assertSame(['isi_dompet', 'menunggu', '100000.00', '4440.00', '104440.00'],
            [$t->jenis, $t->status, (string) $t->nominal, (string) $t->biaya, (string) $t->total]);
        $this->assertSame('TOKEN-'.$t->order_id, $t->snap_token);
        Http::assertSent(fn (PermintaanHttp $r) => str_contains($r->url(), 'app.sandbox.midtrans.com/snap/v1/transactions')
            && $r['transaction_details']['gross_amount'] === 104440
            && $r['enabled_payments'] === config('services.midtrans.bank_va')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KUNCI.':')));
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, MutasiDompet::count());

        $this->sebagaiIbu()->get(route('portal.online.lihat', $t->order_id))->assertOk()
            ->assertSee('Rp 104.440')->assertSee('Pilih bank &amp; bayar', false);
    }

    public function test_lunas_menambah_saldo_utuh_dan_menjurnal_potongan_admin(): void
    {
        $this->santri('Ahmad', 'aktif');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);
        $t = TransaksiMidtrans::sole();
        $this->apiMenjawab($t, 'settlement');

        $this->notifikasi($t)->assertOk();
        $this->notifikasi($t)->assertOk(); // notifikasi ganda

        $t->refresh();
        $this->assertSame('lunas', $t->status);
        $this->assertSame(['bca', '12345678901'], [$t->bank, $t->nomor_va]);
        $this->assertSame('100000.00', (string) DompetWali::where('id_wali', $this->wali->id)->value('saldo'));

        $m = MutasiDompet::sole();
        $this->assertSame(['topup', 'terverifikasi', 'midtrans', self::REK, '2026-10-13'],
            [$m->jenis, $m->status, $m->sumber, $m->kode_rekening, $m->tanggal->toDateString()]);
        $this->assertSame($this->wali->id, $m->dicatat_oleh_wali);

        // Dua jurnal: penerimaan + potongan admin. Tak berlipat walau notifikasinya dua kali.
        $this->assertSame(2, JournalEntry::count());
        $this->assertEqualsWithDelta(100000, $this->saldoAkun(self::REK), 0.001, 'yang menunggu cair = nominal');
        $this->assertEqualsWithDelta(0, $this->saldoAkun(self::BIAYA), 0.001, 'biaya dari wali habis dipotong Midtrans');
        $this->assertEqualsWithDelta(-100000, $this->saldoAkun(DompetPolicy::COA_TITIPAN['wali']), 0.001);
        $this->assertSame(1, JournalLine::where('kode_coa', self::REK)->where('debet', 104440)->count());
    }

    public function test_tanda_tangan_palsu_ditolak(): void
    {
        $this->santri('Ahmad', 'aktif');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);
        $t = TransaksiMidtrans::sole();
        $this->apiMenjawab($t, 'settlement');

        $this->notifikasi($t, 'settlement', str_repeat('a', 128))->assertForbidden();

        $this->assertSame('menunggu', $t->refresh()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    /** Notifikasi bertanda tangan sah yang MENGAKU lunas tak cukup: API-lah yang menentukan. */
    public function test_status_dari_api_bukan_dari_isi_notifikasi(): void
    {
        $this->santri('Ahmad', 'aktif');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);
        $t = TransaksiMidtrans::sole();
        $this->apiMenjawab($t, 'pending');

        $this->notifikasi($t, 'settlement')->assertOk();

        $t->refresh();
        $this->assertSame('menunggu', $t->status);
        $this->assertSame('12345678901', $t->nomor_va, 'nomor VA tetap dicatat untuk ditampilkan ke wali');
        $this->assertSame(0, JournalEntry::count());
    }

    /** Alamat pantauan halaman pesanan: berganti sendiri begitu lunas; pesanan keluarga lain tak terlihat. */
    public function test_pemantau_status_halaman_pesanan(): void
    {
        $this->santri('Ahmad', 'aktif');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);
        $t = TransaksiMidtrans::sole();

        $this->sebagaiIbu()->getJson(route('portal.online.status', $t->order_id))->assertExactJson(['status' => 'menunggu']);
        $this->sebagaiIbu()->get(route('portal.online.lihat', $t->order_id))->assertSee('diperbarui otomatis')
            ->assertSee('Pembayaran sedang diproses');

        $this->apiMenjawab($t, 'settlement');
        $this->notifikasi($t)->assertOk();
        $this->sebagaiIbu()->getJson(route('portal.online.status', $t->order_id))->assertExactJson(['status' => 'lunas']);

        $lain = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Lain', 'telepon_ayah' => '0822', 'nama' => 'Lain',
            'telepon' => '0822', 'status' => 'aktif']);
        $this->actingAs($lain, 'wali')->withSession(['portal.peran' => 'ayah', 'portal.punya_sandi' => true])
            ->getJson(route('portal.online.status', $t->order_id))->assertNotFound();
    }

    public function test_kedaluwarsa_ditutup_tanpa_jurnal(): void
    {
        $this->santri('Ahmad', 'aktif');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);
        $t = TransaksiMidtrans::sole();
        $this->apiMenjawab($t, 'expire');

        $this->sebagaiIbu()->get(route('portal.online.lihat', $t->order_id))->assertOk()->assertSee('Kedaluwarsa');

        $this->assertSame('kedaluwarsa', $t->refresh()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    /**
     * Akun Midtrans platform baru (ditemukan saat uji sandbox 13 Okt 2026): Core API tak
     * mengenal order_id kita — transaksinya bernomor transaction_id (`A1…`), dan notifikasi
     * pun membawa nomor itu. Penghubungnya status Snap (lewat token).
     */
    private function platformBaru(TransaksiMidtrans $t, string $trx, string $status = 'settlement'): void
    {
        $this->statusSnap[$t->snap_token] = ['status_code' => '200', 'order_id' => $t->order_id, 'transaction_id' => $trx,
            'transaction_status' => $status];
        $this->statusApi[$trx] = ['status_code' => '200', 'order_id' => $trx, 'transaction_id' => $trx,
            'transaction_status' => $status, 'fraud_status' => 'accept',
            'gross_amount' => number_format((float) $t->total, 2, '.', ''), 'payment_type' => 'bank_transfer',
            'va_numbers' => [['bank' => 'bsi', 'va_number' => '92617201012154537']], 'settlement_time' => '2026-10-13 12:47:44'];
    }

    public function test_platform_baru_notifikasi_bernomor_transaction_id(): void
    {
        $this->santri('Ahmad', 'aktif');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000]);
        $t = TransaksiMidtrans::sole();
        $this->platformBaru($t, 'A1UJI0001');

        $kode = '200';
        $gross = number_format((float) $t->total, 2, '.', '');
        $this->postJson(route('midtrans.notifikasi'), [
            'order_id' => 'A1UJI0001', 'status_code' => $kode, 'gross_amount' => $gross, 'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', 'A1UJI0001'.$kode.$gross.self::KUNCI),
        ])->assertOk();

        $t->refresh();
        $this->assertSame(['lunas', 'A1UJI0001', 'bsi'], [$t->status, $t->transaction_id, $t->bank]);
        $this->assertSame('100000.00', (string) DompetWali::where('id_wali', $this->wali->id)->value('saldo'));
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_platform_baru_cek_status_dari_halaman_pesanan(): void
    {
        $reg = $this->tagihan($this->santri('Calon', 'calon'), 'registrasi', 500000);
        $this->sebagaiIbu()->post(route('portal.online.tagihan', $reg->id), ['nominal' => 500000]);
        $t = TransaksiMidtrans::sole();
        $this->platformBaru($t, 'A1UJI0002');

        $this->sebagaiIbu()->get(route('portal.online.lihat', $t->order_id))->assertOk()->assertSee('pembayaran diterima');

        $this->assertSame('lunas', $t->refresh()->status);
        $this->assertSame('A1UJI0002', PembayaranSantri::sole()->provider_ref);
        $this->assertSame('lunas', $reg->refresh()->status);
    }

    public function test_belum_dikonfigurasi_pilihan_online_tak_tampil(): void
    {
        $this->santri('Ahmad', 'aktif');
        config(['services.midtrans.server_key' => null]);

        $this->sebagaiIbu()->get(route('portal.dompet.isi'))->assertOk()->assertDontSee('Bayar online (VA)');
        $this->sebagaiIbu()->post(route('portal.online.dompet'), ['nominal' => 100000])->assertSessionHas('error');
        $this->assertSame(0, TransaksiMidtrans::count());
    }

    // ---- Tagihan calon ----

    public function test_calon_bayar_registrasi_online_lunas_dan_maju_tahap(): void
    {
        $calon = $this->santri('Calon', 'calon');
        $reg = $this->tagihan($calon, 'registrasi', 500000);

        $this->sebagaiIbu()->get(route('portal.bayar', $reg->id))->assertOk()->assertSee('Online (VA)');
        $this->sebagaiIbu()->post(route('portal.online.tagihan', $reg->id), ['nominal' => 500000]);
        $t = TransaksiMidtrans::sole();
        $this->assertSame(['bayar_tagihan', $reg->id], [$t->jenis, $t->id_tagihan]);
        $this->apiMenjawab($t, 'settlement');

        $this->notifikasi($t)->assertOk();

        $p = PembayaranSantri::sole();
        $this->assertSame(['terverifikasi', 'midtrans', $t->order_id, 'TRX-'.$t->order_id, '500000.00'],
            [$p->status, $p->sumber, $p->external_id, $p->provider_ref, (string) $p->nominal]);
        $this->assertSame('lunas', $reg->refresh()->status);
        $this->assertSame('terbayar', $calon->refresh()->status, 'registrasi lunas memajukan tahap seperti verifikasi biasa');
        $this->assertEqualsWithDelta(-500000, $this->saldoAkun('4.ZZMT.1'), 0.001);
        $this->assertEqualsWithDelta(500000, $this->saldoAkun(self::REK), 0.001);
        $this->assertSame(0, MutasiDompet::count());
    }

    public function test_tagihan_santri_aktif_tidak_bisa_dibayar_online(): void
    {
        $spp = $this->tagihan($this->santri('Ahmad', 'aktif'), 'spp', 300000);

        $this->sebagaiIbu()->post(route('portal.online.tagihan', $spp->id), ['nominal' => 300000])->assertSessionHas('error');
        $this->assertSame(0, TransaksiMidtrans::count());
    }

    public function test_pesanan_baru_membatalkan_va_lama_tagihan_yang_sama(): void
    {
        $reg = $this->tagihan($this->santri('Calon', 'calon'), 'registrasi', 500000);
        $this->sebagaiIbu()->post(route('portal.online.tagihan', $reg->id), ['nominal' => 500000]);
        $lama = TransaksiMidtrans::sole();
        $this->apiMenjawab($lama, 'pending'); // VA sudah terbit, belum dibayar

        $this->sebagaiIbu()->post(route('portal.online.tagihan', $reg->id), ['nominal' => 500000]);

        $this->assertSame('batal', $lama->refresh()->status);
        $this->assertSame(1, TransaksiMidtrans::where('status', 'menunggu')->count());
        Http::assertSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/v2/'.$lama->order_id.'/cancel'));
    }

    /** Sisa tagihan berkurang sebelum VA dibayar: kelebihannya dititipkan ke Dompet Wali, bukan hilang. */
    public function test_kelebihan_bayar_masuk_dompet_wali(): void
    {
        $reg = $this->tagihan($this->santri('Calon', 'calon'), 'registrasi', 500000);
        $this->sebagaiIbu()->post(route('portal.online.tagihan', $reg->id), ['nominal' => 500000]);
        $t = TransaksiMidtrans::sole();
        $reg->update(['sisa' => 200000, 'status' => 'sebagian']); // setoran lain keburu diverifikasi
        $this->apiMenjawab($t, 'settlement');

        $this->notifikasi($t)->assertOk();

        $this->assertSame('200000.00', (string) PembayaranSantri::sole()->nominal);
        $this->assertSame('lunas', $reg->refresh()->status);
        $this->assertSame('300000.00', (string) DompetWali::where('id_wali', $this->wali->id)->value('saldo'));
        $this->assertEqualsWithDelta(500000, $this->saldoAkun(self::REK), 0.001);
        $this->assertEqualsWithDelta(0, $this->saldoAkun(self::BIAYA), 0.001);
    }
}
