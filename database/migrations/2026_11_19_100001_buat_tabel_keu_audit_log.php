<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'dashi_keu';

    public function up(): void
    {
        // Jejak aktivitas. Hanya boleh ditambah, tidak pernah diubah atau dihapus.
        Schema::connection('dashi_keu')->create('keu_audit_log', function (Blueprint $table) {
            $table->id();
            // id pengguna di aplikasi input (beda database, jadi tanpa foreign key). Kosong bila dari server/tamu.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Salinan nama saat kejadian, supaya jejak tetap terbaca walau akun diubah/dinonaktifkan
            $table->string('user_nama', 100)->nullable();
            $table->string('aktivitas', 40);
            $table->string('objek_tipe', 40)->nullable();   // batch / pengguna
            $table->unsignedBigInteger('objek_id')->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->json('data_sebelum')->nullable();
            $table->json('data_sesudah')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');

            $table->index(['aktivitas', 'created_at']);
            $table->index(['objek_tipe', 'objek_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Rollback tabel keuangan dilarang di production.');
        }

        Schema::connection('dashi_keu')->dropIfExists('keu_audit_log');
    }
};