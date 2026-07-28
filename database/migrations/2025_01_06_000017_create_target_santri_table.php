<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** target_santri — Target jumlah santri per Tahun Ajaran per jenjang. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_santri', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tahun_ajaran');
            $table->string('jenjang');
            $table->integer('target');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['tahun_ajaran', 'jenjang']);
            $table->index('tahun_ajaran');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_santri');
    }
};
