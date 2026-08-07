<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali jalannya impor data awal. Menyimpan jejaknya, dan menjadi pegangan
 * untuk membatalkan seluruh barisnya sekaligus.
 */
class ImporBatch extends Model
{
    protected $table = 'impor_batch';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ringkasan' => 'array',
            'dijalankan_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
        ];
    }

    /** Masih bisa dibatalkan? Batch yang sudah dibatalkan tak punya baris lagi. */
    public function aktif(): bool
    {
        return $this->dibatalkan_pada === null;
    }

    public function santri(): HasMany
    {
        return $this->hasMany(Santri::class, 'id_batch', 'id');
    }

    public function wali(): HasMany
    {
        return $this->hasMany(Wali::class, 'id_batch', 'id');
    }

    public function pelaksana(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dijalankan_oleh', 'id_pengguna');
    }

    public function pembatal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibatalkan_oleh', 'id_pengguna');
    }
}
