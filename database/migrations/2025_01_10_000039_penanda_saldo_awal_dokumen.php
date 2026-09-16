<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENANDA `saldo_awal` untuk SELURUH dokumen pindahan sistem.
 *
 * Melanjutkan `tagihan_santri.saldo_awal` (migrasi …000038) ke lima tabel lain.
 * Artinya satu kalimat di semua tempat: **dokumen ini tak pernah berjurnal.**
 * Nilainya masuk buku besar lewat jurnal pembuka di menu Saldo Awal, bukan lewat
 * dokumennya sendiri.
 *
 * Dua hal bergantung pada penanda ini:
 *   1. Tombol ubah/hapus TANPA jurnal — hanya sah pada baris yang memang tak
 *      punya jurnal untuk dibalik.
 *   2. Baris turunan di menu Saldo Awal — jumlah per akun dihitung dari sini,
 *      supaya buku besar mengikuti sendiri apa yang sudah dicatat.
 *
 * Aditif & berdefault: seluruh baris lama tetap sah apa adanya.
 *
 * TENTANG BACKFILL. Arah amannya SELALU `false`. Salah menandai `true` berarti
 * sebuah dokumen yang jurnalnya sudah ada ikut dihitung lagi di jurnal pembuka —
 * angkanya dobel, dan tombol hapus-tanpa-jurnal muncul di tempat yang salah.
 * Salah menandai `false` hanya berarti barisnya tak ikut baris turunan, persis
 * seperti keadaan sebelum migrasi ini. Karena itu tiap tabel dibackfill dengan
 * syarat yang PASTI, bukan yang kira-kira.
 */
return new class extends Migration
{
    /** Tabel → apakah perlu kolomnya (sebagian mungkin belum ada di pemasangan lama). */
    private const TABEL = [
        'accrues',
        'operational_advances',
        'pengajuan_pembayaran',
        'bank_loans',
        'pinjaman_karyawan',
        'assets',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (Schema::hasTable($tabel) && ! Schema::hasColumn($tabel, 'saldo_awal')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->boolean('saldo_awal')->default(false);
                });
            }
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (Schema::hasTable($tabel) && Schema::hasColumn($tabel, 'saldo_awal')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->dropColumn('saldo_awal');
                });
            }
        }
    }

    private function backfill(): void
    {
        // ACCRUE — impor memanggil AccrueService dengan `tanpa_jurnal`, dan itu
        // satu-satunya cara sebuah accrue berdiri tanpa jurnal. Jadi "tak punya
        // jurnal bersumber Accrue" identik dengan "saldo awal".
        DB::statement("
            UPDATE accrues SET saldo_awal = true
            WHERE NOT EXISTS (
                SELECT 1 FROM journal_entries je
                WHERE je.sumber_modul = 'Accrue' AND je.id_sumber = accrues.id_accrue::text
            )");

        // UANG MUKA OPERASIONAL — registerOutstanding() memberi nomor UMP- dan
        // tak menjurnal. Ia dipakai DUA pihak: impor saldo awal, dan alur
        // Pengajuan Pembayaran. Yang dari pengajuan membawa `id_pengajuan_sumber`
        // dan jurnalnya milik Kas Keluar — itu yang memisahkan keduanya.
        DB::statement("
            UPDATE operational_advances SET saldo_awal = true
            WHERE nomor_ref LIKE 'UMP-%' AND id_pengajuan_sumber IS NULL");

        // PENGAJUAN PEMBAYARAN — jalur normal menetapkan status `diposting`
        // BERSAMAAN dengan `journal_entry_id` dalam satu update. Berstatus
        // diposting tetapi tanpa id jurnal karena itu hanya mungkin lahir dari
        // impor, yang menulis barisnya langsung.
        DB::statement("
            UPDATE pengajuan_pembayaran SET saldo_awal = true
            WHERE status = 'diposting' AND journal_entry_id IS NULL");

        // ASET TETAP — pencatatan aset TIDAK pernah menjurnal (yang menjurnal
        // hanya depresiasi bulanan), jadi "tak punya jurnal" bukan pembeda di
        // sini: aset yang dibeli lewat Kas Keluar pun barisnya tak berjurnal,
        // sementara nilainya SUDAH masuk buku besar dari sisi pembayarannya.
        // Satu-satunya penanda yang pasti adalah jejak impornya sendiri.
        DB::statement("
            UPDATE assets SET saldo_awal = true
            WHERE sumber_ref = 'impor-saldo-awal'");

        // PEMBIAYAAN BANK & PINJAMAN KARYAWAN — keduanya sudah lama punya opsi
        // `posting_pencairan`; melepas centangnya berarti uangnya cair sebelum
        // pindah sistem. Yang dipakai di sini: tak ada jurnal SAMA SEKALI yang
        // bersumber dari dokumen itu.
        //
        // Sengaja seketat itu. Pinjaman saldo awal yang SUDAH pernah dicicil
        // punya jurnal angsuran, sehingga tak ikut tertandai — dan itu memang
        // pilihan yang diambil: ia hanya tak muncul di baris turunan, persis
        // seperti sebelum migrasi ini. Menandainya butuh mata manusia, bukan
        // tebakan migrasi.
        DB::statement("
            UPDATE bank_loans SET saldo_awal = true
            WHERE NOT EXISTS (
                SELECT 1 FROM journal_entries je
                WHERE je.sumber_modul = 'PinjamanBank' AND je.id_sumber = bank_loans.id::text
            )");

        DB::statement("
            UPDATE pinjaman_karyawan SET saldo_awal = true
            WHERE NOT EXISTS (
                SELECT 1 FROM journal_entries je
                WHERE je.sumber_modul = 'PinjamanKaryawan' AND je.id_sumber = pinjaman_karyawan.id::text
            )");
    }
};
