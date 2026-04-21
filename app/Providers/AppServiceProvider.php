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
        // 1. Paksa HTTPS agar tidak terjadi redirect POST ke GET (Fix 405)
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }

        // 2. Paksa Config Cloudinary (Fix Error "Undefined array key cloud")
        // Ini adalah cara paling ampuh jika file config/cloudinary.php tidak terbaca
        if (!config()->has('cloudinary.cloud')) {
            Config::set('cloudinary.cloud', [
                'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                'api_key'    => env('CLOUDINARY_API_KEY'),
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ]);
        }
    }
}