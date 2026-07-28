<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Buku besar mutasi dompet. Perpindahan antar-dompet = 2 baris (id_pasangan). */
class MutasiDompet extends Model
{
    protected $table = 'mutasi_dompet';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'saldo_setelah' => 'decimal:2',
            'tanggal' => 'date',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function pencatat(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }
}
