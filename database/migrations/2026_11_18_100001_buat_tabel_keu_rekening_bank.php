<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tabel keu_* ada di database "dashi" lewat koneksi strict dashi_keu
    protected $connection = 'dashi_keu';

    public function up(): void
    {
        Schema::connection('dashi_keu')->create('keu_rekening_bank', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();
            $table->string('bank', 10);                        // BNI / BPD (rekening lain menyusul)
            $table->string('nomor_rekening', 30)->unique();    // teks, bukan angka: nol di depan tidak boleh hilang
            $table->string('nama', 100);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Rollback tabel keuangan dilarang di production.');
        }

        Schema::connection('dashi_keu')->dropIfExists('keu_rekening_bank');
    }
};