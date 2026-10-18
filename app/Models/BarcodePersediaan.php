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

    /**
     * Rapikan barcode dari berkas Excel / POS lama. Mengembalikan:
     *  • string bersih — "8991234567890.00" → "8991234567890" (POS lama menyimpannya
     *    sebagai angka desimal), spasi dibuang;
     *  • null — kosong;
     *  • false — RUSAK tak terpulihkan: notasi ilmiah "8,99123E+12" (Excel memotong
     *    digitnya; kolom harus diformat Teks sebelum disimpan).
     */
    public static function bersihkan(?string $v): string|false|null
    {
        $v = trim(str_replace(' ', '', (string) $v));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+([.,]\d+)?[eE]\+?\d+$/', $v)) {
            return false;
        }
        if (preg_match('/^(\d+)[.,]0+$/', $v, $m)) {
            return $m[1];
        }

        return mb_strlen($v) <= 64 ? $v : false;
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }
}
