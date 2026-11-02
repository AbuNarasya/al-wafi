<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris pakaian di kantong laundry (jenis × jumlah), dicocokkan saat diserahkan. */
class LaundryItem extends Model
{
    protected $table = 'laundry_item';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function kurang(): int
    {
        return $this->jumlah_kembali === null ? 0 : max(0, $this->jumlah - $this->jumlah_kembali);
    }
}
