<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan filter termin jatuh tempo (Angsuran Uang Pangkal) — singleton id=1. */
class TerminFilterSetting extends Model
{
    protected $table = 'termin_filter_settings';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_hari' => 'integer'];
    }
}
