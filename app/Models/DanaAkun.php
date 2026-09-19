<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu akun beban yang boleh dibebani sebuah dana. Kosong = tanpa pembatasan akun. */
class DanaAkun extends Model
{
    protected $table = 'dana_akun';

    protected $fillable = ['kode_dana', 'kode_coa'];

    public function dana(): BelongsTo
    {
        return $this->belongsTo(Dana::class, 'kode_dana', 'kode_dana');
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(CoaDetail::class, 'kode_coa', 'kode_coa');
    }
}
