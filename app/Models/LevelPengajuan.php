<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peringkat rantai persetujuan Pengajuan Pembayaran. PK = peringkat (Int
 * natural, 1 = tertinggi), bukan autoincrement.
 */
class LevelPengajuan extends Model
{
    protected $table = 'level_pengajuan';

    protected $primaryKey = 'peringkat';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'peringkat',
        'nama',
        'keterangan',
        'status',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'peringkat_pengajuan', 'peringkat');
    }
}
