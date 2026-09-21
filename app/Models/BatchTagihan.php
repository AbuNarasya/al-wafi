<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sekumpulan tagihan yang sudah disusun & diotorisasi, tetapi belum terbit.
 *
 * Yang membedakannya dari penerbitan biasa: di antara "diperiksa" dan
 * "terbit" ada jeda waktu, dan selama jeda itu tak ada tagihan maupun jurnal
 * yang lahir. Karena itu batch berstatus `diotorisasi` TIDAK BOLEH dihitung
 * sebagai piutang di laporan mana pun — ia belum ada.
 */
class BatchTagihan extends Model
{
    protected $table = 'batch_tagihan';

    protected $guarded = ['id'];

    /** Modul asal → label yang dipakai di layar. */
    public const MODUL = [
        'spp' => 'SPP',
        'daftar_ulang' => 'Daftar Ulang',
        'tagihan_lain' => 'Tagihan Lain-lain',
    ];

    /** Status yang isinya masih bisa diubah petugas. */
    public const BISA_DIUBAH = ['draft'];

    /** Status yang sudah selesai — tak bisa dirilis maupun dibatalkan lagi. */
    public const SELESAI = ['dirilis', 'sebagian', 'gagal', 'dibatalkan'];

    protected function casts(): array
    {
        return [
            'parameter' => 'array',
            'snapshot' => 'array',
            'rilis_pada' => 'datetime',
            'diotorisasi_pada' => 'datetime',
            'dirilis_pada' => 'datetime',
        ];
    }

    public function baris(): HasMany
    {
        return $this->hasMany(BatchTagihanBaris::class, 'id_batch', 'id');
    }

    /** Baris yang memang akan jadi tagihan. Yang lain hanya keterangan. */
    public function barisTerbit(): HasMany
    {
        return $this->baris()->where('keputusan', BatchTagihanBaris::TERBIT);
    }

    public function penyusun(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disusun_oleh', 'id_pengguna');
    }

    public function pengotorisasi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diotorisasi_oleh', 'id_pengguna');
    }

    public function perilis(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dirilis_oleh', 'id_pengguna');
    }

    /** Batch yang menunggu penjadwal: sudah diotorisasi & waktunya sudah lewat. */
    public function scopeJatuhTempo($query, $sampai = null)
    {
        return $query->where('status', 'diotorisasi')
            ->whereNotNull('rilis_pada')
            ->where('rilis_pada', '<=', $sampai ?? now());
    }

    public function labelModul(): string
    {
        return self::MODUL[$this->modul] ?? $this->modul;
    }
}
