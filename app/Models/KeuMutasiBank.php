<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class KeuMutasiBank extends Model
{
    public const ARAH_DEBIT = 'debit';
    public const ARAH_KREDIT = 'kredit';

    protected $connection = 'dashi_keu';
    protected $table = 'keu_mutasi_bank';

    protected $fillable = [
        'rekening_id', 'batch_id', 'baris_id', 'kunci_unik',
        'tanggal_transaksi', 'tanggal_efektif', 'nomor_referensi', 'kanal', 'deskripsi',
        'arah', 'nominal', 'saldo_setelah',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_transaksi' => 'datetime',
            'tanggal_efektif'   => 'datetime',
            'nominal'           => 'decimal:2',
            'saldo_setelah'     => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Mutasi yang sudah disimpan tidak boleh diubah atau dihapus lewat aplikasi.
        // Koreksi dilakukan lewat batch baru, bukan dengan mengedit data final.
        static::updating(fn () => throw new LogicException('Mutasi bank tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Mutasi bank tidak boleh dihapus.'));
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(KeuRekeningBank::class, 'rekening_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KeuImporBatch::class, 'batch_id');
    }

    public function baris(): BelongsTo
    {
        return $this->belongsTo(KeuImporBaris::class, 'baris_id');
    }
}