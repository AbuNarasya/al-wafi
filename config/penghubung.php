<?php

/*
 * PENGHUBUNG DOMPET SANTRI ↔ WAFI MART (Fase 3, keputusan user 20 Okt 2026).
 *
 * Kunci rahasia yang SAMA dipasang di .env kedua instans:
 *   ERP Al Wafi (pemilik dompet, MENERIMA permintaan) : PENGHUBUNG_TOKO_RAHASIA=…
 *   ERP Wafi Mart (kasir, MENGIRIM permintaan)        : PENGHUBUNG_TOKO_RAHASIA=…  + PENGHUBUNG_TOKO_URL=https://erp.al-wafi.sch.id
 * Kunci kosong = penghubung MATI (API menjawab 404).
 */
return [
    'rahasia' => env('PENGHUBUNG_TOKO_RAHASIA', ''),

    // Alamat ERP Al Wafi yang dihubungi kasir Wafi Mart.
    'url' => env('PENGHUBUNG_TOKO_URL', ''),

    // Selisih waktu maksimum (detik) antara tanda tangan & jam server — penangkal pemutaran ulang.
    'toleransi_detik' => (int) env('PENGHUBUNG_TOKO_TOLERANSI', 300),

    // Alamat IP pengirim yang diterima (dipisah koma). Kosong = tak dibatasi IP (tetap wajib tanda tangan).
    'ip' => env('PENGHUBUNG_TOKO_IP', ''),
];
