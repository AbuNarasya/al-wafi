<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Surat pernyataan wali, berversi — lihat SuratPernyataanService. */
class TermTemplate extends Model
{
    protected $table = 'term_template';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['versi' => 'integer', 'berlaku_mulai' => 'date'];
    }

    public function persetujuan(): HasMany
    {
        return $this->hasMany(PersetujuanTerm::class, 'id_term_template');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh', 'id_pengguna');
    }
}
