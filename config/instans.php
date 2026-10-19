<?php

/*
 * JATI DIRI INSTANS (keputusan user 20 Okt 2026, arah B): satu kode program dipasang sebagai
 * beberapa instans — ERP Al Wafi & ERP Wafi Mart — masing-masing dengan database sendiri.
 * Instans dipilih dari `.env` (INSTANS=…), BUKAN dari layar Pengaturan: jati diri tak boleh
 * tertukar karena salah klik. Bawaannya `alwafi` sehingga ERP yang sudah berjalan tak berubah.
 *
 * Modul yang dimatikan: hilang dari menu & matriks hak akses, dan alamatnya 404 — admin pun.
 * Pemeriksaannya di [[App\Support\Instans::modulHidup]], dipanggil Akses & middleware hak akses.
 */

return [
    'kode' => env('INSTANS', 'alwafi'),

    // Tambahan modul yang dimatikan lewat .env (dipisah koma) — mis. di ERP Al Wafi saat Wafi
    // Mart resmi pindah: MODUL_MATI=pos,shift-kasir,laporan-toko
    'modul_mati_tambahan' => env('MODUL_MATI', ''),

    'daftar' => [
        'alwafi' => [
            'nama' => 'Al Wafi ERP',
            'subjudul' => 'AL Wafi Islamic Boarding School',
            'logo' => 'ikon/logo.png',
            'manifest' => '/manifest.json',
            'portal_wali' => true,
            'grup_mati' => [],
            'modul_mati' => [],
            'alamat_mati' => [],
            'menu_sembunyi' => [],
            'impor_mati' => [],
            'laporan_unit_toko' => true,
            'pengaturan_santri' => true,
        ],

        'wafimart' => [
            'nama' => 'Wafi Mart ERP',
            'subjudul' => 'Wafi Mart',
            // Belum ada: komponen <x-logo> menampilkan huruf awal nama sampai berkasnya dipasang.
            'logo' => 'ikon-wafimart/logo.png',
            'manifest' => '/manifest-wafimart.json',
            // Portal wali, Midtrans, & santri hanya ada di ERP Al Wafi (penerbit dompet santri).
            'portal_wali' => false,
            'grup_mati' => ['DATA SISWA/SANTRI', 'PPSB', 'KEPENDIDIKAN', 'TAGIHAN LAIN-LAIN'],
            'modul_mati' => [
                'dashboard-ppsb', 'dashboard-kesantrian',
                'jenjang', 'tipe-biaya', 'jenis-biaya', 'tarif', 'reminder-tagihan',
                'koreksi-tagihan', 'pembebasan-registrasi',
                // Sisi dompet santri tetap milik Al Wafi; di Wafi Mart diganti penghubung API (fase 3).
                'kartu-santri', 'pencairan-kartu', 'sesi-tarik-tunai',
                // Dana terikat & pertanggungjawabannya khas yayasan nirlaba, bukan minimarket.
                'dana',
            ],
            // Rute yang tak dijaga modul tersendiri: awalan alamat (tanpa "/" depan) → 404 & hilang dari menu.
            'alamat_mati' => ['wali', 'midtrans', 'santri-manual', 'rekening-portal', 'reports/perubahan-aset-neto'],
            // Layar persediaan sudah ada di grup WAFI MART — di instans ini tak perlu kembar di KEUANGAN.
            'menu_sembunyi' => [
                'KEUANGAN|/inventory', 'KEUANGAN|/transfer-persediaan', 'KEUANGAN|/opname-persediaan',
                'KEUANGAN|/lokasi-persediaan', 'KEUANGAN|/purchase-orders', 'KEUANGAN|/penerimaan-barang',
                'KEUANGAN|/reports/persediaan',
            ],
            'impor_mati' => ['santri-lama', 'kartu-santri'],
            // Seluruh buku instans ini milik Wafi Mart: laporan biasa sudah laporan Wafi Mart,
            // jadi "Laba Rugi Wafi Mart" dkk (tersaring unit) tak perlu.
            'laporan_unit_toko' => false,
            // Isian Pengaturan Perusahaan soal dompet & jajan santri.
            'pengaturan_santri' => false,
        ],
    ],
];
