<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'dashi_keu';

    private const TRIGGER = [
        'UPDATE' => 'keu_audit_log_cegah_ubah',
        'DELETE' => 'keu_audit_log_cegah_hapus',
    ];

    /**
     * Lapis pengaman di level database: UPDATE dan DELETE pada audit log ditolak oleh MySQL sendiri,
     * jadi tidak cukup hanya mengandalkan model Laravel (query langsung atau tinker tetap tertahan).
     *
     * Butuh hak TRIGGER di akun MySQL. Kalau akun tidak punya hak itu, migration tetap lanjut
     * (tabel sudah jadi) dan kegagalannya dicatat di log. Cek dengan: SHOW TRIGGERS LIKE 'keu_audit_log';
     */
    public function up(): void
    {
        $db = DB::connection('dashi_keu');

        if ($db->getDriverName() !== 'mysql') {
            return;   // test memakai SQLite; perlindungan model sudah diuji terpisah
        }

        try {
            foreach (self::TRIGGER as $operasi => $nama) {
                $db->unprepared("DROP TRIGGER IF EXISTS {$nama}");
                $db->unprepared(
                    "CREATE TRIGGER {$nama} BEFORE {$operasi} ON keu_audit_log FOR EACH ROW "
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log tidak boleh diubah atau dihapus.'"
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        $db = DB::connection('dashi_keu');

        if ($db->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TRIGGER as $nama) {
            $db->unprepared("DROP TRIGGER IF EXISTS {$nama}");
        }
    }
};