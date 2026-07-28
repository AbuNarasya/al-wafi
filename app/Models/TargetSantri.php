<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Target jumlah santri per Tahun Ajaran per jenjang. */
class TargetSantri extends Model
{
    protected $table = 'target_santri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target' => 'integer'];
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }
}
