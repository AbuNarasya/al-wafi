<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gelombang menjadi KODE BERUPA TEKS, bukan angka urut.
 *
 * Akan ada gelombang khusus yang tak mengacu ke angka ("Beasiswa Tahfizh",
 * "Anak Karyawan"), dan memaksanya menjadi 1/2/3 membuat nomornya kehilangan
 * arti — sekaligus memaksa petugas menghafal nomor mana milik gelombang apa.
 *
 * Nilai lama dialihbentukkan apa adanya: angka 1 menjadi teks "1", jadi santri
 * & tagihan yang sudah terbit tetap tersambung ke baris potongannya. Yang
 * berubah hanya tipenya, bukan isinya.
 *
 * PostgreSQL menolak mengubah integer→varchar tanpa diberi tahu caranya, maka
 * dipakai USING ...::text. Indeks unik (T.A, gelombang, jenjang) tetap sah
 * karena kolomnya tetap satu dan nilainya tetap unik setelah dialihbentukkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE potongan_gelombang ALTER COLUMN gelombang TYPE varchar(50) USING gelombang::text');
        DB::statement('ALTER TABLE potongan_uang_pangkal ALTER COLUMN gelombang TYPE varchar(50) USING gelombang::text');

        // santri.gelombang punya DEFAULT 1 warisan skema lama; default itu harus
        // dibuang lebih dulu, kalau tidak PostgreSQL menolak mengubah tipenya.
        DB::statement('ALTER TABLE santri ALTER COLUMN gelombang DROP DEFAULT');
        DB::statement('ALTER TABLE santri ALTER COLUMN gelombang TYPE varchar(50) USING gelombang::text');
        DB::statement('ALTER TABLE santri ALTER COLUMN gelombang DROP NOT NULL');
    }

    public function down(): void
    {
        // Hanya kode yang seluruhnya angka yang bisa kembali; sisanya dikosongkan
        // — memaksakan "Beasiswa Tahfizh" menjadi angka justru merusak datanya.
        foreach (['potongan_gelombang', 'potongan_uang_pangkal', 'santri'] as $tabel) {
            DB::statement("UPDATE {$tabel} SET gelombang = NULL WHERE gelombang !~ '^[0-9]+$'");
            DB::statement("ALTER TABLE {$tabel} ALTER COLUMN gelombang TYPE integer USING gelombang::integer");
        }
        Schema::table('santri', fn ($t) => $t->integer('gelombang')->default(1)->change());
    }
};
