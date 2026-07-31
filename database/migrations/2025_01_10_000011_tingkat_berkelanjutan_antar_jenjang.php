<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran tingkat BERKELANJUTAN antar jenjang: SDTQ 1–6, SMP 7–9, SMA 10–12.
 *
 * Sebelumnya setiap jenjang memulai penomorannya dari 1, sehingga "Tingkat 2"
 * bisa berarti SDTQ kelas 2, SMP kelas 8, atau SMA kelas 11 — dan tak ada
 * satu pun layar yang bisa membedakannya tanpa ikut menyebut jenjangnya.
 * Sejak NIS memuat tingkat, ambiguitas itu tak lagi bisa dibiarkan.
 *
 * `tingkat_mulai` disimpan, BUKAN dihitung: menghitungnya dari akumulasi
 * jenjang sebelumnya membuat penyisipan satu jenjang di tengah menggeser
 * penomoran seluruh jenjang sesudahnya — termasuk tingkat yang sudah tercetak
 * di NIS santri yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenjang', function (Blueprint $t) {
            $t->unsignedSmallInteger('tingkat_mulai')->nullable()->after('jumlah_tingkat');
        });

        // Nilai awal = akumulasi jenjang sebelumnya menurut urutan master:
        // SDTQ mulai 1, SMP mulai 1+6 = 7, SMA mulai 7+3 = 10. Sesudah ini
        // angkanya bebas disunting petugas lewat master Jenjang.
        $mulai = 1;
        foreach (DB::table('jenjang')->orderBy('urutan')->orderBy('kode')->get(['kode', 'jumlah_tingkat']) as $j) {
            DB::table('jenjang')->where('kode', $j->kode)->update(['tingkat_mulai' => $mulai]);
            $mulai += max(1, (int) $j->jumlah_tingkat);
        }

        // Sel tarif daftar ulang menyimpan tingkat TUJUAN dengan penomoran lama
        // (2..N per jenjang). Tanpa dipetakan ulang, penagihan daftar ulang SMP &
        // SMA berhenti dengan "sel tarif belum diisi" — tingkat 8 dicari, yang
        // tersimpan tingkat 2.
        foreach (DB::table('jenjang')->get(['kode', 'tingkat_mulai']) as $j) {
            $geser = (int) $j->tingkat_mulai - 1;
            if ($geser === 0) {
                continue;
            }
            DB::table('tarif_biaya')
                ->where('kode_jenjang', $j->kode)
                ->whereNotNull('tingkat')
                ->update(['tingkat' => DB::raw("tingkat + {$geser}")]);
        }

        // Santri & riwayat tingkat ikut digeser supaya tetap menunjuk kelas yang
        // sama. Aman dijalankan pada basis data kosong (0 baris).
        foreach (DB::table('jenjang')->get(['kode', 'tingkat_mulai']) as $j) {
            $geser = (int) $j->tingkat_mulai - 1;
            if ($geser === 0) {
                continue;
            }
            DB::table('santri')->where('kode_jenjang', $j->kode)->whereNotNull('tingkat')
                ->update(['tingkat' => DB::raw("tingkat + {$geser}")]);
            DB::table('riwayat_tingkat')->where('kode_jenjang', $j->kode)->whereNotNull('tingkat')
                ->update(['tingkat' => DB::raw("tingkat + {$geser}")]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('jenjang')->get(['kode', 'tingkat_mulai']) as $j) {
            $geser = (int) $j->tingkat_mulai - 1;
            if ($geser === 0) {
                continue;
            }
            DB::table('tarif_biaya')->where('kode_jenjang', $j->kode)->whereNotNull('tingkat')
                ->update(['tingkat' => DB::raw("tingkat - {$geser}")]);
            DB::table('santri')->where('kode_jenjang', $j->kode)->whereNotNull('tingkat')
                ->update(['tingkat' => DB::raw("tingkat - {$geser}")]);
            DB::table('riwayat_tingkat')->where('kode_jenjang', $j->kode)->whereNotNull('tingkat')
                ->update(['tingkat' => DB::raw("tingkat - {$geser}")]);
        }

        Schema::table('jenjang', fn (Blueprint $t) => $t->dropColumn('tingkat_mulai'));
    }
};
