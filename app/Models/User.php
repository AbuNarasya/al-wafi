<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Pengguna aplikasi. Autentikasi berbasis `username` + `password_hash`
 * (bukan email/password bawaan Laravel).
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id_pengguna';

    protected $fillable = [
        'username',
        'nama',
        'jabatan',
        'password_hash',
        'kode_level',
        'is_admin',
        'kode_bagian',
        'peringkat_pengajuan',
        'tim_keuangan',
        'status',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'tim_keuangan' => 'boolean',
        ];
    }

    /**
     * Auth Laravel memeriksa kolom ini sebagai kata sandi (bukan `password`).
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'kode_level', 'kode_level');
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }

    public function levelPengajuan(): BelongsTo
    {
        return $this->belongsTo(LevelPengajuan::class, 'peringkat_pengajuan', 'peringkat');
    }

    public function hakAkses(): HasMany
    {
        return $this->hasMany(HakAksesModul::class, 'id_pengguna', 'id_pengguna');
    }
}
