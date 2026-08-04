<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak penyuntingan rekening tujuan oleh keuangan saat verifikasi. Hanya
 * ditulis, tak pernah disunting — nilainya justru berguna karena tak bisa
 * dirapikan belakangan.
 */
class PengajuanRekeningRiwayat extends Model
{
    protected $table = 'pengajuan_rekening_riwayat';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_pengajuan',
        'bank_lama', 'no_rekening_lama', 'atas_nama_lama',
        'bank_baru', 'no_rekening_baru', 'atas_nama_baru',
        'alasan', 'id_pengguna',
    ];

    public function pengubah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
