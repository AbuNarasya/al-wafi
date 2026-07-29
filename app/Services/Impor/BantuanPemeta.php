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
    protected function angka(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '0';
        }
        // Titik dibuang sebagai pemisah ribuan, koma dijadikan pemisah desimal —
        // itu cara Excel berbahasa Indonesia menulis angka.
        $bersih = str_replace(',', '.', str_replace([' ', '.'], '', $v));

        return is_numeric($bersih) && (float) $bersih >= 0 ? Money::of($bersih) : null;
    }

    /** Angka yang WAJIB lebih dari nol; null bila tak sah atau ≤ 0. */
    protected function angkaPositif(?string $v): ?string
    {
        $n = $this->angka($v);

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
