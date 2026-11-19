<?php

namespace App\Providers;

use App\Services\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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

        // Audit login/logout lewat event bawaan Laravel: tercatat di mana pun autentikasi terjadi,
        // tanpa menyentuh controller. Sengaja hanya email yang dicatat, tidak pernah password.
        Event::listen(Login::class, fn (Login $e) => AuditLog::catatAtauLapor(AuditLog::LOGIN, pengguna: $e->user));
        Event::listen(Logout::class, fn (Logout $e) => $e->user
            ? AuditLog::catatAtauLapor(AuditLog::LOGOUT, pengguna: $e->user)
            : null);
        Event::listen(Failed::class, fn (Failed $e) => AuditLog::catatAtauLapor(
            AuditLog::LOGIN_GAGAL,
            keterangan: 'Email: '.($e->credentials['email'] ?? '-'),
            pengguna: $e->user,
        ));
    }
}