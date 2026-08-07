<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seorang santri yang mengikuti sebuah kegiatan berbayar.
 *
 * `nominal` NULL = ikut tarif jenjangnya, sehingga koreksi di matriks tarif
 * langsung berlaku bagi seluruh peserta biasa. Terisi = keringanan yang
 * disengaja, dan barisnya ditandai di layar supaya penyimpangan itu terlihat
 * tanpa harus dibandingkan satu per satu.
 *
 * Peserta yang BERHENTI tidak dihapus: kepesertaannya tetap terbaca bila ia
 * kembali ikut, dan tagihan yang sudah terbit atas namanya tetap punya sebab.
 */
class PesertaTagihanLain extends Model
{
    protected $table = 'peserta_tagihan_lain';

    protected $guarded = [];

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function ikut(): bool
    {
        return $this->status === 'ikut';
    }
}
