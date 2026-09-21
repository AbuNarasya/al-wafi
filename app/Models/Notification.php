<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Notifikasi dalam aplikasi (kustom, bukan notifikasi bawaan Laravel). Hanya created_at. */
class Notification extends Model
{
    protected $table = 'notifications';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_pengguna', 'judul', 'pesan', 'jenis', 'ref_jenis', 'ref_id', 'dibaca',
        // NULL = belum pernah didorong ke perangkat. Penanda inilah yang disapu
        // `push:kirim`; lihat PushService.
        'didorong_pada',
    ];

    protected function casts(): array
    {
        return ['dibaca' => 'boolean', 'didorong_pada' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }
}
