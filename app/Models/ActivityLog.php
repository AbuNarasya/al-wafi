<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JEJAK AUDIT. Hanya created_at — baris jejak tak pernah diubah, dan tak pernah
 * dihapus dari dalam aplikasi (tak ada rute destroy, tak ada tombol).
 *
 * Ditulis lewat [[App\Support\Audit\Jejak]]: lapis otomatis dari trait
 * [[App\Support\Audit\MencatatJejak]] pada model keuangan, dan lapis niat untuk
 * tindakan yang maknanya tak terbaca dari perubahan kolom.
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_pengguna', 'aksi', 'modul', 'ref_jenis', 'ref_id',
        'detail', 'perubahan', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['perubahan' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
