<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barcode → barang (+ satuan). Disimpan sebagai TEKS apa adanya: nol di depan dan
 * panjang 13 digit harus utuh, bukan angka desimal seperti di POS lama.
 * `satuan` kosong = satuan dasar.
 */
class BarcodePersediaan extends Model
{
    protected $table = 'barcode_persediaan';

    protected $primaryKey = 'barcode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['barcode', 'kode_persediaan', 'satuan'];

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }
}
