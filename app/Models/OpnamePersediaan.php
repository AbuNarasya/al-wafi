<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen stok opname per lokasi — lihat [[App\Services\Modules\OpnamePersediaanService]].
 */
class OpnamePersediaan extends Model
{
    use MencatatJejak;

    public const SUMBER = 'OpnamePersediaan';

    public const STATUS = [
        'draft' => 'Draf — sedang dihitung',
        'diajukan' => 'Menunggu persetujuan',
        'disetujui' => 'Disetujui',
        'batal' => 'Batal',
    ];

    protected $jejakModul = 'opname-persediaan';

    protected $table = 'opname_persediaan';

    protected $primaryKey = 'id_opname';

    protected $fillable = [
        'nomor', 'tanggal', 'kode_lokasi', 'kode_kategori', 'kode_bagian', 'status', 'keterangan', 'catatan_putusan',
        'dibuat_oleh', 'diajukan_oleh', 'diajukan_pada', 'disetujui_oleh', 'disetujui_pada',
    ];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'diajukan_pada' => 'datetime', 'disetujui_pada' => 'datetime'];
    }

    public function details(): HasMany
    {
        return $this->hasMany(OpnamePersediaanDetail::class, 'id_opname', 'id_opname');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPersediaan::class, 'kode_lokasi', 'kode');
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriBarang::class, 'kode_kategori', 'kode');
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh', 'id_pengguna');
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh', 'id_pengguna');
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh', 'id_pengguna');
    }
}
