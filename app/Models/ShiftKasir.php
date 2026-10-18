<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift kasir — pertanggungjawaban laci uang satu kasir di satu toko.
 * buka (modal awal) → tutup (hitung uang + jurnal ringkas) → diserahkan (setoran dikonfirmasi).
 * Lihat [[App\Services\Modules\KasirService]].
 */
class ShiftKasir extends Model
{
    use MencatatJejak;

    public const STATUS = ['buka' => 'Buka', 'tutup' => 'Ditutup — menunggu serah terima', 'diserahkan' => 'Sudah diserahkan'];

    /** Pecahan rupiah yang dihitung saat tutup shift. */
    public const PECAHAN = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    protected $jejakModul = 'pos';

    protected $jejakAbaikan = ['jumlah_transaksi', 'total_tunai', 'total_hpp'];

    protected $table = 'shift_kasir';

    protected $primaryKey = 'id_shift';

    protected $fillable = [
        'nomor', 'kode_lokasi', 'id_kasir', 'dibuka_pada', 'modal_awal', 'ditutup_pada', 'jumlah_transaksi',
        'total_tunai', 'total_hpp', 'uang_dihitung', 'rincian_pecahan', 'selisih', 'catatan_tutup', 'status',
        'journal_entry_id', 'setoran', 'kode_coa_kas_tujuan', 'diterima_oleh', 'diterima_pada', 'journal_setoran_id',
    ];

    protected function casts(): array
    {
        return [
            'dibuka_pada' => 'datetime', 'ditutup_pada' => 'datetime', 'diterima_pada' => 'datetime',
            'modal_awal' => 'decimal:2', 'total_tunai' => 'decimal:2', 'total_hpp' => 'decimal:2',
            'uang_dihitung' => 'decimal:2', 'selisih' => 'decimal:2', 'setoran' => 'decimal:2',
            'rincian_pecahan' => 'array',
        ];
    }

    public function penjualan(): HasMany
    {
        return $this->hasMany(Penjualan::class, 'id_shift', 'id_shift');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPersediaan::class, 'kode_lokasi', 'kode');
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_kasir', 'id_pengguna');
    }

    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh', 'id_pengguna');
    }
}
