<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BuatPengguna extends Command
{
    protected $signature = 'keu:buat-pengguna {email : Email untuk login} {--role=keuangan : admin atau keuangan}';

    protected $description = 'Membuat akun baru dengan password sementara (wajib diganti saat login pertama)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->option('role');

        if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_KEUANGAN], true)) {
            $this->error('Role harus admin atau keuangan.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('Email itu sudah terdaftar.');
            return self::FAILURE;
        }

        $nama = $this->ask('Nama lengkap');
        $password = $this->secret('Password sementara (min 10 karakter, huruf besar-kecil, angka)');

        $validator = Validator::make(
            ['email' => $email, 'nama' => $nama, 'password' => $password],
            [
                'email'    => ['required', 'email', 'max:255'],
                'nama'     => ['required', 'string', 'max:100'],
                'password' => ['required', Password::defaults()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }
            return self::FAILURE;
        }

        User::create([
            'name' => $nama,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
            'wajib_ganti_password' => true,
        ]);

        $this->info("Akun {$email} ({$role}) dibuat. Password wajib diganti saat login pertama.");
        return self::SUCCESS;
    }
}