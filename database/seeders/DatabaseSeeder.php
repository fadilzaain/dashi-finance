<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Tidak boleh ada akun bawaan/contoh di aplikasi keuangan.
    // Akun dibuat lewat `php artisan keu:buat-pengguna` (lihat BuatPengguna).
    public function run(): void
    {
        $this->call(KeuRekeningBankSeeder::class);
    }
}