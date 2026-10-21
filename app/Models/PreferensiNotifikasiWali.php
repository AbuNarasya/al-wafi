<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kategori push yang DIMATIKAN satu orang di keluarga. Tak ada barisnya = kategori itu nyala. */
class PreferensiNotifikasiWali extends Model
{
    protected $table = 'preferensi_notifikasi_wali';

    protected $guarded = ['id'];
}
