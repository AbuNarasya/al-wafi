<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERBAIKAN DATA: calon yang sudah mengundurkan diri masih menyandang tagihan.
 *
 * `SantriService::mengundurkanDiri()` dulu hanya mengubah status santrinya dan
 * membiarkan tagihan registrasi / uang pangkal / perlengkapan berdiri sebagai
 * "Belum bayar". Akibatnya calon yang sudah mundur tetap muncul di dropdown
 * pembayaran & penjadwalan angsuran, dan tunggakannya ikut terhitung di rekap —
 * padahal tak ada jasa yang pernah diberikan.
 *
 * Servicenya sudah dibetulkan; migrasi ini menutup baris yang telanjur ada.
 *
 * SEMPIT dengan sengaja — hanya yang:
 *  • santrinya berstatus `mengundurkan_diri` (bukan `keluar`, yang punya jalur
 *    pembatalan sendiri berikut pembalikan akrualnya);
 *  • tagihannya masih `belum_bayar`/`sebagian` (yang `lunas` adalah jejak
 *    kuitansi, yang `batal` sudah selesai);
 *  • `sudah_akrual = false`. Yang sudah diakrualkan butuh JURNAL pembalik, dan
 *    migrasi bukan tempat menerbitkan jurnal — barisnya sengaja ditinggalkan
 *    supaya ketahuan dan bisa ditangani lewat layar.
 */
return new class extends Migration
{
    private const PERILAKU = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    public function up(): void
    {
        $id = DB::table('tagihan_santri')
            ->join('santri', 'santri.id', '=', 'tagihan_santri.id_santri')
            ->where('santri.status', 'mengundurkan_diri')
            ->whereIn('tagihan_santri.perilaku', self::PERILAKU)
            ->whereIn('tagihan_santri.status', ['belum_bayar', 'sebagian'])
            ->where('tagihan_santri.sudah_akrual', false)
            ->pluck('tagihan_santri.id');

        if ($id->isEmpty()) {
            return;
        }

        // Jejaknya ditulis di keterangan supaya baris ini bisa dibedakan dari
        // pembatalan biasa saat ada yang menelusuri belakangan.
        DB::table('tagihan_santri')->whereIn('id', $id)->update([
            'sisa' => '0',
            'status' => 'batal',
            'keterangan' => DB::raw("TRIM(BOTH ' ' FROM COALESCE(keterangan || ' · ', '') || 'Ditutup: santri mengundurkan diri')"),
            'updated_at' => now(),
        ]);

        DB::table('rencana_angsuran_uang_pangkal')
            ->whereIn('id_tagihan', $id)->where('status', 'aktif')
            ->update(['status' => 'digantikan', 'alasan' => 'Santri mengundurkan diri']);
    }

    /**
     * Tidak dibalik. Sisa & status aslinya tidak disimpan di mana pun, jadi
     * memulihkannya berarti mengarang angka — lebih buruk daripada membiarkan
     * tagihan yang memang seharusnya tertutup.
     */
    public function down(): void {}
};

