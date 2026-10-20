<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log potong/kembalikan uang saku dari kasir Wafi Mart — lihat [[App\Services\Modules\LayananDompetToko]]. */
class TransaksiDompetToko extends Model
{
    public const SUMBER = 'DompetToko';

    protected $table = 'transaksi_dompet_toko';

    protected $fillable = [
        'nomor_toko', 'jenis', 'nomor_asal', 'id_santri', 'uid_kartu', 'nominal', 'kode_toko', 'nama_toko', 'keterangan',
        'id_mutasi_dompet', 'status', 'tanggal', 'journal_entry_id', 'dijurnal_pada', 'rincian',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'tanggal' => 'date', 'dijurnal_pada' => 'datetime', 'rincian' => 'array'];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function mutasi(): BelongsTo
    {
        return $this->belongsTo(MutasiDompet::class, 'id_mutasi_dompet');
    }
}
