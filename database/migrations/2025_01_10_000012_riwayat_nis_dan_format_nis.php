<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NIS berformat & BERIWAYAT.
 *
 * NIS kini diterbitkan ulang setiap santri masuk jenjang baru, sehingga satu
 * santri bisa memiliki beberapa NIS sepanjang hidupnya di pesantren:
 * `santri.nis` memegang yang BERLAKU, tabel ini menyimpan seluruhnya.
 *
 * Tanpa riwayat, NIS lama akan tertimpa dan kartu, rapor, serta berkas lama
 * menunjuk nomor yang tak lagi dikenal sistem — padahal itulah satu-satunya
 * pegangan orang tua saat bertanya di meja administrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nis_santri', function (Blueprint $t) {
            $t->id();
            $t->foreignId('id_santri')->constrained('santri')->cascadeOnDelete();
            // Unik LINTAS santri: satu nomor tak boleh pernah dipakai dua orang,
            // termasuk oleh nomor yang sudah tak berlaku.
            $t->string('nis', 40)->unique();
            $t->string('kode_jenjang', 20)->nullable();
            $t->unsignedSmallInteger('tingkat')->nullable();
            // Tahun ajaran saat santri MASUK jenjang itu — sumber 4 digit pertama.
            $t->string('tahun_ajaran', 20)->nullable();
            $t->unsignedInteger('urut')->nullable();
            $t->boolean('berlaku')->default(true);
            $t->date('diterbitkan_pada')->nullable();
            $t->unsignedBigInteger('diterbitkan_oleh')->nullable();
            $t->timestamps();

            $t->index(['id_santri', 'berlaku']);
            $t->index(['tahun_ajaran', 'kode_jenjang']);
        });

        // Singleton, pola yang sama dengan reminder_settings & company_settings.
        Schema::create('pengaturan_nis', function (Blueprint $t) {
            $t->id();
            $t->string('format', 100)->default('{TA4}{TINGKAT2}{URUT3}');
            $t->timestamps();
        });
        DB::table('pengaturan_nis')->insert([
            'id' => 1, 'format' => '{TA4}{TINGKAT2}{URUT3}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // NIS yang sudah ada (mis. hasil impor santri lama) diangkat menjadi baris
        // riwayat pertamanya, supaya tak ada nomor yang berdiri di luar catatan.
        $baris = DB::table('santri')->whereNotNull('nis')->where('nis', '!=', '')
            ->get(['id', 'nis', 'kode_jenjang', 'tingkat', 'tahun_ajaran']);
        foreach ($baris as $s) {
            DB::table('nis_santri')->insert([
                'id_santri' => $s->id, 'nis' => $s->nis, 'kode_jenjang' => $s->kode_jenjang,
                'tingkat' => $s->tingkat, 'tahun_ajaran' => $s->tahun_ajaran,
                'urut' => null, 'berlaku' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nis_santri');
        Schema::dropIfExists('pengaturan_nis');
    }
};
