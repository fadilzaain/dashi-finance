<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ResetPassword extends Command
{
    protected $signature = 'keu:reset-password {email : Email akun yang di-reset}';

    protected $description = 'Mengganti password akun dengan password sementara (wajib diganti saat login berikutnya)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Email itu tidak ditemukan.');
            return self::FAILURE;
        }

        $password = $this->secret('Password sementara baru (min 10 karakter, huruf besar-kecil, angka)');

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::defaults()]]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }
            return self::FAILURE;
        }

        // Cast "hashed" di model yang meng-hash password-nya
        $user->forceFill([
            'password' => $password,
            'wajib_ganti_password' => true,
        ])->save();

        $this->info("Password {$user->email} sudah di-reset. Wajib diganti saat login berikutnya.");
        return self::SUCCESS;
    }
}