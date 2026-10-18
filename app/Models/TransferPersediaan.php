<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer stok antar lokasi, dua langkah — lihat [[App\Services\Modules\TransferPersediaanService]].
 */
class TransferPersediaan extends Model
{
    use MencatatJejak;

    public const SUMBER = 'TransferPersediaan';

    public const STATUS = [
        'dikirim' => 'Dalam perjalanan',
        'selisih' => 'Selisih — menunggu putusan',
        'selesai' => 'Selesai',
        'batal' => 'Batal',
    ];

    protected $jejakModul = 'transfer-persediaan';

    protected $table = 'transfer_persediaan';

    protected $primaryKey = 'id_transfer';

    protected $fillable = [
        'nomor', 'tanggal_kirim', 'tanggal_terima', 'kode_lokasi_asal', 'kode_lokasi_tujuan', 'status',
        'keterangan', 'catatan_terima', 'putusan', 'catatan_putusan',
        'dikirim_oleh', 'diterima_oleh', 'diputus_oleh', 'diputus_pada',
    ];

    protected function casts(): array
    {
        return ['tanggal_kirim' => 'date', 'tanggal_terima' => 'date', 'diputus_pada' => 'datetime'];
    }

    public function details(): HasMany
    {
        return $this->hasMany(TransferPersediaanDetail::class, 'id_transfer', 'id_transfer')->orderBy('id');
    }

    public function asal(): BelongsTo
    {
        return $this->belongsTo(LokasiPersediaan::class, 'kode_lokasi_asal', 'kode');
    }

    public function tujuan(): BelongsTo
    {
        return $this->belongsTo(LokasiPersediaan::class, 'kode_lokasi_tujuan', 'kode');
    }

    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikirim_oleh', 'id_pengguna');
    }

    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh', 'id_pengguna');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh', 'id_pengguna');
    }
}
