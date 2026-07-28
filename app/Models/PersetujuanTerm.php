<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Surat pernyataan wali (snapshot teks + tanda tangan elektronik). */
class PersetujuanTerm extends Model
{
    protected $table = 'persetujuan_term';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'disetujui_pada' => 'datetime',
            'otp_terverifikasi_pada' => 'datetime',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali', 'id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TermTemplate::class, 'id_term_template', 'id');
    }
}
