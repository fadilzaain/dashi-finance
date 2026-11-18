<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeuImporBaris extends Model
{
    public const STATUS_VALID = 'valid';
    public const STATUS_PERINGATAN = 'peringatan';
    public const STATUS_TIDAK_VALID = 'tidak_valid';
    public const STATUS_DUPLIKAT = 'duplikat';

    protected $connection = 'dashi_keu';
    protected $table = 'keu_impor_baris';

    protected $fillable = [
        'batch_id', 'nomor_baris', 'data_mentah',
        'tanggal_transaksi', 'tanggal_efektif', 'nomor_referensi', 'kanal', 'deskripsi',
        'debit', 'kredit', 'saldo',
        'status', 'pesan_validasi', 'kunci_unik',
    ];

    protected function casts(): array
    {
        return [
            'data_mentah'       => 'array',
            'tanggal_transaksi' => 'datetime',
            'tanggal_efektif'   => 'datetime',
            'debit'             => 'decimal:2',
            'kredit'            => 'decimal:2',
            'saldo'             => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KeuImporBatch::class, 'batch_id');
    }
}