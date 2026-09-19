<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KLASIFIKASI ARUS KAS pada tiap akun: operasi | investasi | pendanaan.
 *
 * Laporan Arus Kas yang lama dibangun dari dokumen Kas Masuk & Kas Keluar saja,
 * sehingga SELURUH penerimaan santri — sumber kas terbesar pesantren, yang
 * memposting jurnalnya sendiri lewat `PembayaranSantri` — tak pernah muncul di
 * sana. Begitu pula pindah buku, pinjaman, mutasi dompet, dan jurnal umum yang
 * menyentuh kas. Laporan itu kini dibangun ulang dari `journal_lines`, dan
 * pengelompokannya butuh satu penanda per akun. Inilah penandanya.
 *
 * NILAI BAWAAN sengaja hanya diisikan untuk akun yang klasifikasinya nyaris
 * tak pernah keliru:
 *   - Pendapatan (4) & Beban (5) → operasi
 *   - Ekuitas (3)                → pendanaan
 * Aset (1) & Liabilitas (2) DIBIARKAN KOSONG: piutang & hutang usaha itu
 * operasi, aset tetap itu investasi, pinjaman bank itu pendanaan — tak ada
 * cara menebaknya dari nomor akun, dan tebakan yang salah di laporan arus kas
 * jauh lebih berbahaya daripada kolom yang terang-terangan kosong. Laporannya
 * menampilkan yang belum diklasifikasikan sebagai kelompok tersendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coa_detail', function (Blueprint $table) {
            $table->string('klasifikasi_arus_kas')->nullable()->after('jenis_saldo');
            $table->index('klasifikasi_arus_kas');
        });

        // Akar kelompok akun ada di coa_groups yang berjenjang, jadi ditelusuri
        // ke atas dulu — kode akun TIDAK bisa dipakai sebagai pintasan, karena
        // tiap pesantren menomori COA-nya sendiri.
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
            $k = match ($akar($a->kode_grup)) {
                '4', '5' => 'operasi',
                '3' => 'pendanaan',
                default => null,
            };
            if ($k !== null) {
                DB::table('coa_detail')->where('kode_coa', $a->kode_coa)->update(['klasifikasi_arus_kas' => $k]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('coa_detail', function (Blueprint $table) {
            $table->dropIndex(['klasifikasi_arus_kas']);
            $table->dropColumn('klasifikasi_arus_kas');
        });
    }
};
