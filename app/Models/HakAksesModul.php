<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hak akses per pengguna per modul. DENY BY DEFAULT. PK komposit
 * (id_pengguna, kode_modul) — tanpa timestamps.
 */
class HakAksesModul extends Model
{
    protected $table = 'hak_akses_modul';

    // PK komposit: Eloquent tidak mendukung native; nonaktifkan increment.
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'id_pengguna',
        'kode_modul',
        'lihat',
        'buat',
        'ubah',
        'hapus',
        'menu',
    ];

    protected function casts(): array
    {
        return [
            'lihat' => 'boolean',
            'buat' => 'boolean',
            'ubah' => 'boolean',
            'hapus' => 'boolean',
            'menu' => 'boolean',
        ];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
