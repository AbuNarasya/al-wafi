<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SYARAT POTONGAN UANG PANGKAL DIATUR PER GELOMBANG + STATUS `batal`.
 *
 * 1. Syarat "bayar ≥ 50%" dan jeda termin "+30 hari" bila potongan hangus dulu
 *    tertanam di kode. Kini isian di master Gelombang, berdampingan dengan
 *    `masa_berlaku_hari` yang sudah bisa diatur. Nilai bawaannya tetap 50 & 30,
 *    jadi gelombang yang sudah ada berperilaku persis seperti sebelumnya.
 *
 * 2. Baris potongan menjepret JEDA-nya sendiri, sama seperti `syarat_persen`
 *    yang sudah dijepret sejak dulu: mengubah master tak boleh menggeser syarat
 *    potongan yang sudah dijanjikan ke wali.
 *
 * 3. Status `batal` untuk potongan milik tagihan yang dibatalkan/dihapus (calon
 *    mundur, santri keluar, koreksi ke nol). Uang pangkal dicatat cash basis
 *    selama calon; begitu tagihannya tak berlaku, tak ada lagi urusan potongan.
 *    Dulu potongannya tetap `berlaku`, dan evaluasi berikutnya bisa membuatnya
 *    "hangus" — menghidupkan kembali tagihan yang sudah batal.
 *
 * Enum Laravel di PostgreSQL = varchar + CHECK, jadi menambah nilainya berarti
 * membuang lalu memasang ulang constraint-nya.
 */
return new class extends Migration
{
    private const BARU = ['berlaku', 'earned', 'hangus', 'batal'];

    private const LAMA = ['berlaku', 'earned', 'hangus'];

    public function up(): void
    {
        Schema::table('gelombang', function (Blueprint $table) {
            $table->integer('syarat_persen_potongan')->default(50)->after('masa_berlaku_hari');
            $table->integer('jeda_termin_hangus_hari')->default(30)->after('syarat_persen_potongan');
        });

        Schema::table('potongan_uang_pangkal', function (Blueprint $table) {
            $table->integer('jeda_hangus_hari')->default(30)->after('syarat_persen');
        });

        $this->pasangCek(self::BARU);

        // Potongan yang tagihannya SUDAH tak berlaku sebelum migrasi ini ikut
        // dibereskan — kalau tidak, evaluasi berikutnya masih bisa membuatnya hangus.
        DB::statement("UPDATE potongan_uang_pangkal p SET status = 'batal', dinilai_pada = NOW()
            FROM tagihan_santri t
            WHERE t.id = p.id_tagihan AND p.status = 'berlaku' AND t.status IN ('batal', 'dihapus')");
    }

    public function down(): void
    {
        // Tanpa ini constraint lama menolak baris yang sudah terlanjur `batal`.
        DB::table('potongan_uang_pangkal')->where('status', 'batal')->update(['status' => 'hangus']);
        $this->pasangCek(self::LAMA);

        Schema::table('potongan_uang_pangkal', fn (Blueprint $t) => $t->dropColumn('jeda_hangus_hari'));
        Schema::table('gelombang', fn (Blueprint $t) => $t->dropColumn(['syarat_persen_potongan', 'jeda_termin_hangus_hari']));
    }

    private function pasangCek(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE potongan_uang_pangkal DROP CONSTRAINT IF EXISTS potongan_uang_pangkal_status_check');
        DB::statement("ALTER TABLE potongan_uang_pangkal ADD CONSTRAINT potongan_uang_pangkal_status_check CHECK (status IN ({$daftar}))");
    }
};
