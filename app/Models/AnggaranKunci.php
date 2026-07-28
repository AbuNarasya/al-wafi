<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kunci anggaran per Tahun Anggaran. Baris ada = TA terkunci. PK = tahun. */
class AnggaranKunci extends Model
{
    protected $table = 'anggaran_kunci';

    protected $primaryKey = 'tahun';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = ['tahun', 'locked_by', 'locked_at', 'catatan'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }
}
