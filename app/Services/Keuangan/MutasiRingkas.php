<?php

namespace App\Services\Keuangan;

/**
 * Ringkasan satu mutasi bank untuk keperluan pencocokan: hanya yang dibutuhkan, tanpa deskripsi.
 * Deskripsi sengaja tidak ikut karena berisi nama orang dan tidak dipakai untuk mencocokkan.
 */
final class MutasiRingkas
{
    /**
     * @param  int  $waktu  waktu transaksi (unix timestamp)
     * @param  string  $arah  'debit' atau 'kredit'
     * @param  int  $sen  nominal dalam sen (bilangan bulat, tidak pernah float)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $rekeningId,
        public readonly int $waktu,
        public readonly string $arah,
        public readonly int $sen,
    ) {
    }
}