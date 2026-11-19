<?php

namespace App\Services\Keuangan;

/**
 * Hasil pemeriksaan sebelum batch boleh dipindahkan dari staging ke data final.
 */
final class KelayakanSimpan
{
    /**
     * @param  list<string>  $blokir  alasan batch TIDAK boleh disimpan (tidak bisa dilewati)
     * @param  list<string>  $konfirmasi  hal yang harus dikonfirmasi pengguna lebih dulu (boleh dilewati dengan centang)
     * @param  int  $barisBaru  jumlah transaksi yang benar-benar akan masuk ke data final
     * @param  int  $barisDilewati  baris berstatus duplikat yang tidak ikut disimpan
     */
    public function __construct(
        public readonly array $blokir,
        public readonly array $konfirmasi,
        public readonly int $barisBaru,
        public readonly int $barisDilewati,
    ) {
    }

    public function bisaDisimpan(): bool
    {
        return $this->blokir === [];
    }
}