<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HEADER jurnal double-entry. Hanya punya created_at (tanpa updated_at).
 * Void = status 'void' + entry pembalik (reversal_of → entry asal).
 */
class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    // Tabel hanya punya created_at; matikan updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'referensi',
        'tanggal',
        'keterangan',
        'sumber_modul',
        'id_sumber',
        'status',
        'reversal_of',
        'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of', 'id');
    }

    public function reversedBy(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reversal_of', 'id');
    }
}
