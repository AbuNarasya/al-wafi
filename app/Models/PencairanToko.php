<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ERP Al Wafi membayar belanja kartu santri ke Wafi Mart — lihat [[App\Services\Modules\PencairanTokoService]]. */
class PencairanToko extends Model
{
    use MencatatJejak;

    public const SUMBER = 'PencairanToko';

    protected $jejakModul = 'pencairan-toko';

    protected $table = 'pencairan_toko';

    protected $fillable = [
        'nomor', 'sampai_tanggal', 'jumlah_transaksi', 'total', 'kode_coa_bank', 'status', 'alasan_batal',
        'journal_entry_id', 'dibuat_oleh', 'diterima_toko_pada',
    ];

    protected function casts(): array
    {
        return ['sampai_tanggal' => 'date', 'total' => 'decimal:2', 'diterima_toko_pada' => 'datetime'];
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh', 'id_pengguna');
    }
}
