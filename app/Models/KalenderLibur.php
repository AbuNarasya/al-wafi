<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu rentang hari libur (lihat migrasi 000123). kode_jenjang NULL = semua jenjang. */
class KalenderLibur extends Model
{
    protected $table = 'kalender_libur';

    protected $guarded = ['id'];

    public const CAKUPAN = ['semua' => 'Sekolah & asrama', 'sekolah' => 'Sekolah saja', 'asrama' => 'Asrama saja'];

    protected function casts(): array
    {
        return ['mulai' => 'date', 'selesai' => 'date'];
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    /** Libur yang beririsan dengan [dari, sampai] untuk jenjang & cakupan ini (cakupan 'semua' selalu ikut). */
    public function scopeBerlaku(Builder $q, ?string $kodeJenjang, string $cakupan, string $dari, string $sampai): Builder
    {
        return $q->where('mulai', '<=', $sampai)->where('selesai', '>=', $dari)
            ->where(fn ($w) => $w->whereNull('kode_jenjang')->when($kodeJenjang, fn ($x) => $x->orWhere('kode_jenjang', $kodeJenjang)))
            ->whereIn('cakupan', ['semua', $cakupan]);
    }
}
