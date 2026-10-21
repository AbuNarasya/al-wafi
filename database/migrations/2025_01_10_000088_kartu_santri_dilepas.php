<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HAPUS PENDAFTARAN KARTU RFID (keputusan user 21 Okt 2026).
 *
 *  • Kartu tanpa riwayat transaksi → barisnya dihapus.
 *  • Kartu yang sudah punya riwayat → status `dilepas` (baris tetap ada untuk jejak), dan UID-nya
 *    BOLEH didaftarkan lagi ke santri mana pun. Riwayat lama tetap milik santri lama: setiap
 *    transaksi menyimpan id_santri-nya sendiri.
 *  • Karena itu UID tak lagi unik mutlak — unik hanya di antara kartu yang belum dilepas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kartu_santri', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->timestamp('dilepas_pada')->nullable();
            $table->unsignedInteger('dilepas_oleh')->nullable();
            $table->foreign('dilepas_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
        DB::statement('ALTER TABLE kartu_santri DROP CONSTRAINT IF EXISTS kartu_santri_status_check');
        DB::statement("ALTER TABLE kartu_santri ADD CONSTRAINT kartu_santri_status_check CHECK (status IN ('aktif', 'diblokir', 'dilepas'))");
        DB::statement("CREATE UNIQUE INDEX kartu_santri_uid_terpakai ON kartu_santri (uid) WHERE status <> 'dilepas'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kartu_santri_uid_terpakai');
        DB::table('kartu_santri')->where('status', 'dilepas')->delete();
        DB::statement('ALTER TABLE kartu_santri DROP CONSTRAINT IF EXISTS kartu_santri_status_check');
        DB::statement("ALTER TABLE kartu_santri ADD CONSTRAINT kartu_santri_status_check CHECK (status IN ('aktif', 'diblokir'))");
        Schema::table('kartu_santri', function (Blueprint $table) {
            $table->dropForeign(['dilepas_oleh']);
            $table->dropColumn(['dilepas_pada', 'dilepas_oleh']);
            $table->unique('uid');
        });
    }
};
