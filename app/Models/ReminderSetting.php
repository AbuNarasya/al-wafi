<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan reminder tagihan jatuh tempo — baris tunggal (singleton id=1). */
class ReminderSetting extends Model
{
    protected $table = 'reminder_settings';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'sumber_tagihan_santri' => 'boolean',
            'sumber_invoice_vendor' => 'boolean',
            'sumber_angsuran_uang_pangkal' => 'boolean',
            'penerima_admin' => 'boolean',
            'penerima_tim_keuangan' => 'boolean',
            'penerima_akses_modul' => 'boolean',
        ];
    }
}
