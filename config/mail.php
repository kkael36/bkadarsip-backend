<?php

return [

    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
    'transport' => 'smtp',
    'host' => env('MAIL_HOST', 'smtp.gmail.com'),
    'port' => env('MAIL_PORT', 587),
    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
    'timeout' => 60,
    // TAMBAHKAN INI DI BAWAH TIMEOUT
    'stream' => [
        'ssl' => [
            'allow_self_signed' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ],

           'local_domain' => env('MAIL_EHLO_DOMAIN', 'bkadarsip-backend-production.up.railway.app'),
        ],

        // Mailer lainnya biarkan standar...
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'dikahadip4@gmail.com'),
        'name' => env('MAIL_FROM_NAME', 'BKAD Digital Archive'),
    ],

];