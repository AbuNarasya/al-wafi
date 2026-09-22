<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERAN LEVEL PENGAJUAN — supaya jumlah levelnya bisa disesuaikan pesantren.
 *
 * Rantai persetujuannya sendiri sudah lama berupa data (`approval_flows` +
 * `approval_steps`). Yang terkunci adalah DAFTAR PERINGKATNYA: masternya tanpa
 * Tambah/Hapus, dan selusin titik di kode menyebut angkanya sebagai aturan —
 * "hanya peringkat 4 yang boleh mengajukan", "peringkat 1 melihat semua". Level
 * kelima karena itu bisa dibuat tetapi tak akan pernah berfungsi: orang di
 * dalamnya tak boleh mengajukan apa pun dan tak menyetujui apa pun.
 *
 * Empat tanda ini memindahkan aturan itu dari ANGKA ke PERAN. Peringkat tinggal
 * menjadi urutan (1 = tertinggi), persis seperti maksud aslinya.
 *
 * Bawaannya false semua: level yang baru dibuat adalah anak tangga penyetuju
 * biasa, dan wewenang yang lebih dari itu harus dinyalakan dengan sengaja.
 * Keempat level yang sudah ada diisi persis seperti perilakunya selama ini,
 * sehingga tak satu pun pesantren merasakan perubahan sampai ia sendiri
 * mengubahnya.
 */
return new class extends Migration
{
    /** Perilaku yang selama ini dipaku di kode, per peringkat bawaan. */
    private const KLASIK = [
        // 1 Ketua Yayasan — tak mengajukan, melihat seluruh yayasan.
        1 => ['lingkup_semua' => true],
        // 2 Mudir Umum — murni penyetuju; tak punya wewenang tambahan.
        2 => [],
        // 3 Mudir Bagian — boleh mengajukan ANGGARAN (bukan pembayaran), dan
        //   seluruh pekerjaannya terikat pada bagiannya sendiri.
        3 => ['boleh_ajukan_anggaran' => true, 'terikat_bagian' => true],
        // 4 Staff — pemohon: pembayaran maupun anggaran, dalam bagiannya.
        4 => ['boleh_ajukan_pembayaran' => true, 'boleh_ajukan_anggaran' => true, 'terikat_bagian' => true],
    ];

    public function up(): void
    {
        Schema::table('level_pengajuan', function (Blueprint $table) {
            $table->boolean('boleh_ajukan_pembayaran')->default(false);
            $table->boolean('boleh_ajukan_anggaran')->default(false);
            // Wajib ditempatkan di sebuah Bagian, DAN datanya terbatas pada
            // bagian itu. Keduanya satu tanda karena memang satu gagasan: level
            // ini bekerja atas nama satu bagian, bukan atas nama yayasan.
            $table->boolean('terikat_bagian')->default(false);
            $table->boolean('lingkup_semua')->default(false);
        });

        foreach (self::KLASIK as $peringkat => $peran) {
            if ($peran !== []) {
                DB::table('level_pengajuan')->where('peringkat', $peringkat)->update($peran);
            }
        }
    }

    public function down(): void
    {
        Schema::table('level_pengajuan', function (Blueprint $table) {
            $table->dropColumn([
                'boleh_ajukan_pembayaran', 'boleh_ajukan_anggaran',
                'terikat_bagian', 'lingkup_semua',
            ]);
        });
    }
};
