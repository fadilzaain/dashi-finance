<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class KeuTransferInternal extends Model
{
    public const STATUS_MENUNGGU = 'needs_review';
    public const STATUS_TERKONFIRMASI = 'matched';
    public const STATUS_DITOLAK = 'rejected';

    public const DAFTAR_STATUS = [
        self::STATUS_MENUNGGU,
        self::STATUS_TERKONFIRMASI,
        self::STATUS_DITOLAK,
    ];

    protected $connection = 'dashi_keu';
    protected $table = 'keu_transfer_internal';

    protected $fillable = [
        'debit_mutasi_id', 'kredit_mutasi_id', 'jenis', 'selisih_detik', 'status',
        'dibuat_oleh', 'diputuskan_oleh', 'diputuskan_pada',
    ];

    protected function casts(): array
    {
        return [
            'selisih_detik'   => 'integer',
            'diputuskan_pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Usulan baru selalu mulai dari "menunggu konfirmasi"; tidak boleh langsung terkonfirmasi
        static::creating(function (self $m) {
            if (($m->status ?? self::STATUS_MENUNGGU) !== self::STATUS_MENUNGGU) {
                throw new LogicException('Usulan baru harus berstatus menunggu konfirmasi.');
            }
        });

        // Yang boleh berubah hanya keputusan. Pasangan transaksinya tidak pernah diubah.
        static::updating(function (self $m) {
            $boleh = ['status', 'diputuskan_oleh', 'diputuskan_pada', 'updated_at'];
            if (array_diff(array_keys($m->getDirty()), $boleh) !== []) {
                throw new LogicException('Hanya keputusan (status) yang boleh diubah pada pasangan pindah dana.');
            }
        });

        static::deleting(fn () => throw new LogicException('Pasangan pindah dana tidak boleh dihapus.'));
    }

    public function debit(): BelongsTo
    {
        return $this->belongsTo(KeuMutasiBank::class, 'debit_mutasi_id');
    }

    public function kredit(): BelongsTo
    {
        return $this->belongsTo(KeuMutasiBank::class, 'kredit_mutasi_id');
    }
}