<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Laundry dipecah IKHWAN / AKHWAT (keputusan user 3 Nov 2026): modul `laundry` diganti
 * `laundry-ikhwan` & `laundry-akhwat` (lihat App\Support\BagianLaundry). Pemegang hak lama
 * mendapat KEDUA hak baru dengan centang yang sama — tak ada yang kehilangan akses; staf lalu
 * dipersempit lewat layar Hak Akses. Tanpa tabel baru: pembaginya jenis kelamin santri.
 */
return new class extends Migration
{
    private const BARU = ['laundry-ikhwan', 'laundry-akhwat'];

    public function up(): void
    {
        $lama = DB::table('hak_akses_modul')->where('kode_modul', 'laundry')->get();
        foreach ($lama as $h) {
            foreach (self::BARU as $kode) {
                $baris = (array) $h;
                unset($baris['id']);
                DB::table('hak_akses_modul')->updateOrInsert(
                    ['id_pengguna' => $h->id_pengguna, 'kode_modul' => $kode],
                    array_diff_key($baris, array_flip(['id_pengguna', 'kode_modul'])) + ['kode_modul' => $kode],
                );
            }
        }
        DB::table('hak_akses_modul')->where('kode_modul', 'laundry')->delete();
    }

    public function down(): void
    {
        $ikhwan = DB::table('hak_akses_modul')->where('kode_modul', 'laundry-ikhwan')->get();
        foreach ($ikhwan as $h) {
            $baris = (array) $h;
            unset($baris['id']);
            DB::table('hak_akses_modul')->updateOrInsert(['id_pengguna' => $h->id_pengguna, 'kode_modul' => 'laundry'],
                array_diff_key($baris, array_flip(['id_pengguna', 'kode_modul'])) + ['kode_modul' => 'laundry']);
        }
        DB::table('hak_akses_modul')->whereIn('kode_modul', self::BARU)->delete();
    }
};
