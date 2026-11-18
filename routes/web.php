<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GantiPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'beranda' : 'login'));

// Tamu. Tidak ada pendaftaran mandiri: akun dibuat admin.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // Lapis kedua di atas pembatasan per email+IP di controller
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.post');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Semua halaman di bawah ini wajib login, akun aktif, dan sudah bukan password sementara
Route::middleware(['auth', 'aktif', 'wajib.ganti'])->group(function () {
    Route::get('/akun/password', [GantiPasswordController::class, 'form'])->name('akun.password');
    Route::put('/akun/password', [GantiPasswordController::class, 'simpan'])
        ->middleware('throttle:6,1')
        ->name('akun.password.simpan');

    Route::view('/beranda', 'beranda')->name('beranda');

    // Rute khusus admin (kelola pengguna, audit log) ditambahkan di milestone berikutnya:
    // Route::middleware('role:admin')->group(function () { ... });
});