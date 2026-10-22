<?php

namespace App\Services\Midtrans;

use App\Exceptions\AppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pembungkus HTTP Midtrans — Snap (membuat pesanan) & Core API (status, batal).
 * Tanpa SDK: tiga panggilan saja, dan Http::fake() membuatnya mudah diuji.
 *
 * Server Key hanya hidup di `.env` dan tak pernah keluar ke peramban; yang
 * dipakai halaman cuma Client Key (untuk snap.js).
 */
class KlienMidtrans
{
    public static function siap(): bool
    {
        return filled(config('services.midtrans.server_key')) && filled(config('services.midtrans.client_key'));
    }

    public static function produksi(): bool
    {
        return (bool) config('services.midtrans.production');
    }

    public static function urlSnapJs(): string
    {
        return (self::produksi() ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com').'/snap/snap.js';
    }

    private static function urlSnap(): string
    {
        return (self::produksi() ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com').'/snap/v1/transactions';
    }

    private static function urlApi(string $jalur): string
    {
        return (self::produksi() ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com').'/v2/'.$jalur;
    }

    /**
     * Notifikasi sungguhan dari Midtrans: SHA-512(order_id + status_code + gross_amount + Server Key).
     * Hanya penyaring murah — status yang DIPAKAI tetap ditanyakan ulang ke API (lihat sinkron()).
     *
     * @param  array<string,mixed>  $n
     */
    public static function tandaTanganSah(array $n): bool
    {
        $kunci = (string) config('services.midtrans.server_key');
        if ($kunci === '' || ! isset($n['order_id'], $n['status_code'], $n['gross_amount'], $n['signature_key'])) {
            return false;
        }
        $harap = hash('sha512', $n['order_id'].$n['status_code'].$n['gross_amount'].$kunci);

        return hash_equals($harap, (string) $n['signature_key']);
    }

    private function http()
    {
        return Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()->asJson()->timeout(20);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{token:string, redirect_url:string}
     */
    public function buatSnap(array $payload): array
    {
        try {
            $res = $this->http()->post(self::urlSnap(), $payload);
        } catch (ConnectionException $e) {
            Log::warning('Midtrans Snap tak terjangkau', ['galat' => $e->getMessage()]);
            throw new AppException(502, __('Layanan pembayaran online sedang tidak bisa dihubungi. Coba lagi sebentar lagi.'));
        }
        if (! $res->successful() || ! $res->json('token')) {
            Log::warning('Midtrans Snap menolak', ['status' => $res->status(), 'isi' => $res->json()]);
            throw new AppException(502, __('Pembayaran online belum bisa dibuat. Coba lagi sebentar lagi, atau pakai transfer bank.'));
        }

        return ['token' => (string) $res->json('token'), 'redirect_url' => (string) $res->json('redirect_url')];
    }

    /**
     * Status transaksi menurut Midtrans. Pesanan Snap yang belum dipilih banknya
     * dijawab `status_code` 404 — dikembalikan apa adanya, bukan galat.
     *
     * @return array<string,mixed>
     */
    public function status(string $orderId): array
    {
        try {
            $res = $this->http()->get(self::urlApi(rawurlencode($orderId).'/status'));
        } catch (ConnectionException $e) {
            throw new AppException(502, __('Status pembayaran belum bisa diperiksa. Coba lagi sebentar lagi.'));
        }
        if ($res->serverError()) {
            throw new AppException(502, __('Status pembayaran belum bisa diperiksa. Coba lagi sebentar lagi.'));
        }

        return (array) $res->json();
    }

    /**
     * Status pesanan di lapisan SNAP, lewat token. Akun Midtrans platform baru mencatat
     * transaksi di Core API dengan order_id = transaction_id buatan Midtrans (`A1…`),
     * sehingga order_id kita hanya dikenal di sini — inilah penghubung keduanya.
     * Dipakai HANYA untuk menemukan transaction_id; status yang dibukukan tetap dari
     * Core API (terautentikasi Server Key).
     *
     * @return array<string,mixed>
     */
    public function statusSnap(string $token): array
    {
        $dasar = self::produksi() ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
        try {
            $res = Http::acceptJson()->timeout(20)->get($dasar.'/snap/v1/transactions/'.rawurlencode($token).'/status');
        } catch (ConnectionException) {
            return [];
        }

        return $res->successful() ? (array) $res->json() : [];
    }

    /** Batalkan VA yang belum dibayar. Hasilnya tak dipercaya — status sesudahnya ditanyakan ulang. */
    public function batalkan(string $orderId): void
    {
        try {
            $this->http()->post(self::urlApi(rawurlencode($orderId).'/cancel'));
        } catch (ConnectionException) {
            // Diabaikan: pemeriksaan status sesudahnya yang menentukan.
        }
    }
}
