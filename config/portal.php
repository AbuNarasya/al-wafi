<?php

/*
 * DOMAIN PORTAL WALI (keputusan user 21 Okt 2026): portal wali dibuka dari alamatnya sendiri,
 * mis. https://parents.al-wafi.sch.id — satu aplikasi & satu database dengan ERP, hanya alamatnya beda.
 *
 *  • Di domain ini HANYA halaman portal (/wali/…) yang terbuka; "/" → /wali/, sisanya 404 (layar staf tak bisa
 *    dibuka dari alamat wali).
 *  • /wali/… di domain lain (ERP staf) dialihkan permanen ke sini — link verifikasi WA yang sudah terkirim tetap jalan.
 *  • Link portal yang dibuat dari sisi staf (link verifikasi WA) memakai domain ini ([[App\Support\AlamatPortal]]).
 * Kosong = portal tetap di domain ERP seperti sebelumnya (laptop, test, dev.erp).
 */
return [
    'domain' => env('PORTAL_DOMAIN', ''),

    // MASA DEMO (keputusan user 12 Nov 2026): portal resmi dipakai mulai tanggal ini. Sebelumnya, wali yang masuk
    // melihat pengumuman "masih demo" (jendela sekali per sesi + pita di tiap halaman) — tanggalnya SENGAJA tak
    // disebut ke wali (keputusan user). Kosong = pengumuman mati.
    'demo_sampai' => env('PORTAL_DEMO_SAMPAI', '2027-01-01'),

    // MODE TERBATAS (keputusan user 12 Nov 2026) — saklar MANUAL: wali biasa hanya Data Wali & Santri, berkas,
    // akun, & alur PPSB; keluarga bertanda akun uji tetap bisa semua. Lihat App\Services\PortalWali\PortalTerbatas.
    'terbatas' => (bool) env('PORTAL_TERBATAS', true),
];
