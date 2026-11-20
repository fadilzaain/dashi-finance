<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'dashi_keu';

    public function up(): void
    {
        // Pasangan pindah dana antar rekening milik RS sendiri. keu_mutasi_bank TIDAK diubah.
        Schema::connection('dashi_keu')->create('keu_transfer_internal', function (Blueprint $table) {
            $table->id();

            // UNIQUE: satu mutasi hanya boleh berada di satu pasangan (unique() sebelum constrained())
            $table->foreignId('debit_mutasi_id')->unique()->constrained('keu_mutasi_bank')->restrictOnDelete();
            $table->foreignId('kredit_mutasi_id')->unique()->constrained('keu_mutasi_bank')->restrictOnDelete();

            $table->enum('jenis', ['pasti', 'kelompok']);
            $table->integer('selisih_detik');   // waktu kredit dikurangi waktu debit

            // needs_review = menunggu konfirmasi, matched = terkonfirmasi, rejected = bukan pindah dana
            $table->enum('status', ['needs_review', 'matched', 'rejected'])->default('needs_review');

            // id pengguna di aplikasi input (beda database, jadi tanpa foreign key)
            $table->unsignedBigInteger('dibuat_oleh');
            $table->unsignedBigInteger('diputuskan_oleh')->nullable();
            $table->timestamp('diputuskan_pada')->nullable();

            $table->timestamps();

            $table->index(['status', 'jenis']);
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Rollback tabel keuangan dilarang di production.');
        }

        Schema::connection('dashi_keu')->dropIfExists('keu_transfer_internal');
    }
};