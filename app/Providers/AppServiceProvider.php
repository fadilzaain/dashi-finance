<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Di produksi semua tautan dipaksa https
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Aturan password satu tempat: minimal 10 karakter, huruf besar-kecil, angka
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());
    }
}