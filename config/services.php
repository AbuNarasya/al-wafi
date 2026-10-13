<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Payment gateway Midtrans (Snap) untuk Portal Wali — keputusan user 13 Okt 2026:
    | Virtual Account SAJA (biayanya flat), biaya ditanggung wali.
    |
    | `biaya_va` = tarif VA Midtrans TERMASUK PPN (Rp 4.000 + 11%). Ikuti tarif di
    | akun Midtrans; yang dipungut dari wali harus sama dengan yang dipotong
    | Midtrans, kalau tidak saldo akun titipan biaya admin tak kembali ke nol.
    */
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        'biaya_va' => (int) env('MIDTRANS_BIAYA_VA', 4440),
        'kedaluwarsa_jam' => (int) env('MIDTRANS_KEDALUWARSA_JAM', 24),
        // Rekening Midtrans (kas/rekening tersendiri) & titipan biaya admin dari wali.
        'kode_rekening' => env('MIDTRANS_KODE_REKENING', '1.1.01.003'),
        'akun_biaya' => env('MIDTRANS_AKUN_BIAYA', '2.1.01.010'),
        'bank_va' => ['bca_va', 'bni_va', 'bri_va', 'echannel', 'permata_va', 'bsi_va', 'cimb_va'],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
