<?php

return [

    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [

      'smtp' => [
    'transport' => 'smtp',
    'host' => '74.125.142.108', // IP Langsung Gmail (Lebih cepat & stabil)
    'port' => env('MAIL_PORT', 2525),
    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
    'timeout' => 30,
    'local_domain' => 'bkadarsip-backend-production.up.railway.app',
    'stream' => [
        'ssl' => [
            'allow_self_signed' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ],
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