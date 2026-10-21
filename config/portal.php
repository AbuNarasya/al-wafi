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
];
