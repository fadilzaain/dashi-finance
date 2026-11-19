<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use App\Services\AuditLog;

class GantiPasswordController extends Controller
{
    public function form()
    {
        return view('akun.password');
    }

    public function simpan(Request $request)
    {
        $request->validate([
            'password_lama' => ['required', 'current_password'],
            'password'      => ['required', 'confirmed', 'different:password_lama', Password::defaults()],
        ], [
            'password_lama.required'         => 'Password lama wajib diisi.',
            'password_lama.current_password' => 'Password lama salah.',
            'password.required'              => 'Password baru wajib diisi.',
            'password.confirmed'             => 'Konfirmasi password tidak sama.',
            'password.different'             => 'Password baru harus berbeda dari password lama.',
            'password.min'                   => 'Password baru minimal 10 karakter.',
            'password.mixed'                 => 'Password baru harus memuat huruf besar dan huruf kecil.',
            'password.numbers'               => 'Password baru harus memuat angka.',
        ]);

        // Cast "hashed" di model yang meng-hash password-nya
        $request->user()->forceFill([
            'password' => $request->password,
            'wajib_ganti_password' => false,
        ])->save();

        $request->session()->regenerate();
        AuditLog::catatAtauLapor(AuditLog::GANTI_PASSWORD, $request->user());

        return redirect()->route('beranda')->with('status', 'Password berhasil diganti.');
    }
}