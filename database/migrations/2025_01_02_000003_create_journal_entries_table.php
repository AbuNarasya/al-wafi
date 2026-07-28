<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * journal_entries — HEADER jurnal double-entry. Balance (Σdebet = Σkredit)
 * divalidasi di service layer (DB transaction), bukan di skema.
 * Tidak ada hard-delete: status `void` + reversal (reversal_of menautkan entry
 * pembalik ke entry asal, self-relation). Dimensi unit bisnis melekat di BARIS
 * (journal_lines), bukan di header. Hanya punya created_at (tanpa updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('referensi');
            $table->date('tanggal');
            $table->text('keterangan')->nullable();
            $table->string('sumber_modul'); // KasMasuk, KasKeluar, Invoice, Accrue, ...
            $table->string('id_sumber')->nullable();
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->unsignedInteger('reversal_of')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();

            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index('referensi');
            $table->index('tanggal');
            $table->index(['sumber_modul', 'id_sumber']);
        });

        // FK self-reference (jurnal pembalik) ditambah terpisah agar PK sudah ada.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reversal_of')->references('id')->on('journal_entries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
