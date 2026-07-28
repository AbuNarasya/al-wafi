<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Struktur organisasi (Yayasan → Bidang → Bagian), hierarki via kode_induk.
 * Dimensi anggaran (dipakai per baris jurnal & pemilik budget).
 */
class Bagian extends Model
{
    protected $table = 'bagian';

    protected $primaryKey = 'kode_bagian';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_bagian',
        'nama_bagian',
        'kode_induk',
        'level',
        'status',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    public function induk(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_induk', 'kode_bagian');
    }

    public function anak(): HasMany
    {
        return $this->hasMany(Bagian::class, 'kode_induk', 'kode_bagian');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'kode_bagian', 'kode_bagian');
    }
}
