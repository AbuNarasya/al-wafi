<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master tahun ajaran PPSB. kode ("2026/2027") dirujuk tabel lain sebagai string. */
class TahunAjaran extends Model
{
    protected $table = 'tahun_ajaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'default_pendaftaran' => 'boolean',
        ];
    }
}
