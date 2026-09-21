<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kunci VAPID
    |--------------------------------------------------------------------------
    |
    | Dibangkitkan SEKALI dengan `php artisan push:kunci`, lalu ditaruh di .env
    | masing-masing lingkungan. Kunci privat TIDAK BOLEH ikut ter-commit.
    |
    | Mengganti pasangan kunci ini MEMATIKAN seluruh langganan yang sudah ada —
    | alamat langganan di peramban terikat pada kunci publik yang dipakai saat
    | mendaftar. Staf harus menekan "Nyalakan" lagi satu per satu, dan tak ada
    | pemberitahuan apa pun yang memberi tahu mereka.
    |
    | `subject` wajib berupa URL atau mailto: — layanan push memakainya untuk
    | menghubungi pemilik aplikasi bila pengirimannya bermasalah.
    |
    */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'https://erp.al-wafi.sch.id'),
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Umur notifikasi yang masih layak didorong (menit)
    |--------------------------------------------------------------------------
    |
    | Penjaga saat fitur ini pertama dinyalakan, dan saat cron sempat mati
    | berhari-hari. Tanpa batas ini, sapuan pertama akan mendorong SELURUH
    | tugas lama yang belum tersentuh sekaligus — puluhan notifikasi meledak di
    | ponsel staf dalam semenit, dan izinnya dicabut hari itu juga.
    |
    | Yang lebih tua dari ini tetap ditandai "sudah didorong" supaya ia keluar
    | dari antrean, hanya saja tak benar-benar dikirim.
    |
    */
    'umur_maksimal_menit' => (int) env('PUSH_UMUR_MAKSIMAL', 120),

    /*
    |--------------------------------------------------------------------------
    | Berapa notifikasi disapu sekali jalan
    |--------------------------------------------------------------------------
    |
    | Perintahnya berjalan tiap menit, jadi batas ini bukan pembatas laju
    | melainkan penjaga supaya satu lonjakan tak menahan prosesnya berlama-lama.
    |
    */
    'batas_sapuan' => (int) env('PUSH_BATAS_SAPUAN', 200),

    /*
    |--------------------------------------------------------------------------
    | TTL — berapa lama layanan push menyimpan pesan bila perangkatnya mati
    |--------------------------------------------------------------------------
    |
    | 12 jam: notifikasi tugas yang baru sampai keesokan harinya sudah kehilangan
    | gunanya, dan lebih baik tak muncul sama sekali daripada muncul terlambat.
    |
    */
    'ttl' => (int) env('PUSH_TTL', 43200),
];
