<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu kali koreksi nominal tagihan santri, beserta jurnal penyesuaiannya. */
class KoreksiTagihan extends Model
{
    protected $table = 'koreksi_tagihan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'nominal_lama' => 'decimal:2',
            'nominal_baru' => 'decimal:2',
            'terbayar' => 'decimal:2',
            'kelebihan_ke_dompet' => 'decimal:2',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan', 'id');
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id', 'id');
    }

    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh', 'id_pengguna');
    }
}
