<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `jalur_pendaftaran.urutan` — urutan tampil yang ditentukan petugas.
 *
 * Sebelum ini jalur diurut berbeda-beda di tiap layar (abjad nama di masternya,
 * `kode` di grid tarif & dashboard PPSB, `created_at` di dropdown santri),
 * sehingga "jalur ketiga" tidak pernah berarti baris yang sama. Kolom ini
 * menjadi satu-satunya penentu urutan, seperti yang sudah lebih dulu dipakai
 * `jenjang`, `tipe_biaya`, dan `sumber_informasi`.
 *
 * Isi awalnya mengikuti URUTAN ABJAD NAMA — persis tampilan master sebelum
 * migrasi ini — supaya tak ada layar yang berubah susunannya tanpa diminta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->integer('urutan')->default(0);
        });

        // Subquery ditaruh di FROM dengan syarat di WHERE: di PostgreSQL tabel
        // yang di-UPDATE tak boleh dirujuk dari klausa JOIN.
        DB::statement(<<<'SQL'
            UPDATE jalur_pendaftaran
               SET urutan = s.n
              FROM (SELECT kode, ROW_NUMBER() OVER (ORDER BY nama) AS n FROM jalur_pendaftaran) s
             WHERE jalur_pendaftaran.kode = s.kode
        SQL);
    }

    public function down(): void
    {
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->dropColumn('urutan');
        });
    }
};
