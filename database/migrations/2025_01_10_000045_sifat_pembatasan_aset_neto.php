<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIFAT PEMBATASAN pada akun — tulang punggung pelaporan nirlaba (ISAK 35).
 *
 * Laporan yang ada memakai kosakata perusahaan: Neraca, Laba Rugi, Perubahan
 * Modal. Yayasan & pesantren dituntut kosakata lain — Laporan Posisi Keuangan,
 * Penghasilan Komprehensif, dan yang paling khas: LAPORAN PERUBAHAN ASET NETO,
 * yang memisahkan aset neto DENGAN pembatasan dari yang TANPA pembatasan.
 *
 * Kolom ini hanya bermakna untuk akun Pendapatan (4) dan Ekuitas/Aset Neto (3).
 * Aset, liabilitas, dan beban dibiarkan kosong: pembatasan melekat pada sumber
 * dananya dan pada aset neto yang terbentuk darinya, bukan pada kas atau
 * hutangnya — kas dari wakaf menumpang rekening yang sama dengan kas lain.
 *
 * Bawaannya `tanpa_pembatasan` untuk kedua kelompok itu. Itu bukan tebakan
 * berisiko seperti pada klasifikasi arus kas: pesantren yang belum punya dana
 * terikat memang seluruh aset netonya tanpa pembatasan, dan yang punya tinggal
 * menandai beberapa akun saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coa_detail', function (Blueprint $table) {
            $table->string('sifat_pembatasan')->nullable()->after('klasifikasi_arus_kas');
            $table->index('sifat_pembatasan');
        });

        $grup = DB::table('coa_groups')->get(['kode_grup', 'kode_induk'])->keyBy('kode_grup');
        $akar = function (?string $kode) use ($grup) {
            $lihat = [];
            $cur = $kode ? ($grup[$kode] ?? null) : null;
            while ($cur && $cur->kode_induk && ! in_array($cur->kode_grup, $lihat, true)) {
                $lihat[] = $cur->kode_grup;
                $cur = $grup[$cur->kode_induk] ?? null;
            }

            return $cur?->kode_grup;
        };

        foreach (DB::table('coa_detail')->get(['kode_coa', 'kode_grup']) as $a) {
            if (in_array($akar($a->kode_grup), ['3', '4'], true)) {
                DB::table('coa_detail')->where('kode_coa', $a->kode_coa)
                    ->update(['sifat_pembatasan' => 'tanpa_pembatasan']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('coa_detail', function (Blueprint $table) {
            $table->dropIndex(['sifat_pembatasan']);
            $table->dropColumn('sifat_pembatasan');
        });
    }
};
