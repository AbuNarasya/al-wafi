<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Sudah saya kerjakan" — satu baris per (jadwal, periode).
 *
 * Inilah yang memadamkan notifikasi tugasnya. Disimpan sebagai baris, bukan
 * sekadar menandai notifikasinya dibaca: notifikasi hanya milik satu orang,
 * sedangkan pengingat ini dikirim ke beberapa petugas sekaligus — begitu satu
 * orang mengerjakannya, yang lain tak perlu lagi ditagih.
 */
class KonfirmasiPengingatTerbit extends Model
{
    protected $table = 'konfirmasi_pengingat_terbit';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pada' => 'datetime'];
    }

    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(JadwalPengingatTerbit::class, 'id_jadwal', 'id');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh', 'id_pengguna');
    }
}
