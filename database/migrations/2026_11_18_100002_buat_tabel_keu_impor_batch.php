<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'dashi_keu';

    public function up(): void
    {
        Schema::connection('dashi_keu')->create('keu_impor_batch', function (Blueprint $table) {
            $table->id();
            $table->uuid('kode_batch')->unique();   // dipakai di URL, supaya id berurutan tidak terbuka
            $table->foreignId('rekening_id')->constrained('keu_rekening_bank')->restrictOnDelete();

            // File sumber (disimpan di storage privat dengan nama acak)
            $table->string('nama_file_asli', 255);
            $table->string('path_file', 255);
            $table->string('format_file', 10);      // xls / xlsx / csv
            $table->unsignedBigInteger('ukuran_byte');
            $table->char('hash_sha256', 64);        // sidik jari file, untuk mendeteksi unggah ulang

            $table->date('periode_awal')->nullable();
            $table->date('periode_akhir')->nullable();

            // Angka yang tertulis di file (header/footer). Kosong bila file tidak memuatnya (mis. CSV BNI).
            $table->decimal('saldo_awal_file', 18, 2)->nullable();
            $table->decimal('saldo_akhir_file', 18, 2)->nullable();
            $table->decimal('total_debit_file', 18, 2)->nullable();
            $table->decimal('total_kredit_file', 18, 2)->nullable();

            // Angka hasil hitung sistem dari baris transaksi, untuk dibandingkan dengan angka file
            $table->decimal('total_debit_hitung', 18, 2)->nullable();
            $table->decimal('total_kredit_hitung', 18, 2)->nullable();

            // Jumlah baris per status
            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->unsignedInteger('jumlah_valid')->default(0);
            $table->unsignedInteger('jumlah_peringatan')->default(0);
            $table->unsignedInteger('jumlah_tidak_valid')->default(0);
            $table->unsignedInteger('jumlah_duplikat')->default(0);

            $table->enum('status', ['diproses', 'menunggu_verifikasi', 'disimpan', 'ditolak', 'gagal'])
                ->default('diproses');
            $table->enum('hasil_rekonsiliasi', ['cocok', 'selisih'])->nullable();
            $table->decimal('selisih', 18, 2)->nullable();
            $table->text('catatan')->nullable();    // alasan penolakan / kegagalan, ditampilkan ke pengguna

            // id pengguna di aplikasi input. Beda database, jadi tanpa foreign key.
            $table->unsignedBigInteger('diunggah_oleh');
            $table->unsignedBigInteger('disimpan_oleh')->nullable();
            $table->timestamp('disimpan_pada')->nullable();

            $table->timestamps();

            $table->index(['rekening_id', 'hash_sha256']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Rollback tabel keuangan dilarang di production.');
        }

        Schema::connection('dashi_keu')->dropIfExists('keu_impor_batch');
    }
};