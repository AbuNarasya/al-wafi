<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mitra kantin penyewa lahan Wafi Mart — lihat [[App\Services\Modules\MitraKantinService]]. */
class Mitra extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'mitra-kantin';

    protected $table = 'mitra';

    protected $fillable = [
        'kode', 'nama', 'pemilik', 'telepon', 'bank_nama', 'bank_rekening', 'bank_atas_nama',
        'sewa_mulai', 'sewa_sampai', 'keterangan_sewa', 'status', 'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return ['sewa_mulai' => 'date', 'sewa_sampai' => 'date'];
    }

    public function menu(): HasMany
    {
        return $this->hasMany(MenuMitra::class, 'id_mitra')->orderBy('nama');
    }

    public function akun(): HasMany
    {
        return $this->hasMany(User::class, 'id_mitra', 'id');
    }

    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }
}
