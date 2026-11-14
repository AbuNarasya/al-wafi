<?php

namespace App\Services\PortalWali;

use App\Models\Santri;
use App\Models\Wali;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HISTORI SANTRI DARI ISE (My Al Wafi) — dibaca LANGSUNG saat wali membuka menu, TIDAK disimpan di tabel ERP
 * (keputusan user 13 Nov 2026). Hanya cache singkat (ISE_CACHE_MENIT) agar batas 120 permintaan/menit ISE tak habis.
 *
 *  • Kunci baca hanya dari .env server; dikirim lewat header X-Api-Key, tak pernah ke browser.
 *  • ISE memeriksa NIS saja — penjaga "wali hanya melihat anaknya sendiri" adalah PEMANGGIL (controller portal):
 *    santri yang diberikan ke sini WAJIB sudah dipastikan milik wali yang masuk.
 *  • Dipaksa lewat IPv4: daftar izin IP di ISE hanya berisi IPv4 VPS; jalur IPv6 ditolak 403 (diukur 14 Nov 2026).
 *  • Kirim id_erp DAN nis: sebelum pemasangan data awal, id_erp belum dikenal ISE dan ISE jatuh ke NIS.
 */
final class HistoriIse
{
    /** Saklar menu akademik untuk keluarga ini: ISE_HISTORI + kunci terpasang. */
    public static function aktifUntuk(?Wali $wali): bool
    {
        $mode = (string) config('services.ise.histori');
        if (! filled(config('services.ise.kunci_baca')) || ! in_array($mode, ['uji', 'semua'], true)) {
            return false;
        }

        return $mode === 'semua' || (bool) $wali?->akun_uji;
    }

    /**
     * Satu halaman histori satu area. `dikenal` = false bila santri belum ada di ISE (404) — bukan galat.
     *
     * @return array{baris: list<array<string,mixed>>, halaman:int, halaman_akhir:int, total:int, dikenal:bool}
     *
     * @throws HistoriIseGagal bila ISE mati, lambat, menolak kunci, atau jawabannya tak terbaca
     */
    public function daftar(Santri $santri, string $area, int $halaman = 1, int $perHalaman = 20, ?string $dari = null, ?string $sampai = null): array
    {
        $halaman = max(1, $halaman);
        $kunci = "ise:histori:{$area}:{$santri->id}:{$halaman}:{$perHalaman}:{$dari}:{$sampai}";

        // remember tak menyimpan pengecualian — kegagalan ISE tak ikut ter-cache.
        return Cache::remember($kunci, max(0, (int) config('services.ise.cache_menit')) * 60,
            fn () => $this->ambil($santri, $area, $halaman, $perHalaman, $dari, $sampai));
    }

    /** $dari/$sampai = YYYY-MM-DD (date_from/date_to ISE, inklusif). */
    private function ambil(Santri $santri, string $area, int $halaman, int $perHalaman, ?string $dari, ?string $sampai): array
    {
        $url = rtrim((string) config('services.ise.url'), '/').'/histori/'.rawurlencode($area);
        try {
            $jawab = Http::withHeaders(['X-Api-Key' => (string) config('services.ise.kunci_baca'), 'Accept' => 'application/json'])
                ->timeout(max(1, (int) config('services.ise.batas_detik')))->connectTimeout(3)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get($url, array_filter(['id_erp' => $santri->id, 'nis' => $santri->nis, 'page' => $halaman, 'per_page' => $perHalaman,
                    'date_from' => $dari, 'date_to' => $sampai],
                    fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            throw new HistoriIseGagal('ISE tak terjangkau: '.$e->getMessage(), previous: $e);
        }

        if ($jawab->status() === 404) {
            return ['baris' => [], 'halaman' => 1, 'halaman_akhir' => 1, 'total' => 0, 'dikenal' => false];
        }
        $isi = $jawab->json();
        if (! $jawab->successful() || ! is_array($isi) || ($isi['success'] ?? false) !== true || ! is_array($isi['data'] ?? null)) {
            throw new HistoriIseGagal("ISE menjawab {$jawab->status()} untuk area {$area}.");
        }
        $data = $isi['data'];

        return [
            'baris' => array_values(array_filter((array) ($data['data'] ?? []), 'is_array')),
            'halaman' => (int) ($data['current_page'] ?? $halaman),
            'halaman_akhir' => max(1, (int) ($data['last_page'] ?? 1)),
            'total' => (int) ($data['total'] ?? 0),
            'dikenal' => true,
        ];
    }
}
