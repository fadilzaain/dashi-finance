<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Satu baris jejak aktivitas (tabel keu_audit_log di koneksi dashi_keu).
 * Hanya boleh ditambah lewat App\Services\AuditLog; tidak pernah diubah atau dihapus.
 */
class KeuAuditLog extends Model
{
    // Tabel hanya punya created_at, tidak ada updated_at
    public const UPDATED_AT = null;

    protected $connection = 'dashi_keu';
    protected $table = 'keu_audit_log';

    protected $fillable = [
        'user_id', 'user_nama', 'aktivitas', 'objek_tipe', 'objek_id',
        'keterangan', 'data_sebelum', 'data_sesudah', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'data_sebelum' => 'array',
            'data_sesudah' => 'array',
            'created_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Lapis pertama: model menolak ubah/hapus. Lapis kedua: trigger di database (migration lindungi_tabel_keu_audit_log).
        static::updating(fn () => throw new LogicException('Audit log tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log tidak boleh dihapus.'));
    }
}