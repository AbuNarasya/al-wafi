<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kode OTP portal wali — per EMAIL, karena pendaftar baru belum punya baris wali. */
class OtpWali extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'otp_wali';

    protected $guarded = ['id'];

    protected $hidden = ['kode_hash'];

    protected function casts(): array
    {
        return [
            'kedaluwarsa_pada' => 'datetime',
            'dipakai_pada' => 'datetime',
            'percobaan' => 'integer',
        ];
    }
}
