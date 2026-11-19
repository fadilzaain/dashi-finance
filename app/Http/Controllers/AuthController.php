<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Services\AuditLog;

class AuthController extends Controller
{
    private const MAKS_PERCOBAAN = 5;
    private const DETIK_KUNCI = 300; // 5 menit

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required'    => 'Email wajib diisi.',
            'email.email'       => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ]);

        // Pembatasan per kombinasi email + IP: tebak-tebakan password kena kunci 5 menit
        $kunci = Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PERCOBAAN)) {
            $detik = RateLimiter::availableIn($kunci);
            AuditLog::catatAtauLapor(AuditLog::LOGIN_TERKUNCI, keterangan: 'Email: '.$data['email']);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik."]);
        }

        // Hanya akun berstatus aktif yang bisa masuk (tanpa fitur "ingat saya")
        if (Auth::attempt($data + ['status' => User::STATUS_AKTIF])) {
            RateLimiter::clear($kunci);
            $request->session()->regenerate(); // cegah session fixation

            return redirect()->intended(route('beranda'));
        }

        RateLimiter::hit($kunci, self::DETIK_KUNCI);

        // Pesan sengaja sama untuk semua kegagalan supaya tidak membocorkan email mana yang terdaftar
        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Email atau password salah.']);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}