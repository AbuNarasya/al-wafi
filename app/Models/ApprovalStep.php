<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu tahap rantai. TEPAT SATU dari peringkat/fungsi terisi. Tanpa timestamps. */
class ApprovalStep extends Model
{
    protected $table = 'approval_steps';

    public $timestamps = false;

    protected $fillable = [
        'kode_flow', 'urutan', 'nama_tahap', 'peringkat', 'fungsi',
        'scope', 'nominal_min', 'syarat',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'peringkat' => 'integer',
            'nominal_min' => 'decimal:2',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'kode_flow', 'kode_flow');
    }

    public function levelPengajuan(): BelongsTo
    {
        return $this->belongsTo(LevelPengajuan::class, 'peringkat', 'peringkat');
    }
}
