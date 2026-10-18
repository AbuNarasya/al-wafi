<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu barang dalam transfer. `kuantiti` & `kuantiti_diterima` dalam satuan DASAR. */
class TransferPersediaanDetail extends Model
{
    protected $table = 'transfer_persediaan_detail';

    public $timestamps = false;

    protected $fillable = [
        'id_transfer', 'kode_persediaan', 'satuan', 'isi', 'kuantiti_input', 'kuantiti', 'nilai',
        'kuantiti_diterima', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'isi' => 'decimal:4', 'kuantiti_input' => 'decimal:4', 'kuantiti' => 'decimal:4',
            'nilai' => 'decimal:2', 'kuantiti_diterima' => 'decimal:4',
        ];
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }

    /** Dikirim dikurangi diterima (satuan dasar); nol bila belum diterima. */
    public function selisih(): string
    {
        return $this->kuantiti_diterima === null ? '0' : Money::sub($this->kuantiti, $this->kuantiti_diterima, 4);
    }
}
