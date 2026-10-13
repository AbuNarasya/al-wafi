<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\Wali;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaksi yang pencatatnya STAF (`dicatat_oleh`) ATAU WALI lewat portal
 * (`dicatat_oleh_wali` + `dicatat_oleh_peran`) — tepat satu, dijaga CHECK di
 * basis data (migrasi portal_wali).
 *
 * Tampilan & pemberitahuan memakai namaPencatat() / pencatatWali(), bukan
 * `pencatat->nama` langsung: transaksi dari portal tak punya pengguna staf.
 */
trait DicatatStafAtauWali
{
    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }

    public function waliPencatat(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'dicatat_oleh_wali', 'id');
    }

    public function dicatatWali(): bool
    {
        return $this->dicatat_oleh_wali !== null;
    }

    /** "Ibu Aminah (Ibu) · via portal" atau nama staf; "—" bila keduanya tak ada. */
    public function namaPencatat(): string
    {
        if ($this->dicatatWali()) {
            $wali = $this->waliPencatat;
            $peran = (string) $this->dicatat_oleh_peran;
            $nama = $wali ? ($wali->{"nama_{$peran}"} ?: $wali->nama) : 'Wali';

            return $nama.' ('.(Wali::PERAN[$peran] ?? $peran).') · via portal';
        }

        return $this->pencatat?->nama ?? '—';
    }
}
