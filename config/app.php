<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | PWA (pasang ke layar utama)
    |--------------------------------------------------------------------------
    |
    | Tombol darurat dari sisi server. Bila diisi false, halaman tak hanya
    | berhenti mendaftarkan pekerja layanan — ia MENCABUT yang sudah terpasang
    | di ponsel saat halaman berikutnya dibuka. Jadi satu deploy cukup untuk
    | mematikannya di semua perangkat, tanpa menyentuh ponsel siapa pun.
    |
    */

    'pwa_aktif' => (bool) env('PWA_AKTIF', true),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions.
    |
    | ASIA/JAKARTA, bukan UTC bawaan Laravel. Seluruh pemakai aplikasi ini
    | berada di satu zona waktu, dan setiap angka waktu yang mereka lihat atau
    | tentukan berarti WIB: jam pengiriman reminder, tanggal jatuh tempo,
    | stempel waktu pada bukti pembayaran, dan hitungan "lewat N hari".
    |
    | Dengan UTC, `dailyAt('07:00')` pada pengaturan reminder mengirim pukul
    | 14:00 WIB — tujuh jam dari yang dimaksud petugas yang mengetiknya. Dan
    | pergantian hari untuk perhitungan jatuh tempo terjadi pukul 07:00 pagi,
    | bukan tengah malam.
    |
    | Diubah pada 19 Sep 2026, saat database transaksi masih KOSONG. Itu
    | disengaja: sesudah ada ribuan tagihan & jurnal, mengubahnya melahirkan
    | satu kolom berisi dua zona waktu yang tak bisa dipisahkan lagi tanpa
    | menebak baris mana milik zona mana.
    |
    | Lewat env supaya lingkungan lain (mis. pemeriksaan lintas zona) bisa
    | menimpanya tanpa menyunting berkas ini.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
