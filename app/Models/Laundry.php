<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu kantong cucian santri — lihat App\Services\Modules\LaundryService. */
class Laundry extends Model
{
    protected $table = 'laundry';

    protected $guarded = ['id'];

    /** Urutan langkah; `dibatalkan` di luar urutan (hanya dari `diterima`). */
    public const LANGKAH = ['diterima' => 'Diterima', 'diproses' => 'Diproses', 'selesai' => 'Selesai', 'diserahkan' => 'Diserahkan'];

    public const LABEL = self::LANGKAH + ['dibatalkan' => 'Dibatalkan'];

    /**
     * Label langkah di PORTAL WALI — kalimat untuk orang tua, dan sengaja beda kunci terjemahan dari "Diterima"
     * (yang sudah berarti "diterima sebagai santri" / Accepted).
     */
    public const LANGKAH_PORTAL = ['diterima' => 'Cucian diterima', 'diproses' => 'Sedang dicuci', 'selesai' => 'Siap diambil', 'diserahkan' => 'Sudah diambil'];

    /** Langkah berikutnya yang boleh dari papan proses. Diserahkan HANYA lewat penyerahan (tap kartu + cocokkan). */
    public const MAJU = ['diterima' => 'diproses', 'diproses' => 'selesai'];

    protected function casts(): array
    {
        return [
            'berat' => 'decimal:2', 'ada_kurang' => 'boolean',
            'diterima_pada' => 'datetime', 'diproses_pada' => 'datetime', 'selesai_pada' => 'datetime',
            'diserahkan_pada' => 'datetime', 'dibatalkan_pada' => 'datetime',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function item(): HasMany
    {
        return $this->hasMany(LaundryItem::class, 'id_laundry')->orderBy('id');
    }

    public function petugas(string $langkah): BelongsTo
    {
        return $this->belongsTo(User::class, "{$langkah}_oleh", 'id_pengguna');
    }

    public function labelStatus(): string
    {
        return self::LABEL[$this->status] ?? $this->status;
    }

    public function jumlahPotong(): int
    {
        return (int) $this->item->sum('jumlah');
    }
}
