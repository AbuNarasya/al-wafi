<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PUSH NOTIFICATION — langganan perangkat (Web Push, VAPID).
 *
 * ══ SATU BARIS PER PERANGKAT, BUKAN PER ORANG ══
 * Satu staf lazim memakai ponsel DAN laptop, dan masing-masing punya alamat
 * langganannya sendiri. Menyimpannya per pengguna berarti perangkat yang
 * didaftarkan belakangan menimpa yang lebih dulu — dan notifikasinya berhenti
 * sampai ke ponsel tanpa ada yang tahu sebabnya.
 *
 * ══ `endpoint` UNIK ══
 * Peramban memberi alamat yang sama bila perangkat yang sama mendaftar ulang.
 * Tanpa indeks unik, tiap kali seseorang menekan "Nyalakan" lahir baris baru,
 * dan ia mulai menerima notifikasi yang sama dua, tiga, empat kali.
 *
 * ══ IZIN ADA DI PERANGKAT, BUKAN DI SINI ══
 * Menghapus baris di sini TIDAK mencabut izin di ponsel, dan sebaliknya:
 * pengguna yang mencabut izin lewat pengaturan peramban meninggalkan baris
 * basi di tabel ini. Itu sebabnya ada `gagal_beruntun` — langganan yang ditolak
 * layanan push berkali-kali memang sudah mati, dan harus dibuang sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_langganan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_pengguna');

            // 500 cukup untuk seluruh layanan push yang ada (FCM, Mozilla,
            // Apple) dan masih aman sebagai kunci indeks btree PostgreSQL.
            $table->string('endpoint', 500)->unique();
            $table->string('p256dh');
            $table->string('auth');

            // Ringkasan perangkat ("Android · Chrome"), untuk dua hal: supaya
            // pengguna mengenali perangkat mana yang ia matikan, dan supaya
            // sebaran ponsel staf akhirnya terjawab tanpa perlu bertanya.
            $table->string('perangkat')->nullable();

            $table->timestamp('terakhir_berhasil')->nullable();
            $table->unsignedSmallInteger('gagal_beruntun')->default(0);
            $table->timestamps();

            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
            $table->index('id_pengguna');
        });

        Schema::table('notifications', function (Blueprint $table) {
            // NULL = belum pernah didorong. Penanda inilah yang dipakai perilis
            // menyapu, bukan perbandingan waktu: notifikasi yang lahir saat cron
            // sedang berjalan tetap terbawa pada sapuan berikutnya, alih-alih
            // terlewat karena stempel waktunya kebetulan di antara dua sapuan.
            $table->timestamp('didorong_pada')->nullable()->after('dibaca');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('didorong_pada');
        });
        Schema::dropIfExists('push_langganan');
    }
};
