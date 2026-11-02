<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNDRY tahap 2 (keputusan user 26 Okt 2026):
 *  • kuota per BULAN kalender, bawaan 20 kg, bisa dibedakan per jenjang; sisa TIDAK terbawa (reset tiap tanggal 1 —
 *    dihitung dari tanggal diterima, jadi tak ada saldo yang perlu di-reset);
 *  • kelebihan × tarif per kg → TAGIHAN BULANAN: draf disusun tanggal 1 untuk bulan lalu, ditinjau keuangan, terbit
 *    otomatis pada `hari_terbit` (bawaan 5) lewat mesin Tagihan Lain-lain (jurnal, portal, VA, auto-debet);
 *  • pengaturan tarif & kuota tinggal di grup LAUNDRY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_laundry', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            // Kosong = belum diatur → draf tagihan tak bisa disusun (layar memberitahu).
            $table->decimal('tarif_per_kg', 18, 2)->nullable();
            $table->decimal('kuota_bawaan_kg', 6, 2)->default(20);
            // Jenis biaya (perilaku Lain-lain) yang dipakai menagih; bisa ditimpa per jenjang.
            $table->string('kode_jenis_bawaan')->nullable();
            $table->unsignedTinyInteger('hari_terbit')->default(5);
            $table->boolean('terbit_otomatis')->default(true);
            // Pelaku jurnal saat terbit otomatis = orang yang terakhir mengatur ini (jurnal wajib berpelaku manusia).
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->timestamps();
            $table->foreign('kode_jenis_bawaan')->references('kode')->on('jenis_biaya')->nullOnDelete();
            $table->foreign('diubah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
        DB::table('pengaturan_laundry')->insert(['id' => 1, 'kuota_bawaan_kg' => 20, 'hari_terbit' => 5, 'terbit_otomatis' => true,
            'created_at' => now(), 'updated_at' => now()]);

        Schema::create('pengaturan_laundry_jenjang', function (Blueprint $table) {
            $table->string('kode_jenjang')->primary();
            $table->decimal('kuota_kg', 6, 2)->nullable();      // kosong = kuota bawaan
            $table->string('kode_jenis')->nullable();           // kosong = jenis biaya bawaan
            $table->timestamps();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->nullOnDelete();
        });

        Schema::create('tagihan_laundry', function (Blueprint $table) {
            $table->increments('id');
            $table->char('periode', 7);                         // YYYY-MM
            $table->unsignedInteger('id_santri');
            $table->string('kode_jenjang')->nullable();
            $table->decimal('kg_total', 8, 2);
            $table->decimal('kuota_kg', 6, 2);
            $table->decimal('kg_lebih', 8, 2);
            $table->decimal('tarif_per_kg', 18, 2);
            $table->decimal('nominal', 18, 2);
            $table->string('kode_jenis')->nullable();
            $table->enum('status', ['draf', 'terbit', 'dikecualikan']);
            $table->text('alasan_kecuali')->nullable();
            $table->unsignedInteger('id_tagihan')->nullable();
            $table->timestamp('terbit_pada')->nullable();
            $table->unsignedInteger('terbit_oleh')->nullable();
            $table->unsignedInteger('dikecualikan_oleh')->nullable();
            $table->timestamps();

            $table->unique(['periode', 'id_santri']);
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->nullOnDelete();
            $table->foreign('terbit_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('dikecualikan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['periode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan_laundry');
        Schema::dropIfExists('pengaturan_laundry_jenjang');
        Schema::dropIfExists('pengaturan_laundry');
    }
};
