<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'dashi_keu';

    public function up(): void
    {
        // Tabel final: satu-satunya yang dibaca DASH-i. Hanya terisi lewat tombol Simpan.
        Schema::connection('dashi_keu')->create('keu_mutasi_bank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rekening_id')->constrained('keu_rekening_bank')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('keu_impor_batch')->restrictOnDelete();
            // unique() sebelum constrained() supaya unik menempel ke kolom. Selalu bisa ditelusuri ke baris sumber.
            $table->foreignId('baris_id')->unique()->constrained('keu_impor_baris')->restrictOnDelete();
            $table->char('kunci_unik', 64)->unique();    // pengaman utama terhadap transaksi ganda

            $table->dateTime('tanggal_transaksi');
            $table->dateTime('tanggal_efektif')->nullable();
            $table->string('nomor_referensi', 64)->nullable();
            $table->string('kanal', 100)->nullable();
            $table->text('deskripsi')->nullable();

            $table->enum('arah', ['debit', 'kredit']);
            $table->decimal('nominal', 18, 2);
            $table->decimal('saldo_setelah', 18, 2)->nullable();

            $table->timestamps();

            $table->index(['rekening_id', 'tanggal_transaksi']);
            $table->index(['arah', 'tanggal_transaksi']);
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Rollback tabel keuangan dilarang di production.');
        }

        Schema::connection('dashi_keu')->dropIfExists('keu_mutasi_bank');
    }
};