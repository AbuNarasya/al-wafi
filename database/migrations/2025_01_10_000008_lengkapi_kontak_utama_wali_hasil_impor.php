<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERBAIKAN DATA: wali hasil impor Santri Lama tak bisa disunting.
 *
 * `wali.nama` & `wali.telepon` adalah SALINAN kontak utama, bukan isian
 * tersendiri (lihat WaliService::salinKontakUtama). Pemeta impor Santri Lama
 * dulu hanya menulis kedua salinan itu dan membiarkan `nama_ayah`/`telepon_ayah`
 * kosong. Akibatnya halaman sunting wali TERBUKA — namanya pun terbaca jelas —
 * tetapi menyimpannya selalu ditolak "Kontak utama belum lengkap (nama & telepon
 * wajib diisi)", dan petugas tak punya petunjuk apa yang kurang.
 *
 * Pemetanya sudah dibetulkan; migrasi ini membereskan baris yang sudah masuk.
 *
 * SEMPIT dengan sengaja: hanya baris yang KETIGA nama perannya kosong (jadi
 * pasti bukan hasil pengisian lewat form) dan yang `nama`/`telepon`-nya ada
 * isinya. Nilai yang ditulis pun bukan karangan — disalin dari kolom yang sudah
 * ada di baris yang sama, jadi tak ada informasi baru yang diciptakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wali')
            ->whereNull('nama_ayah')->whereNull('nama_ibu')->whereNull('nama_wali')
            ->whereNotNull('nama')->whereNotNull('telepon')
            ->where('nama', '!=', '')->where('telepon', '!=', '')
            ->update([
                'kontak_utama' => 'ayah',
                'nama_ayah' => DB::raw('nama'),
                'telepon_ayah' => DB::raw('telepon'),
            ]);
    }

    /**
     * Dibalik hanya untuk baris yang PERSIS masih berupa salinan itu — kalau
     * petugas sudah menyunting salah satunya, isiannya ditinggalkan.
     */
    public function down(): void
    {
        DB::table('wali')
            ->whereNull('nama_ibu')->whereNull('nama_wali')
            ->whereColumn('nama_ayah', 'nama')->whereColumn('telepon_ayah', 'telepon')
            ->update(['nama_ayah' => null, 'telepon_ayah' => null]);
    }
};
