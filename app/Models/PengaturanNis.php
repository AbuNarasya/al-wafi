<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan format NIS — singleton (pola yang sama dengan ReminderSetting). */
class PengaturanNis extends Model
{
    protected $table = 'pengaturan_nis';

    protected $guarded = ['id'];
}
