<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'dashi_keu';

    public function up(): void
    {
        Schema::connection('dashi_keu')->create('keu_impor_baris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('keu_impor_batch')->restrictOnDelete();
            $table->unsignedInteger('nomor_baris');   // urutan di file (bukan urutan waktu)
            $table->json('data_mentah');              // isi baris apa adanya dari file

            $table->dateTime('tanggal_transaksi')->nullable();
            $table->dateTime('tanggal_efektif')->nullable();
            $table->string('nomor_referensi', 64)->nullable();   // selalu teks
            $table->string('kanal', 100)->nullable();
            $table->text('deskripsi')->nullable();
            $table->decimal('debit', 18, 2)->nullable();
            $table->decimal('kredit', 18, 2)->nullable();
            $table->decimal('saldo', 18, 2)->nullable();

            $table->enum('status', ['valid', 'peringatan', 'tidak_valid', 'duplikat']);
            $table->text('pesan_validasi')->nullable();
            $table->char('kunci_unik', 64)->nullable();   // tidak UNIQUE di sini: baris duplikat justru perlu tercatat

            $table->timestamps();

            $table->unique(['batch_id', 'nomor_baris']);
            $table->index(['batch_id', 'status']);
            $table->index('kunci_unik');
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Rollback tabel keuangan dilarang di production.');
        }

        Schema::connection('dashi_keu')->dropIfExists('keu_impor_baris');
    }
};