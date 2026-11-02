<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNDRY — tahap 1 (keputusan user 26 Okt 2026): grup menu tersendiri, santri TAP KARTU RFID saat menyerahkan &
 * mengambil cucian (cari nama/NIS sebagai cadangan), daftar pakaian WAJIB dicatat lalu dicocokkan saat diserahkan,
 * status Diterima → Diproses → Selesai → Diserahkan dengan waktu & petugas tiap langkah.
 * Kuota & tagihan kelebihan menyusul di tahap 2; portal wali di tahap 3.
 */
return new class extends Migration
{
    /** Jenis pakaian yang sudah dipakai laundry di ISE (Okt 2026), kembarannya disatukan. */
    private const JENIS_AWAL = [
        'Baju Renang', 'Cardigan', 'Celana Panjang', 'Celana Pendek', 'Celana Dalam', 'Daster', 'Gamis', 'Gamis Hijau Tosca',
        'Handuk', 'Handuk Kepala', 'Hasduk', 'Jas', 'Jaket', 'Jilbab', 'Jilbab Pashmina', 'Jilbab Segi 4', 'Kaos', 'Kaos Dalam',
        'Kaos Kaki', 'Kemeja', 'Koko', 'Manset Tangan', 'Mukena', 'Peci', 'Rok', 'Sabuk', 'Sajadah', 'Sapu Tangan', 'Sarung',
        'Sarung Bantal', 'Sarung Guling', 'Selimut', 'Seragam Batik', 'Seragam Bela Diri', 'Seragam Gamis', 'Seragam Gamis Abu',
        'Seragam Gamis Batik', 'Seragam Gamis Hitam', 'Seragam Gamis Navy', 'Seragam Gamis Pramuka', 'Seragam Koko',
        'Seragam Olahraga', 'Seragam Pramuka', 'Seragam Taruna', 'Sorban', 'Sprei', 'Sweater', 'Tas',
    ];

    public function up(): void
    {
        Schema::create('jenis_pakaian_laundry', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama', 100)->unique();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
        $now = now();
        DB::table('jenis_pakaian_laundry')->insert(array_map(fn ($n) => ['nama' => $n, 'aktif' => true, 'created_at' => $now, 'updated_at' => $now], self::JENIS_AWAL));

        Schema::create('laundry', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 30)->unique();
            $table->unsignedInteger('id_santri');
            $table->decimal('berat', 6, 2);
            $table->text('catatan')->nullable();
            $table->enum('status', ['diterima', 'diproses', 'selesai', 'diserahkan', 'dibatalkan']);
            // Cara santri dikenali saat menyerahkan / mengambil: tap kartu, atau dicari manual (kartu tertinggal).
            $table->enum('cara_terima', ['kartu', 'manual']);
            $table->enum('cara_serah', ['kartu', 'manual'])->nullable();
            foreach (['diterima', 'diproses', 'selesai', 'diserahkan', 'dibatalkan'] as $langkah) {
                $table->timestamp("{$langkah}_pada")->nullable();
                $table->unsignedInteger("{$langkah}_oleh")->nullable();
                $table->foreign("{$langkah}_oleh")->references('id_pengguna')->on('users')->nullOnDelete();
            }
            $table->boolean('ada_kurang')->default(false);
            $table->text('catatan_serah')->nullable();
            $table->text('alasan_batal')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->index('status');
            $table->index(['id_santri', 'diterima_pada']);
            $table->index('diterima_pada');
        });

        Schema::create('laundry_item', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_laundry');
            $table->unsignedInteger('id_jenis_pakaian');
            // Nama disalin saat dicatat: mengganti nama jenis kelak tak mengubah riwayat.
            $table->string('nama_pakaian', 100);
            $table->unsignedSmallInteger('jumlah');
            // Diisi saat diserahkan; kurang dari `jumlah` = ada yang kurang / hilang.
            $table->unsignedSmallInteger('jumlah_kembali')->nullable();

            $table->foreign('id_laundry')->references('id')->on('laundry')->cascadeOnDelete();
            $table->foreign('id_jenis_pakaian')->references('id')->on('jenis_pakaian_laundry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_item');
        Schema::dropIfExists('laundry');
        Schema::dropIfExists('jenis_pakaian_laundry');
    }
};
