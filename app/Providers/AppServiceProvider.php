<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL; // WAJIB ADA
use Illuminate\Support\Facades\Config; // WAJIB ADA

class AppServiceProvider extends ServiceProvider
{
    public function register(): void { }

   public function boot(): void
{
    // 1. Paksa HTTPS (Penting agar tidak 405)
    if (config('app.env') !== 'local') {
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    // 2. JURUS PAMUNGKAS: Paksa Config Cloudinary ke dalam sistem
    // Kita tidak pakai env() di sini agar datanya 'mati' terkunci di sistem
    config([
        'cloudinary.cloud' => [
            'cloud_name' => 'dswy4tagj',
            'api_key'    => '877393947668591',
            'api_secret' => 'h-EXj0-IhNHx2zKBuNXVwNbPeWI',
        ],
        'cloudinary.cloud_url' => 'cloudinary://877393947668591:h-EXj0-IhNHx2zKBuNXVwNbPeWI@dswy4tagj',
    ]);
}
}