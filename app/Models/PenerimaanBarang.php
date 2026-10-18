<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penerimaan Barang (tanda terima gudang). Stok bertambah di `kode_lokasi` saat dokumen
 * ini disimpan, berjurnal D Persediaan / K Barang Diterima Belum Ditagih. Hutangnya
 * baru lahir saat invoice vendor merujuk baris-barisnya — lihat
 * [[App\Services\Modules\PenerimaanBarangService]].
 */
class PenerimaanBarang extends Model
{
    use MencatatJejak;

    public const SUMBER = 'PenerimaanBarang';

    protected $jejakModul = 'penerimaan-barang';

    protected $table = 'penerimaan_barang';

    protected $primaryKey = 'id_penerimaan';

    protected $fillable = [
        'nomor', 'tanggal', 'id_po', 'kode_vendor', 'kode_lokasi', 'kode_unit', 'nomor_surat_jalan',
        'keterangan', 'total', 'status', 'alasan_batal', 'journal_entry_id', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'total' => 'decimal:2'];
    }

    public function details(): HasMany
    {
        return $this->hasMany(PenerimaanBarangDetail::class, 'id_penerimaan', 'id_penerimaan')->orderBy('id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'kode_vendor', 'kode_vendor');
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'id_po', 'id_po');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPersediaan::class, 'kode_lokasi', 'kode');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    /** Sudah ada baris yang ditagih → dokumen terkunci (tak bisa dibatalkan). */
    public function sudahDitagih(): bool
    {
        return $this->details->contains(fn ($d) => (float) $d->qty_ditagih > 0);
    }
}
