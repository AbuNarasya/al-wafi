<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\AnggaranKunci;

/**
 * KUNCI ANGGARAN per Tahun Anggaran (port budget-lock.service.ts).
 *
 * TA terkunci = anggarannya BEKU: tak bisa diubah SIAPA PUN — simpan-langsung
 * admin ditolak, pengajuan baru ditolak. Hanya admin yang mengunci/membuka.
 * Baris `anggaran_kunci` ADA = terkunci; membuka = menghapusnya. `tahun` =
 * LABEL Tahun Anggaran, sama dengan yang dipakai grid/pengajuan.
 */
class BudgetLockService
{
    public function isTerkunci(int $tahun): bool
    {
        return AnggaranKunci::whereKey($tahun)->exists();
    }

    /** Lempar 409 bila TA terkunci — dipakai semua jalur tulis anggaran. */
    public function assertTidakTerkunci(int $tahun): void
    {
        if ($this->isTerkunci($tahun)) {
            throw new AppException(
                409,
                "Anggaran Tahun Anggaran {$tahun} terkunci. Minta administrator membuka kuncinya sebelum mengubah anggaran.",
            );
        }
    }

    /** Daftar TA yang terkunci (dengan pengunci & waktu). */
    public function list()
    {
        return AnggaranKunci::orderByDesc('tahun')->get();
    }

    public function kunci(int $tahun, int $idPengguna, ?string $catatan = null): AnggaranKunci
    {
        // Idempoten: mengunci yang sudah terkunci hanya memperbarui jejak.
        return AnggaranKunci::updateOrCreate(
            ['tahun' => $tahun],
            ['locked_by' => $idPengguna, 'locked_at' => now(), 'catatan' => $catatan],
        );
    }

    public function buka(int $tahun): array
    {
        AnggaranKunci::whereKey($tahun)->delete(); // idempoten
        return ['ok' => true];
    }
}
