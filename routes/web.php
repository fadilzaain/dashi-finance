<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GantiPasswordController;
use App\Http\Controllers\PindahDanaController;
use App\Http\Controllers\UnggahMutasiController;
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

    // Unggah mutasi bank: admin dan keuangan. {batch} memakai kode_batch (UUID), bukan id berurutan.
    Route::middleware('role:admin,keuangan')->prefix('unggah')->name('unggah.')->group(function () {
        Route::get('/', [UnggahMutasiController::class, 'index'])->name('form');
        Route::post('/', [UnggahMutasiController::class, 'store'])->middleware('throttle:10,1')->name('simpan');
        Route::get('/{batch}', [UnggahMutasiController::class, 'tampil'])->whereUuid('batch')->name('tampil');

        // Memindahkan ke data final / membuang batch. Dibatasi lebih ketat dari unggah biasa.
        Route::post('/{batch}/simpan', [UnggahMutasiController::class, 'simpanKeFinal'])
            ->whereUuid('batch')->middleware('throttle:6,1')->name('setujui');
        Route::post('/{batch}/batalkan', [UnggahMutasiController::class, 'batalkan'])
            ->whereUuid('batch')->middleware('throttle:6,1')->name('batalkan');
    });

        // Pindah dana antar rekening RS sendiri: admin dan keuangan
    Route::middleware('role:admin,keuangan')->prefix('pindah-dana')->name('pindah.')->group(function () {
        Route::get('/', [PindahDanaController::class, 'index'])->name('index');
        Route::post('/cari', [PindahDanaController::class, 'cari'])->middleware('throttle:6,1')->name('cari');
        Route::post('/konfirmasi-massal', [PindahDanaController::class, 'konfirmasiMassal'])->middleware('throttle:6,1')->name('massal');

        Route::post('/{transfer}/setujui', [PindahDanaController::class, 'setujui'])->whereNumber('transfer')->middleware('throttle:30,1')->name('setujui');
        Route::post('/{transfer}/tolak', [PindahDanaController::class, 'tolak'])->whereNumber('transfer')->middleware('throttle:30,1')->name('tolak');
        Route::post('/{transfer}/kembalikan', [PindahDanaController::class, 'kembalikan'])->whereNumber('transfer')->middleware('throttle:30,1')->name('kembalikan');
    });

    // Khusus admin
    Route::middleware('role:admin')->group(function () {
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        // Kelola pengguna ditambahkan di milestone berikutnya
    });
});