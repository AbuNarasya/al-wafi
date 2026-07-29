<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERILAKU KE-5: `perlengkapan` — biaya perlengkapan santri baru, ditagihkan
 * BERSAMA uang pangkal tetapi berdiri sendiri.
 *
 * Kenapa perilaku baru, bukan menumpang yang sudah ada:
 *  • perilaku `lain` membuat pembayarannya jatuh ke modul KESANTRIAN (lihat
 *    PembayaranSantriService::TIPE_LINGKUP) — PPSB yang menerbitkan tagihannya
 *    justru tak boleh menerima uangnya, dan tagihannya nongol di Tagihan Lain.
 *  • perilaku `uang_pangkal` membuat seluruh kueri `->first()` uang pangkal
 *    bimbang antara dua tagihan, dan penjaga "uang pangkal sudah pernah
 *    ditagihkan" akan menolak penerbitan yang kedua.
 *
 * Yang membedakannya dari uang pangkal: POTONGAN GELOMBANG TIDAK BERLAKU.
 * Potongan memotong uang pangkal saja, sehingga ambang 50%-nya pun tetap
 * dihitung dari uang pangkal — itulah alasan keduanya tak boleh dilebur jadi
 * satu baris tagihan.
 */
return new class extends Migration
{
    private const PERILAKU_BARU = ['registrasi', 'uang_pangkal', 'perlengkapan', 'spp', 'lain'];

    private const PERILAKU_LAMA = ['registrasi', 'uang_pangkal', 'spp', 'lain'];

    public function up(): void
    {
        $this->pasangCek(self::PERILAKU_BARU);

        // Baris bawaan: tak boleh dihapus & perilakunya terkunci, sama seperti
        // keempat tipe lain. Dibuat lewat updateOrInsert agar migration ini aman
        // dijalankan di basis data yang barisnya sudah terlanjur ada.
        $now = now();
        DB::table('tipe_biaya')->updateOrInsert(
            ['kode' => 'perlengkapan'],
            [
                'nama' => 'Biaya Perlengkapan',
                'perilaku' => 'perlengkapan',
                'urutan' => 3,
                'bawaan' => true,
                'status' => 'aktif',
                'keterangan' => 'Ditagihkan bersama uang pangkal setelah calon lulus, tetapi TIDAK dipotong potongan gelombang dan punya jadwal termin sendiri.',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        // SPP & Lain-lain digeser agar urutannya di dropdown tetap masuk akal.
        DB::table('tipe_biaya')->where('kode', 'spp')->where('bawaan', true)->update(['urutan' => 4]);
        DB::table('tipe_biaya')->where('kode', 'lain')->where('bawaan', true)->update(['urutan' => 5]);
    }

    public function down(): void
    {
        // Sengaja TIDAK memaksa: bila sudah ada jenis biaya yang memakainya,
        // kunci asing akan menolak dan itu memang yang seharusnya terjadi —
        // menghapusnya diam-diam akan meninggalkan tagihan tanpa induk.
        DB::table('tipe_biaya')->where('kode', 'perlengkapan')->delete();
        DB::table('tipe_biaya')->where('kode', 'spp')->where('bawaan', true)->update(['urutan' => 3]);
        DB::table('tipe_biaya')->where('kode', 'lain')->where('bawaan', true)->update(['urutan' => 4]);

        $this->pasangCek(self::PERILAKU_LAMA);
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK; nilainya diganti dengan memasang ulang. */
    private function pasangCek(array $nilai): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        DB::statement('ALTER TABLE tipe_biaya DROP CONSTRAINT IF EXISTS tipe_biaya_perilaku_check');
        DB::statement("ALTER TABLE tipe_biaya ADD CONSTRAINT tipe_biaya_perilaku_check CHECK (perilaku IN ({$daftar}))");
    }
};
