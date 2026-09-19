<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permohonan membuka kembali periode yang sudah ditutup.
 *
 * Dua tangan: admin keuangan MENGAJUKAN beserta alasannya (hak
 * `buka-periode.buat`), direktur keuangan MEMUTUSKAN (hak `buka-periode.ubah`).
 * Pemohon tak boleh memutuskan permohonannya sendiri — ditegakkan di
 * [[App\Services\Modules\BukaPeriodeService]].
 */
class PermohonanBukaPeriode extends Model
{
    protected $table = 'permohonan_buka_periode';

    protected $fillable = [
        'lingkup', 'tahun', 'bulan', 'alasan', 'status',
        'diajukan_oleh', 'diajukan_pada', 'diputus_oleh', 'diputus_pada', 'catatan_keputusan',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'diajukan_pada' => 'datetime',
            'diputus_pada' => 'datetime',
        ];
    }

    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh', 'id_pengguna');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh', 'id_pengguna');
    }

    public function labelPeriode(): string
    {
        return $this->lingkup === 'tahun'
            ? "Tahun {$this->tahun}"
            : str_pad((string) $this->bulan, 2, '0', STR_PAD_LEFT).'/'.$this->tahun;
    }
}
