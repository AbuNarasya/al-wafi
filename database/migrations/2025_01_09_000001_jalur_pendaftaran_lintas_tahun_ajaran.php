<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jalur pendaftaran BERLAKU LINTAS TAHUN AJARAN (keputusan user 2026-07-28).
 *
 * Sebelumnya tiap jalur terikat satu T.A, sehingga membuka tahun ajaran baru
 * menuntut seluruh jalur dibuat ulang — padahal "Reguler" atau "Pindahan"
 * memang jalur yang sama setiap tahun. Kolomnya dibuang, bukan sekadar
 * dikosongkan, supaya tidak tersisa isian yang tak berarti apa-apa di form.
 *
 * Tarif TETAP bisa dibedakan per tahun ajaran: yang membawa dimensi T.A adalah
 * `jenis_biaya.tahun_ajaran` (dipasangkan dengan `kode_jalur`), bukan masternya.
 *
 * CATATAN saat menurunkan (down): kolomnya bisa dikembalikan, tetapi NILAI
 * lamanya tidak — tak ada tempat menyimpannya. Baris yang ada diisi tahun
 * ajaran default agar kolomnya bisa NOT NULL lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->dropForeign(['tahun_ajaran']);
            $table->dropIndex(['tahun_ajaran']);
            $table->dropColumn('tahun_ajaran');
        });
    }

    public function down(): void
    {
        $ta = \Illuminate\Support\Facades\DB::table('tahun_ajaran')
            ->orderByDesc('default_pendaftaran')->orderBy('kode')->value('kode');

        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->string('tahun_ajaran')->nullable()->after('nama');
        });

        if ($ta) {
            \Illuminate\Support\Facades\DB::table('jalur_pendaftaran')->update(['tahun_ajaran' => $ta]);
        }

        Schema::table('jalur_pendaftaran', function (Blueprint $table) {
            $table->string('tahun_ajaran')->nullable(false)->change();
            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->restrictOnDelete();
            $table->index('tahun_ajaran');
        });
    }
};
