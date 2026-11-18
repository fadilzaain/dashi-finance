<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeuRekeningBank extends Model
{
    protected $connection = 'dashi_keu';
    protected $table = 'keu_rekening_bank';

    protected $fillable = ['kode', 'bank', 'nomor_rekening', 'nama', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function batch(): HasMany
    {
        return $this->hasMany(KeuImporBatch::class, 'rekening_id');
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(KeuMutasiBank::class, 'rekening_id');
    }
}