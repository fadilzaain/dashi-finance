<?php

namespace App\Services\Keuangan;

use DateTimeImmutable;

/**
 * Hasil pembacaan satu file bank. Belum menyentuh database.
 * Nominal dalam sen (lihat Uang).
 */
final class HasilParse
{
    /**
     * @param  list<BarisTransaksi>  $baris
     * @param  list<string>  $peringatan  hal yang perlu diperhatikan, tidak menghentikan proses
     * @param  list<string>  $galat  masalah fatal: file tidak boleh diproses
     */
    public function __construct(
        public readonly string $format,                     // xls | xlsx | csv
        public readonly ?string $nomorRekeningFile,         // null bila file tidak memuatnya
        public readonly ?DateTimeImmutable $periodeAwal,
        public readonly ?DateTimeImmutable $periodeAkhir,
        public readonly ?int $saldoAwal,
        public readonly ?int $saldoAkhir,
        public readonly ?int $totalDebitFile,
        public readonly ?int $totalKreditFile,
        public readonly array $baris,
        public readonly array $peringatan,
        public readonly array $galat,
    ) {
    }

    public function bisaDiproses(): bool
    {
        return $this->galat === [];
    }

    /**
     * @return array{valid:int, peringatan:int, tidak_valid:int, duplikat:int}
     */
    public function jumlahPerStatus(): array
    {
        $hitung = [
            BarisTransaksi::VALID => 0,
            BarisTransaksi::PERINGATAN => 0,
            BarisTransaksi::TIDAK_VALID => 0,
            BarisTransaksi::DUPLIKAT => 0,
        ];

        foreach ($this->baris as $baris) {
            $hitung[$baris->status]++;
        }

        return $hitung;
    }
}
