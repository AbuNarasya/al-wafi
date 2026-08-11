<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk penyimpanan lampiran dokumen
    |--------------------------------------------------------------------------
    |
    | Nama disk (lihat config/filesystems.php) tempat ISI berkas lampiran
    | disimpan. Bawaannya `local` — folder di luar webroot, jadi berkasnya tak
    | pernah bisa diambil orang tanpa melewati pemeriksaan hak akses.
    |
    | PERINGATAN PRODUKSI: Render paket gratis TIDAK punya disk permanen. Selama
    | disk-nya masih `local` di sana, berkas yang diunggah HILANG setiap server
    | restart atau bangun dari tidur. Pindah ke object storage cukup dengan
    | mengisi LAMPIRAN_DISK=s3 beserta kredensial AWS_* di .env — tanpa menyentuh
    | kode. Baris lampiran menyimpan nama disknya masing-masing, jadi berkas lama
    | tetap terbaca dari tempat lamanya dan tak perlu dipindahkan serentak.
    |
    */

    'disk' => env('LAMPIRAN_DISK', 'local'),

    /*
    | Folder di dalam disk tersebut.
    */

    'folder' => env('LAMPIRAN_FOLDER', 'lampiran-dokumen'),

];
