<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasil rekonsiliasi malam belanja kartu per tanggal — lihat [[App\Services\Modules\RekonsiliasiDompetToko]]. */
class RekonsiliasiDompetToko extends Model
{
    protected $table = 'rekonsiliasi_dompet_toko';

    protected $fillable = [
        'tanggal', 'status', 'jumlah_transaksi', 'bersih_al_wafi', 'jumlah_yatim', 'nilai_yatim', 'perlu_diputus',
        'rincian', 'pesan', 'dijalankan_pada', 'ditangani_oleh', 'ditangani_pada', 'catatan_tangani',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date', 'bersih_al_wafi' => 'decimal:2', 'nilai_yatim' => 'decimal:2', 'rincian' => 'array',
            'dijalankan_pada' => 'datetime', 'ditangani_pada' => 'datetime',
        ];
    }

    public function penangan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditangani_oleh', 'id_pengguna');
    }
}
