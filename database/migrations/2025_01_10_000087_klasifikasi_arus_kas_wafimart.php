<?php

use App\Support\Instans;
use Database\Seeders\WafiMartSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Instans Wafi Mart yang SUDAH berjalan: isi klasifikasi arus kas akun neraca ritelnya (lihat
 * WafiMartSeeder::klasifikasiArusKas, audit 21 Okt 2026). Instans lain tak disentuh — nomor akunnya milik sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Instans::adalah('wafimart')) {
            WafiMartSeeder::klasifikasiArusKas();
        }
    }

    public function down(): void {}
};
