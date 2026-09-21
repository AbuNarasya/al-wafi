<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perangkat yang bersedia menerima push notification.
 *
 * `gagal_beruntun` dinolkan tiap kali pengiriman berhasil. Yang dihitung
 * memang harus BERUNTUN: satu kegagalan bisa berarti ponselnya sedang mati,
 * bukan langganannya batal.
 */
class PushLangganan extends Model
{
    protected $table = 'push_langganan';

    protected $guarded = ['id'];

    /** Sesudah sebanyak ini gagal berturut-turut, langganannya dianggap mati. */
    public const BATAS_GAGAL = 5;

    protected function casts(): array
    {
        return ['terakhir_berhasil' => 'datetime'];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
