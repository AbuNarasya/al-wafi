<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wafi Mart menerima pencairan belanja kartu dari ERP Al Wafi — lihat [[App\Services\Modules\PenerimaanAlWafiService]]. */
class PenerimaanAlWafi extends Model
{
    use MencatatJejak;

    public const SUMBER = 'PenerimaanAlWafi';

    protected $jejakModul = 'penerimaan-al-wafi';

    protected $table = 'penerimaan_al_wafi';

    protected $fillable = [
        'nomor_pencairan', 'sampai_tanggal', 'tanggal', 'total', 'kode_coa_bank', 'journal_entry_id', 'diterima_oleh',
    ];

    protected function casts(): array
    {
        return ['sampai_tanggal' => 'date', 'tanggal' => 'date', 'total' => 'decimal:2'];
    }

    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh', 'id_pengguna');
    }
}
