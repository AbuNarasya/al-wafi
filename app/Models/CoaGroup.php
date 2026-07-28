<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok akun (Chart of Accounts), hierarki via kode_induk.
 * level: 1=kelompok utama, 2=grup parent, 3=grup akun.
 */
class CoaGroup extends Model
{
    protected $table = 'coa_groups';

    protected $primaryKey = 'kode_grup';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_grup',
        'nama_grup',
        'kode_induk',
        'level',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    public function induk(): BelongsTo
    {
        return $this->belongsTo(CoaGroup::class, 'kode_induk', 'kode_grup');
    }

    public function anak(): HasMany
    {
        return $this->hasMany(CoaGroup::class, 'kode_induk', 'kode_grup');
    }

    public function details(): HasMany
    {
        return $this->hasMany(CoaDetail::class, 'kode_grup', 'kode_grup');
    }
}
