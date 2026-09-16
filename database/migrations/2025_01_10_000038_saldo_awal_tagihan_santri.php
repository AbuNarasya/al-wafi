<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENANDA `saldo_awal` — tagihan yang masuk sebagai keadaan pindahan sistem,
 * bukan transaksi baru.
 *
 * Saldo awal di aplikasi ini berjalan di DUA jalur yang sengaja dipisah: buku
 * besarnya lewat menu Saldo Awal (satu jurnal pembuka), rinciannya lewat pintu
 * yang TIDAK pernah menjurnal. Baris tunggakan warisan karena itu
 * ber-`sudah_akrual = true` tanpa jurnal mana pun — nilainya sudah diakui
 * sebagai pendapatan di pembukuan lama, jadi pembayarannya kelak mengkredit
 * PIUTANG, bukan Pendapatan.
 *
 * `sudah_akrual` saja TIDAK cukup membedakannya. Tagihan Lain-lain berpengakuan
 * akrual, Tagihan Massal, dan kenaikan jenjang sama-sama menghasilkan
 * `sudah_akrual = true` — bedanya, ketiganya MENERBITKAN jurnal. Tanpa penanda
 * tersendiri, tombol "hapus tanpa jurnal" akan muncul juga di tagihan berjurnal,
 * dan menghapusnya diam-diam meninggalkan piutang di buku besar tanpa lawan di
 * buku pembantu.
 *
 * Aditif & berdefault, jadi seluruh baris lama tetap sah apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->boolean('saldo_awal')->default(false);
        });

        // Tunggakan hasil impor santri lama: bertanda batch DAN berakrual tanpa
        // jurnal. Tagihan yang diterbitkan petugas kemudian tak pernah bertanda
        // batch, jadi keduanya terpisah bersih — tanpa perlu menebak lewat waktu
        // pembuatan, yang sudah pernah jadi sumber kekeliruan di penjagaan batch.
        DB::table('tagihan_santri')
            ->whereNotNull('id_batch')
            ->where('sudah_akrual', true)
            ->update(['saldo_awal' => true]);
    }

    public function down(): void
    {
        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->dropColumn('saldo_awal');
        });
    }
};
