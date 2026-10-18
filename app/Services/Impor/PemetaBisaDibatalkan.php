<?php

namespace App\Services\Impor;

use App\Models\ImporBatch;

/**
 * Pemeta yang membawa ATURAN PEMBATALAN BATCH-nya sendiri.
 *
 * Kerangka ([[ImporSaldoAwal]]) semula hanya tahu cara membatalkan santri lama.
 * Jenis impor lain yang ingin bisa dibatalkan utuh cukup memasang antarmuka ini;
 * kerangka tetap yang mengurus catatan batch, alasan, dan transaksinya.
 */
interface PemetaBisaDibatalkan
{
    /**
     * Alasan batch ini TIDAK bisa dibatalkan lagi — SEMUA sekaligus, bukan yang
     * pertama saja. Kosong = boleh dibatalkan.
     *
     * @return list<string>
     */
    public function halanganBatal(ImporBatch $batch): array;

    /** Buang seluruh baris batch ini. Dipanggil DI DALAM transaksi oleh kerangka. */
    public function batalkan(ImporBatch $batch, ?int $idPengguna): void;
}
