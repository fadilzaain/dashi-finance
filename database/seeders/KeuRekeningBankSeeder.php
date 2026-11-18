<?php

namespace Database\Seeders;

use App\Models\KeuRekeningBank;
use Illuminate\Database\Seeder;

class KeuRekeningBankSeeder extends Seeder
{
    // Aman dijalankan berulang: rekening dicari dari nomornya, tidak dobel.
    public function run(): void
    {
        $daftar = [
            ['kode' => 'BNI-001', 'bank' => 'BNI', 'nomor_rekening' => '400986417',  'nama' => 'RSUD JOMBANG PEMERINTAH'],
            ['kode' => 'BPD-001', 'bank' => 'BPD', 'nomor_rekening' => '0111009133', 'nama' => 'RSUD JOMBANG'],
        ];

        foreach ($daftar as $rekening) {
            // Nomor rekening sengaja string supaya nol di depan (0111009133) tidak hilang
            KeuRekeningBank::updateOrCreate(
                ['nomor_rekening' => $rekening['nomor_rekening']],
                $rekening + ['aktif' => true]
            );
        }
    }
}