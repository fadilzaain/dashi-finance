<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class KeuImporBatch extends Model
{
    public const STATUS_DIPROSES = 'diproses';
    public const STATUS_MENUNGGU_VERIFIKASI = 'menunggu_verifikasi';
    public const STATUS_DISIMPAN = 'disimpan';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_GAGAL = 'gagal';

    public const REKON_COCOK = 'cocok';
    public const REKON_SELISIH = 'selisih';

    protected $connection = 'dashi_keu';
    protected $table = 'keu_impor_batch';

    protected $fillable = [
        'kode_batch', 'rekening_id',
        'nama_file_asli', 'path_file', 'format_file', 'ukuran_byte', 'hash_sha256',
        'periode_awal', 'periode_akhir',
        'saldo_awal_file', 'saldo_akhir_file', 'total_debit_file', 'total_kredit_file',
        'total_debit_hitung', 'total_kredit_hitung',
        'jumlah_baris', 'jumlah_valid', 'jumlah_peringatan', 'jumlah_tidak_valid', 'jumlah_duplikat',
        'status', 'hasil_rekonsiliasi', 'selisih', 'catatan',
        'diunggah_oleh', 'disimpan_oleh', 'disimpan_pada',
    ];

    protected function casts(): array
    {
        return [
            'periode_awal'        => 'date',
            'periode_akhir'       => 'date',
            'saldo_awal_file'     => 'decimal:2',
            'saldo_akhir_file'    => 'decimal:2',
            'total_debit_file'    => 'decimal:2',
            'total_kredit_file'   => 'decimal:2',
            'total_debit_hitung'  => 'decimal:2',
            'total_kredit_hitung' => 'decimal:2',
            'selisih'             => 'decimal:2',
            'disimpan_pada'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Kode batch (UUID) dibuat otomatis
        static::creating(function (self $batch) {
            $batch->kode_batch ??= (string) Str::uuid();
        });
    }

    // URL memakai kode_batch (UUID), bukan id berurutan, supaya batch orang lain tidak bisa ditebak
    public function getRouteKeyName(): string
    {
        return 'kode_batch';
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(KeuRekeningBank::class, 'rekening_id');
    }

    public function baris(): HasMany
    {
        return $this->hasMany(KeuImporBaris::class, 'batch_id');
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(KeuMutasiBank::class, 'batch_id');
    }
}