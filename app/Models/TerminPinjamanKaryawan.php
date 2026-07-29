<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu termin jadwal cicilan. Kesepakatan jadwal — tidak berjurnal. */
class TerminPinjamanKaryawan extends Model
{
    protected $table = 'termin_pinjaman_karyawan';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['jatuh_tempo' => 'date', 'nominal' => 'decimal:2', 'urutan' => 'integer'];
    }

    public function pinjaman(): BelongsTo
    {
        return $this->belongsTo(PinjamanKaryawan::class, 'id_pinjaman', 'id');
    }
}
