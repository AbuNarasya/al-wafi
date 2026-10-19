<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pencairan belanja kartu ke kas toko — lihat [[App\Services\Modules\PencairanKartuService]]. */
class PencairanKartu extends Model
{
    use MencatatJejak;

    public const SUMBER = 'PencairanKartu';

    protected $jejakModul = 'pencairan-kartu';

    protected $table = 'pencairan_kartu';

    protected $primaryKey = 'id_pencairan';

    protected $fillable = [
        'nomor', 'kode_lokasi', 'sampai_tanggal', 'total_belanja', 'total_retur', 'total', 'kode_coa_sumber',
        'kode_coa_tujuan', 'status', 'alasan_batal', 'journal_entry_id', 'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return ['sampai_tanggal' => 'date', 'total_belanja' => 'decimal:2', 'total_retur' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPersediaan::class, 'kode_lokasi', 'kode');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh', 'id_pengguna');
    }
}
