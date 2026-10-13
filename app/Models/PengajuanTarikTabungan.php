<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengajuan wali menarik Tabungan Santri ke Dompet Wali — diputus keuangan. */
class PengajuanTarikTabungan extends Model
{
    protected $table = 'pengajuan_tarik_tabungan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'diputus_pada' => 'datetime'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh', 'id_pengguna');
    }

    public function namaPengaju(): string
    {
        $w = $this->wali;
        $nama = $w ? ($w->{"nama_{$this->peran}"} ?: $w->nama) : 'Wali';

        return $nama.' ('.(Wali::PERAN[$this->peran] ?? $this->peran).')';
    }
}
