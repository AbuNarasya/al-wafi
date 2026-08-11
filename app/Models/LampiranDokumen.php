<?php

namespace App\Models;

use App\Support\Unggahan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata lampiran dokumen keuangan. Hanya created_at. */
class LampiranDokumen extends Model
{
    protected $table = 'lampiran_dokumen';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ukuran' => 'integer'];
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh', 'id_pengguna');
    }

    /** "231 KB" — dipakai daftar lampiran. */
    public function ukuranTerbaca(): string
    {
        return Unggahan::ukuran((int) $this->ukuran);
    }

    /** Bisa dipratinjau di dalam aplikasi (PDF lewat PDF.js, gambar lewat <img>). */
    public function bisaDipratinjau(): bool
    {
        return $this->mime === 'application/pdf' || str_starts_with((string) $this->mime, 'image/');
    }
}
