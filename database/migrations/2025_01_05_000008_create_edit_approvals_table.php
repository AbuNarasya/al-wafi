<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * edit_approvals — Pengajuan EDIT transaksi (payload JSON diterapkan saat
 * disetujui). Hanya created_at + decided_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edit_approvals', function (Blueprint $table) {
            $table->increments('id');
            $table->string('modul');
            $table->string('id_record');
            $table->string('ref')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->text('payload'); // JSON perubahan
            $table->text('ringkasan')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('id_pengguna');
            $table->string('nama_pemohon')->nullable();
            $table->unsignedInteger('decided_by')->nullable();
            $table->string('nama_penyetuju')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_approvals');
    }
};
