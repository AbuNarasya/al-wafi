<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master karyawan ringkas. Nanti diambil alih HRD — lihat migration-nya.
 *
 * CATATAN: jangan menambah method yang namanya sama dengan nama kolom
 * (kode/nama/status/…): Eloquent akan menyangkanya relasi pada objek baru.
 */
class Karyawan extends Model
{
    protected $table = 'karyawan';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode', 'nama', 'jabatan', 'kode_bagian', 'id_pengguna', 'status', 'keterangan'];

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function pinjaman(): HasMany
    {
        return $this->hasMany(PinjamanKaryawan::class, 'kode_karyawan', 'kode');
    }
}
