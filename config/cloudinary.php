<?php

return [
    'cloud_url' => env('CLOUDINARY_URL'),

    'cloud' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME', 'dswy4tagj'),
        'api_key'    => env('CLOUDINARY_API_KEY', '877393947668591'),
        'api_secret' => env('CLOUDINARY_API_SECRET', 'h-EXj0-IhNHx2zKBuNXVwNbPeWI'),
    ],

    'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET'),
];