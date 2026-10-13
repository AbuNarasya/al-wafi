<?php

namespace App\Services\Midtrans;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\TagihanSantri;
use App\Models\TransaksiMidtrans;
use App\Models\Wali;
use App\Services\Modules\AutoDebetService;
use App\Services\Modules\DompetService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\PortalWali\KabarWali;
use App\Services\PortalWali\PembayaranPortal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PEMBAYARAN ONLINE PORTAL WALI LEWAT MIDTRANS (Snap, Virtual Account saja).
 *
 * Dua alur, sama dengan aturan portal yang sudah ada:
 *  • ISI DOMPET WALI — keluarga yang punya santri aktif.
 *  • BAYAR TAGIHAN PSB — calon santri (alur 'transfer'/'keduanya').
 *
 * Tiga tahap:
 *  1. mulai*()   — pesanan `transaksi_midtrans` + token Snap. Tak ada jurnal,
 *                  tak ada saldo; nominalnya cuma "dipesan".
 *  2. sinkron()  — status DITANYAKAN ULANG ke API Midtrans (bukan dipercaya dari
 *                  isi notifikasi). Dipanggil notifikasi, halaman pesanan, & batal.
 *  3. bukukan()  — saat `settlement`: top-up / pembayaran lahir LANGSUNG
 *                  terverifikasi beserta jurnalnya (JurnalGateway). Pesanan yang
 *                  sudah bukan `menunggu` tak pernah diproses ulang, jadi
 *                  notifikasi ganda aman.
 *
 * Biaya VA ditanggung wali: total = nominal + biaya; saldo/tagihan bertambah
 * utuh sebesar nominal.
 */
class PembayaranMidtrans
{
    public const MIN_ISI_DOMPET = 10000;

    public function __construct(private readonly KlienMidtrans $klien = new KlienMidtrans) {}

    public static function biaya(): string
    {
        return Money::of((int) config('services.midtrans.biaya_va'));
    }

    /** Kunci terpasang + rekening & akun biaya ada. Bila tidak, tombol bayar online tak ditampilkan. */
    public static function siap(): bool
    {
        if (! KlienMidtrans::siap()) {
            return false;
        }
        $rek = BankAccount::find(config('services.midtrans.kode_rekening'));

        return $rek && $rek->status === 'aktif' && CoaDetail::whereKey(config('services.midtrans.akun_biaya'))->exists();
    }

    // ---- 1. Pesanan ----

    /** @param  array{id:int,peran:string}  $pencatat */
    public function mulaiIsiDompet(Wali $wali, array $pencatat, string $nominal): TransaksiMidtrans
    {
        $this->assertSiap();
        if (! PembayaranPortal::keluargaBerdompet($wali->id)) {
            throw new AppException(422, 'Dompet aktif setelah anak Anda berstatus santri aktif.');
        }
        $nominal = $this->rupiahBulat($nominal);
        if (Money::lt($nominal, (string) self::MIN_ISI_DOMPET)) {
            throw new AppException(422, 'Isi saldo online minimal Rp '.number_format(self::MIN_ISI_DOMPET, 0, ',', '.').'.');
        }

        return $this->buatPesanan($wali, $pencatat, null, $nominal, 'Isi Dompet Wali');
    }

    /** @param  array{id:int,peran:string}  $pencatat */
    public function mulaiBayarTagihan(Wali $wali, array $pencatat, TagihanSantri $tagihan, string $nominal): TransaksiMidtrans
    {
        $this->assertSiap();
        $tagihan->loadMissing(['santri', 'jenis']);
        if (! $tagihan->santri || $tagihan->santri->id_wali !== $wali->id) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        [$alur, $alasan] = PembayaranPortal::alur($tagihan);
        if (! PembayaranPortal::bisaTransfer($alur)) {
            throw new AppException(422, $alasan ?? 'Tagihan ini dibayar dari Dompet Wali, bukan lewat pembayaran online.');
        }

        // Satu tagihan, satu VA yang hidup: pesanan lama dibatalkan dulu supaya wali tak
        // membayar dua VA untuk tagihan yang sama.
        foreach (TransaksiMidtrans::where('id_tagihan', $tagihan->id)->where('status', 'menunggu')->get() as $lama) {
            $this->batalkan($lama);
        }
        $tagihan->refresh();

        $nominal = $this->rupiahBulat($nominal);
        $ruang = PembayaranPortal::ruang($tagihan);
        if (Money::lte($nominal, '0') || Money::gt($nominal, $ruang)) {
            throw new AppException(422, 'Nominal melebihi sisa tagihan yang belum tercatat (Rp '.number_format((float) $ruang, 0, ',', '.').').');
        }

        $nama = \App\Http\Controllers\PortalKeluargaController::namaTagihan($tagihan).' — '.$tagihan->santri->nama;

        return $this->buatPesanan($wali, $pencatat, $tagihan, $nominal, $nama);
    }

    /** @param  array{id:int,peran:string}  $pencatat */
    private function buatPesanan(Wali $wali, array $pencatat, ?TagihanSantri $tagihan, string $nominal, string $nama): TransaksiMidtrans
    {
        $biaya = self::biaya();
        $jam = max(1, (int) config('services.midtrans.kedaluwarsa_jam'));
        $t = TransaksiMidtrans::create([
            'order_id' => 'AW-'.now()->format('ymdHis').'-'.Str::upper(Str::random(6)),
            'jenis' => $tagihan ? 'bayar_tagihan' : 'isi_dompet', 'id_wali' => $wali->id, 'peran' => $pencatat['peran'],
            'id_tagihan' => $tagihan?->id, 'nominal' => $nominal, 'biaya' => $biaya, 'total' => Money::add($nominal, $biaya),
            'status' => 'menunggu', 'kedaluwarsa_pada' => now()->addHours($jam),
        ]);

        $email = (string) $wali->{'email_'.$pencatat['peran']};
        try {
            $snap = $this->klien->buatSnap([
                'transaction_details' => ['order_id' => $t->order_id, 'gross_amount' => (int) $t->total],
                'item_details' => [
                    ['id' => 'NOMINAL', 'price' => (int) $nominal, 'quantity' => 1, 'name' => mb_substr($nama, 0, 50)],
                    ['id' => 'BIAYA-VA', 'price' => (int) $biaya, 'quantity' => 1, 'name' => 'Biaya layanan Virtual Account'],
                ],
                'customer_details' => array_filter([
                    'first_name' => mb_substr((string) ($wali->{'nama_'.$pencatat['peran']} ?: $wali->nama), 0, 50),
                    'email' => $email ?: null,
                ]),
                'enabled_payments' => config('services.midtrans.bank_va'),
                'expiry' => ['unit' => 'hour', 'duration' => $jam],
                'callbacks' => ['finish' => route('portal.online.lihat', $t->order_id)],
            ]);
        } catch (AppException $e) {
            $t->update(['status' => 'gagal', 'catatan' => 'Snap gagal dibuat: '.$e->getMessage()]);
            throw $e;
        }
        $t->update(['snap_token' => $snap['token'], 'snap_url' => $snap['redirect_url']]);

        return $t;
    }

    // ---- 2. Status ----

    /** Tanyakan status ke Midtrans lalu terapkan. Pesanan tak dikenal → null (notifikasi uji dari dasbor). */
    public function sinkron(string $orderId): ?TransaksiMidtrans
    {
        $t = TransaksiMidtrans::where('order_id', $orderId)->first();
        if (! $t || $t->status !== 'menunggu') {
            return $t;
        }

        return $this->terapkan($t, $this->klien->status($orderId));
    }

    /** Batalkan pesanan yang belum dibayar — kecuali ternyata sudah lunas di Midtrans. */
    public function batalkan(TransaksiMidtrans $t): TransaksiMidtrans
    {
        if ($t->status !== 'menunggu') {
            return $t;
        }
        $this->klien->batalkan($t->order_id);
        $t = $this->terapkan($t, $this->klien->status($t->order_id));
        if ($t->status === 'menunggu') {
            // Belum pernah memilih bank (Midtrans menjawab 404) atau masih pending sesudah dibatalkan:
            // pesanan ditutup di sisi kita. VA-nya — bila ada — ikut dibatalkan di atas.
            $t->update(['status' => 'batal', 'catatan' => 'Dibatalkan wali / diganti pesanan baru.']);
        }

        return $t;
    }

    /** @param  array<string,mixed>  $s  jawaban API status Midtrans */
    private function terapkan(TransaksiMidtrans $t, array $s): TransaksiMidtrans
    {
        if ((string) ($s['status_code'] ?? '') === '404') {
            return $t; // Snap dibuat tapi bank belum dipilih — tetap menunggu.
        }
        if (($s['order_id'] ?? null) !== $t->order_id) {
            throw new AppException(502, 'Jawaban Midtrans tidak cocok dengan pesanan.');
        }

        $status = (string) ($s['transaction_status'] ?? '');
        $hasil = DB::transaction(function () use ($t, $s, $status) {
            $t = TransaksiMidtrans::whereKey($t->id)->lockForUpdate()->first();
            if ($t->status !== 'menunggu') {
                return [$t, false];
            }
            $t->fill($this->infoVa($s) + ['status_midtrans' => $status]);

            $lunas = $status === 'settlement' || ($status === 'capture' && ($s['fraud_status'] ?? 'accept') === 'accept');
            if ($lunas) {
                if (! Money::eq((string) ($s['gross_amount'] ?? '0'), $t->total)) {
                    // Nominal di Midtrans berbeda dari pesanan — jangan dibukukan otomatis.
                    Log::error('Midtrans: gross_amount tak cocok', ['order_id' => $t->order_id, 'midtrans' => $s['gross_amount'] ?? null, 'pesanan' => $t->total]);
                    $t->update(['catatan' => 'Nominal di Midtrans tidak cocok dengan pesanan — periksa manual.']);

                    return [$t, false];
                }
                $this->bukukan($t, $s);

                return [$t, true];
            }

            $akhir = match ($status) {
                'expire' => 'kedaluwarsa', 'cancel' => 'batal', 'deny', 'failure' => 'gagal', default => null,
            };
            $t->fill($akhir ? ['status' => $akhir] : [])->save();

            return [$t, false];
        });

        [$t, $baruLunas] = $hasil;
        if ($baruLunas) {
            $this->sesudahLunas($t);
        }

        return $t;
    }

    /** @return array<string,mixed> */
    private function infoVa(array $s): array
    {
        $info = ['transaction_id' => $s['transaction_id'] ?? null];
        if (! empty($s['va_numbers'][0])) {
            $info += ['bank' => $s['va_numbers'][0]['bank'] ?? null, 'nomor_va' => $s['va_numbers'][0]['va_number'] ?? null];
        } elseif (! empty($s['permata_va_number'])) {
            $info += ['bank' => 'permata', 'nomor_va' => $s['permata_va_number']];
        } elseif (! empty($s['bill_key'])) {
            $info += ['bank' => 'mandiri', 'nomor_va' => ($s['biller_code'] ?? '').' / '.$s['bill_key']];
        }

        return array_filter($info, fn ($v) => $v !== null);
    }

    // ---- 3. Pembukuan ----

    /** Di dalam transaksi terapkan(), pesanan sudah dikunci. */
    private function bukukan(TransaksiMidtrans $t, array $s): void
    {
        $tanggal = isset($s['settlement_time'])
            ? Carbon::parse($s['settlement_time'], 'Asia/Jakarta')->toDateString()
            : Carbon::now()->toDateString();
        $umum = [
            'tanggal' => $tanggal, 'kode_rekening' => (string) config('services.midtrans.kode_rekening'),
            'akun_biaya' => (string) config('services.midtrans.akun_biaya'),
            'pencatat' => ['id' => $t->id_wali, 'peran' => $t->peran],
        ];
        $biaya = Money::of($t->biaya);
        $keDompet = Money::of($t->nominal);

        if ($t->jenis === 'bayar_tagihan') {
            $tagihan = TagihanSantri::whereKey($t->id_tagihan)->lockForUpdate()->first();
            // Sisa bisa sudah berkurang sejak pesanan dibuat (mis. setoran lain diverifikasi).
            $porsi = $tagihan && $tagihan->berlaku() && $tagihan->status !== 'lunas'
                ? (Money::lt($tagihan->sisa, $keDompet) ? Money::of($tagihan->sisa) : $keDompet) : '0';
            if (Money::gtZero($porsi)) {
                $p = (new PembayaranSantriService)->bukukanGateway($tagihan, $umum + [
                    'nominal' => $porsi, 'biaya' => $biaya, 'external_id' => $t->order_id,
                    'provider_ref' => $s['transaction_id'] ?? null, 'metode' => 'va_'.($t->bank ?? 'midtrans'),
                ]);
                $t->id_pembayaran = $p->id;
                $keDompet = Money::sub($keDompet, $porsi);
                $biaya = '0';
            }
        }

        // Isi dompet — atau KELEBIHAN bayar tagihan: uangnya sudah diterima, jadi
        // dititipkan ke Dompet Wali alih-alih ditolak.
        if (Money::gtZero($keDompet)) {
            $m = (new DompetService)->topUpGateway($umum + [
                'id_wali' => $t->id_wali, 'nominal' => $keDompet, 'biaya' => $biaya,
                'keterangan' => ($t->jenis === 'isi_dompet' ? 'Isi dompet online (Midtrans VA)' : 'Kelebihan bayar online tagihan (Midtrans VA)')
                    .' — '.$t->order_id,
            ]);
            $t->id_mutasi = $m->id;
        }

        $t->fill(['status' => 'lunas', 'dibayar_pada' => Carbon::now()])->save();
    }

    /** Di LUAR transaksi — kegagalannya tak membatalkan pembayaran yang sudah sah. */
    private function sesudahLunas(TransaksiMidtrans $t): void
    {
        try {
            if ($t->id_pembayaran) {
                (new PembayaranSantriService)->sesudahBayarGateway($t->tagihan()->first());
            }
            if ($t->id_mutasi) {
                (new AutoDebetService)->jalankan(null, $t->dibayar_pada?->toDateString(), [$t->id_wali],
                    ['id' => $t->id_wali, 'peran' => $t->peran]);
            }
            $sumber = $t->pembayaran()->first() ?? $t->mutasi()->first();
            if ($sumber) {
                KabarWali::kePencatat($sumber, [
                    'judul' => 'Pembayaran online diterima',
                    'pesan' => 'Rp '.number_format((float) $t->nominal, 0, ',', '.').' sudah kami terima ('.$t->order_id.').',
                    'jenis' => 'midtrans_lunas', 'ref_jenis' => 'TransaksiMidtrans', 'ref_id' => (string) $t->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Langkah susulan Midtrans gagal', ['order_id' => $t->order_id, 'pesan' => $e->getMessage()]);
        }
    }

    private function assertSiap(): void
    {
        if (! self::siap()) {
            throw new AppException(422, 'Pembayaran online belum tersedia. Silakan pakai transfer bank.');
        }
    }

    private function rupiahBulat(string $nominal): string
    {
        if (! preg_match('/^\d+(\.0+)?$/', trim($nominal))) {
            throw new AppException(422, 'Nominal harus berupa rupiah bulat.');
        }

        return Money::of((int) $nominal);
    }
}
