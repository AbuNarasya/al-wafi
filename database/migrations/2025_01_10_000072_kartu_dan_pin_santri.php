<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BELANJA KARTU RFID (D1) — keputusan user 19 Okt 2026: dompet SANTRI; belanja kecil cukup
 * tempel kartu, di atas batas harian wajib PIN santri; batas bawaan Rp 20.000; kartu bisa
 * diblokir wali (portal) & staf, membuka blokir / mengganti hanya staf.
 *
 *  • `kartu_santri` — UID kartu (dari pembaca USB yang mengetik seperti keyboard) ↔ santri.
 *    UID unik selamanya; satu santri satu kartu AKTIF (indeks unik parsial). `id_batch`
 *    menandai kartu yang lahir dari Impor Data Awal supaya bisa dibatalkan.
 *  • `pin_santri` — PIN belanja anak, DIPASANG WALI dari portal (santri tak punya akun).
 *  • `dompet_santri.batas_harian` — kosong = batas bawaan `company_settings.batas_jajan_harian`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kartu_santri', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 64)->unique();
            $table->unsignedInteger('id_santri');
            $table->enum('status', ['aktif', 'diblokir'])->default('aktif');
            $table->string('alasan_blokir')->nullable();
            $table->timestamp('diblokir_pada')->nullable();
            $table->unsignedInteger('diblokir_oleh')->nullable();
            $table->unsignedInteger('diblokir_oleh_wali')->nullable();
            $table->unsignedInteger('dipasang_oleh')->nullable();
            $table->unsignedInteger('id_batch')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('diblokir_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('diblokir_oleh_wali')->references('id')->on('wali')->nullOnDelete();
            $table->foreign('dipasang_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('id_batch')->references('id')->on('impor_batch')->nullOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX kartu_santri_satu_aktif ON kartu_santri (id_santri) WHERE status = 'aktif'");

        Schema::create('pin_santri', function (Blueprint $table) {
            $table->unsignedInteger('id_santri')->primary();
            $table->string('pin_hash');
            $table->unsignedSmallInteger('gagal')->default(0);
            $table->timestamp('terkunci_sampai')->nullable();
            $table->unsignedInteger('dipasang_oleh_wali')->nullable();
            $table->string('dipasang_oleh_peran', 10)->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('dipasang_oleh_wali')->references('id')->on('wali')->nullOnDelete();
        });

        Schema::table('dompet_santri', function (Blueprint $table) {
            $table->decimal('batas_harian', 18, 2)->nullable()->after('kunci_tarik');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('batas_jajan_harian', 18, 2)->default(20000);
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('batas_jajan_harian');
        });
        Schema::table('dompet_santri', function (Blueprint $table) {
            $table->dropColumn('batas_harian');
        });
        Schema::dropIfExists('pin_santri');
        Schema::dropIfExists('kartu_santri');
    }
};
