<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BATCH TAGIHAN — penerbitan tagihan yang disusun & diotorisasi lebih dulu,
 * lalu dirilis kemudian (pola perintah berjangka pada internet banking).
 *
 * Penerbitan LANGSUNG di tiap modul TIDAK diganti; ini jalur tambahan untuk
 * pekerjaan yang ingin diperiksa dulu berhari-hari, atau yang rilisnya harus
 * tetap jalan saat petugasnya libur.
 *
 * ══ ANGKA DIKUNCI SAAT OTORISASI ══
 * `batch_tagihan_baris.nominal` adalah jepretan saat draft disusun, bukan
 * hasil hitung ulang saat rilis. Itu inti polanya: yang diperiksa petugas
 * itulah yang terbit. Yang TETAP diperiksa ulang saat rilis adalah KELAYAKAN
 * tiap baris (santri masih aktif, belum punya tagihan yang sama) — baris yang
 * tak lagi layak dilewati berikut alasannya, tidak membatalkan batch.
 *
 * ══ TANGGAL JURNAL ══
 * Untuk batch terjadwal, tanggal jurnalnya adalah tanggal `rilis_pada`, BUKAN
 * tanggal perilis benar-benar berjalan — cron bisa telat atau mati sehari.
 * Bila periode itu sudah ditutup, rilis DITOLAK dan batch ditandai `gagal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_tagihan', function (Blueprint $table) {
            $table->increments('id');

            // Modul asal. Menentukan cara menyusun draft DAN cara menerbitkannya
            // — ketiganya berjurnal & berpenjaga sendiri, jadi tak bisa disamakan.
            $table->enum('modul', ['spp', 'daftar_ulang', 'tagihan_lain']);
            $table->string('judul');

            // Saringan penyusun. Berbentuk jsonb karena ketiga modul bertanya hal
            // yang berbeda: SPP butuh periode, daftar ulang butuh T.A & jenjang,
            // tagihan lain butuh kode jenis & sumber pesertanya.
            $table->jsonb('parameter');

            // dirilis  = seluruh baris terbit
            // sebagian = ada yang dilewati saat rilis (santri keluar, sudah tertagih)
            // gagal    = tak satu pun terbit (mis. periodenya sudah ditutup)
            $table->enum('status', ['draft', 'diotorisasi', 'dirilis', 'sebagian', 'gagal', 'dibatalkan'])
                ->default('draft');

            // Ringkasan baris berketetapan `terbit` saja — yang `bebas`,
            // `dilewati`, dan `terhalang` tak pernah jadi tagihan.
            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->decimal('total', 18, 2)->default(0);

            // NULL = tanpa jadwal, hanya bisa dirilis dengan menekan tombol.
            $table->timestamp('rilis_pada')->nullable();

            $table->unsignedInteger('disusun_oleh');
            $table->unsignedInteger('diotorisasi_oleh')->nullable();
            $table->timestamp('diotorisasi_pada')->nullable();
            // NULL saat yang merilis adalah penjadwal, bukan orang.
            $table->unsignedInteger('dirilis_oleh')->nullable();
            $table->timestamp('dirilis_pada')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('disusun_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->foreign('diotorisasi_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();
            $table->foreign('dirilis_oleh')->references('id_pengguna')->on('users')->restrictOnDelete();

            // Kueri perilis: "yang sudah diotorisasi dan waktunya sudah lewat".
            $table->index(['status', 'rilis_pada']);
        });

        Schema::create('batch_tagihan_baris', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_batch');
            $table->unsignedInteger('id_santri');

            // Kosong pada baris yang tarifnya belum ada — jenisnya memang belum
            // bisa ditentukan, dan baris itu tak akan pernah jadi tagihan.
            $table->string('kode_jenis')->nullable();
            $table->string('periode')->nullable();
            $table->decimal('nominal', 18, 2)->nullable();

            // Keputusan saat draft disusun, istilahnya sama dengan pratinjau
            // TagihanMassalService supaya petugas tak belajar dua kosakata.
            $table->enum('keputusan', ['terbit', 'bebas', 'dilewati', 'terhalang']);
            $table->text('alasan')->nullable();

            // Hasil saat rilis. `dilewati` di sini berbeda artinya dengan
            // `keputusan`: ini yang GUGUR di antara otorisasi dan rilis.
            $table->enum('hasil', ['menunggu', 'terbit', 'dilewati', 'gagal'])->default('menunggu');
            $table->text('hasil_alasan')->nullable();
            $table->unsignedInteger('id_tagihan')->nullable();

            // Jenjang, tingkat, & tahun ajaran yang BERLAKU saat draft disusun —
            // ikut dikunci karena tarifnya bergantung pada ketiganya.
            $table->jsonb('snapshot')->nullable();

            $table->foreign('id_batch')->references('id')->on('batch_tagihan')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->restrictOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->nullOnDelete();

            // Satu santri satu baris per batch. Menyusun draft dua kali untuk
            // orang yang sama hanya melahirkan tagihan dobel.
            $table->unique(['id_batch', 'id_santri']);
            $table->index(['id_batch', 'keputusan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_tagihan_baris');
        Schema::dropIfExists('batch_tagihan');
    }
};
