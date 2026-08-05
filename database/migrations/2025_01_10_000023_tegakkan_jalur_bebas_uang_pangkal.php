<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jalur bertanda "bebas uang pangkal" benar-benar dibebaskan.
 *
 * Penandanya sudah ada sejak lama di master Jalur, dan layar Tarif pun
 * mematikan selnya — tetapi tak ada satu pun jalur kode yang memeriksanya saat
 * menagih (`JalurPendaftaran::bebasUangPangkal()` tak pernah dipanggil, walau
 * dokumentasinya mengaku dipakai di dua tempat). Akibatnya jalur 004 "Lanjutan
 * (OSS)" menagih 25–50 juta di dua jenjang, padahal ditandai bebas — dan tak
 * ada yang tahu, karena di layar selnya tampak nonaktif.
 *
 * Migrasi ini merapikan DATA-nya; penegakannya sendiri ada di
 * TarifService::cari(), supaya penyimpangan yang sama tak bisa lahir lagi.
 *
 * Hanya menyentuh uang pangkal: penanda ini memang cuma tentang itu. Registrasi
 * & perlengkapan jalur tersebut tetap seperti apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bertanda = DB::table('jalur_pendaftaran')->where('bebas_uang_pangkal', true)->pluck('kode');
        if ($bertanda->isEmpty()) {
            return;
        }

        DB::table('tarif_biaya')
            ->where('perilaku', 'uang_pangkal')
            ->whereIn('kode_jalur', $bertanda)
            ->where('bebas', false)
            ->update(['nominal' => null, 'bebas' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Tak dipulihkan: nominal yang dulu tertulis di sel itu justru angka
        // yang tak pernah seharusnya ditagih. Mengembalikannya berarti
        // menghidupkan lagi tagihan yang salah.
    }
};
