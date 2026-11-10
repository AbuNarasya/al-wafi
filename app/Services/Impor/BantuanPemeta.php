<?php

namespace App\Services\Impor;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Penolong yang dipakai hampir semua pemeta: membaca angka & tanggal dari isian
 * Excel yang bentuknya bermacam-macam, dan menyusun jawaban periksa().
 *
 * Dipisah karena aslinya tersalin di tiap pemeta — begitu jenis impornya
 * bertambah, salinan itu mulai berbeda-beda diam-diam.
 */
trait BantuanPemeta
{
    /** @return array{status:string,alasan:string} */
    protected function masalah(string $alasan): array
    {
        return ['status' => 'masalah', 'alasan' => $alasan];
    }

    /** @return array{status:string,alasan:null} */
    protected function siap(): array
    {
        return ['status' => 'siap', 'alasan' => null];
    }

    /** @return array{status:string,alasan:null} */
    protected function lewati(): array
    {
        return ['status' => 'lewati', 'alasan' => null];
    }

    /**
     * Angka bersih dari isian Excel: "1.500.000", "1500000,50", " 2 000 ".
     * Kosong dianggap 0. Mengembalikan null bila tidak sah.
     */
    protected function angka(?string $v, int $skala = 2): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '0';
        }
        // Angka gaya Inggris (titik desimal) DITOLAK, bukan ditebak (audit 10 Nov 2026): "2916.67" dulu terbaca
        // 291.667 dan "1.5" terbaca 15. Pemisah ribuan selalu diikuti tepat 3 angka, jadi titik yang diikuti 1–2
        // angka di akhir — tanpa koma — pasti bukan pemisah ribuan.
        if (! str_contains($v, ',') && preg_match('/\.\d{1,2}$/', $v)) {
            return null;
        }
        // Titik dibuang sebagai pemisah ribuan, koma dijadikan pemisah desimal —
        // itu cara Excel berbahasa Indonesia menulis angka.
        $bersih = str_replace(',', '.', str_replace([' ', '.'], '', $v));

        return is_numeric($bersih) && (float) $bersih >= 0 ? Money::of($bersih, $skala) : null;
    }

    /** Angka yang WAJIB lebih dari nol; null bila tak sah atau ≤ 0. */
    protected function angkaPositif(?string $v, int $skala = 2): ?string
    {
        $n = $this->angka($v, $skala);

        return $n !== null && Money::gtZero($n) ? $n : null;
    }

    protected function tanggalSah(?string $v): bool
    {
        if (trim((string) $v) === '') {
            return false;
        }
        try {
            Carbon::parse($v);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function tanggal(?string $v): ?string
    {
        return $this->tanggalSah($v) ? Carbon::parse(trim((string) $v))->toDateString() : null;
    }

    protected function kosongJadiNull(?string $v): ?string
    {
        return trim((string) $v) === '' ? null : trim((string) $v);
    }
}
