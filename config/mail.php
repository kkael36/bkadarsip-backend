<?php

return [

    // UBAH INI: Pastikan defaultnya ke 'smtp' atau baca env
    'default' => env('MAIL_MAILER', 'smtp'), 

    'mailers' => [
        // Driver resend bisa dihapus atau biarkan saja, tapi tidak akan terpakai
        'resend' => [
            'transport' => 'resend',
        ],

        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailersend.net'), // Sesuai Mailersend
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => 30,
            // Hilangkan local_domain jika tidak perlu, biar Laravel yang handle otomatis
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp', // Ganti resend jadi smtp agar kalau gagal dia coba lagi
                'log',
            ],
        ],
    ],

    'from' => [
        // Sesuai dengan username Mailersend kamu
        'address' => env('MAIL_FROM_ADDRESS', 'dikahadip4@gmail.com'),
        'name' => env('MAIL_FROM_NAME', 'BKAD Digital Archive'),
    ],

];