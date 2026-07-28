<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * approval_steps — Satu tahap rantai. TEPAT SATU dari peringkat (tahap pangkat)
 * atau fungsi (tahap fungsi, mis. "keuangan") terisi — dijaga CHECK constraint.
 * nominal_min: tahap dilewati bila nominal < nilai ini. syarat: "overbudget" dst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_flow');
            $table->integer('urutan');
            $table->string('nama_tahap');
            $table->integer('peringkat')->nullable();
            $table->string('fungsi')->nullable();
            $table->string('scope')->default('bagian');
            $table->decimal('nominal_min', 18, 2)->nullable();
            $table->string('syarat')->nullable();

            $table->foreign('kode_flow')->references('kode_flow')->on('approval_flows')->cascadeOnDelete();
            $table->foreign('peringkat')->references('peringkat')->on('level_pengajuan')->restrictOnDelete();
            $table->unique(['kode_flow', 'urutan']);
        });

        // Invarian "tepat satu terisi" (peringkat XOR fungsi).
        DB::statement('ALTER TABLE approval_steps ADD CONSTRAINT approval_steps_peringkat_xor_fungsi CHECK ((peringkat IS NOT NULL AND fungsi IS NULL) OR (peringkat IS NULL AND fungsi IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
    }
};
