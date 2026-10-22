<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUDIT 22 Okt 2026 — penjaga terakhir: saldo dompet/tabungan/prabayar tak boleh minus.
 * NOT VALID: baris lama tak diperiksa (migrasi tak gagal bila ada sisa data rusak),
 * tetapi setiap INSERT/UPDATE sesudahnya wajib memenuhinya.
 */
return new class extends Migration
{
    private const TABEL = ['dompet_wali', 'dompet_santri', 'tabungan_santri', 'prabayar_spp'];

    public function up(): void
    {
        foreach (self::TABEL as $t) {
            DB::statement("ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$t}_saldo_tak_minus");
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_saldo_tak_minus CHECK (saldo >= 0) NOT VALID");
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $t) {
            DB::statement("ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$t}_saldo_tak_minus");
        }
    }
};
