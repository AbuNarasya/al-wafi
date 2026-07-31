<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERBAIKAN DATA: tagihan berperiode tercap tahun ajaran yang salah.
 *
 * `SppService::pratinjau()` dulu mencap tagihan dengan `taBerjalan()` SANTRINYA,
 * bukan tahun ajaran periodenya. Akibatnya SPP periode 2026-07 tercap 2027/2028
 * hanya karena santrinya sudah terdaftar untuk tahun itu — padahal 2027/2028
 * baru mulai setahun kemudian, sehingga laporan per tahun ajaran menaruh
 * pendapatan Juli 2026 di tahun yang keliru.
 *
 * Servicenya sudah dibetulkan; migrasi ini membereskan baris yang telanjur ada.
 *
 * SEMPIT dengan sengaja — hanya tagihan yang PUNYA periode (`YYYY-MM`), dan
 * hanya bila ada tahun ajaran yang rentang tanggalnya benar-benar memuat awal
 * bulan itu. Yang periodenya jatuh di celah kalender DIBIARKAN: menebak tahunnya
 * lebih buruk daripada meninggalkannya untuk diperiksa manusia.
 *
 * JURNAL TIDAK DISENTUH. Periode buku besar ditentukan TANGGAL jurnal, bukan
 * kolom ini — yang diperbaiki di sini label subledger-nya saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ta = DB::table('tahun_ajaran')
            ->whereNotNull('tanggal_mulai')->whereNotNull('tanggal_selesai')
            ->get(['kode', 'tanggal_mulai', 'tanggal_selesai']);

        $baris = DB::table('tagihan_santri')
            ->whereNotNull('periode')
            ->where('periode', 'like', '____-__')
            ->get(['id', 'periode', 'tahun_ajaran']);

        $perbaikan = [];
        foreach ($baris as $t) {
            $awalBulan = $t->periode.'-01';
            foreach ($ta as $y) {
                if ($awalBulan >= $y->tanggal_mulai && $awalBulan <= $y->tanggal_selesai) {
                    if ($t->tahun_ajaran !== $y->kode) {
                        $perbaikan[$y->kode][] = $t->id;
                    }
                    break;
                }
            }
        }

        foreach ($perbaikan as $kode => $id) {
            DB::table('tagihan_santri')->whereIn('id', $id)
                ->update(['tahun_ajaran' => $kode, 'updated_at' => now()]);
        }
    }

    /**
     * Tidak dibalik. Nilai lamanya justru yang keliru, dan menyimpannya untuk
     * dipulihkan hanya akan mengembalikan tagihan ke tahun ajaran yang salah.
     */
    public function down(): void {}
};
