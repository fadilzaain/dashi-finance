<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin atau keuangan
            $table->string('role', 20)->default('keuangan')->after('email');
            // aktif atau nonaktif
            $table->string('status', 12)->default('aktif')->after('role');
            // password sementara: wajib diganti sebelum memakai menu lain
            $table->boolean('wajib_ganti_password')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'wajib_ganti_password']);
        });
    }
};