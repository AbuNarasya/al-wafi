<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak audit rantai approval. Waktu di kolom `waktu` (tanpa timestamps standar). */
class ApprovalLog extends Model
{
    protected $table = 'approval_logs';

    public $timestamps = false;

    protected $fillable = [
        'id_instance', 'urutan', 'id_pengguna', 'nama_pengguna', 'aksi', 'catatan', 'waktu',
    ];

    protected function casts(): array
    {
        return ['waktu' => 'datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ApprovalInstance::class, 'id_instance', 'id');
    }
}
