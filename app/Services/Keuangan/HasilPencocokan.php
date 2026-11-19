<?php

namespace App\Services\Keuangan;

/**
 * Keluaran PencocokTransfer. Hanya data; tidak ada yang ditulis ke database.
 */
final class HasilPencocokan
{
    /**
     * @param  list<array{debit: MutasiRingkas, kredit: MutasiRingkas, jenis: string, selisihDetik: int}>  $pasangan
     *         selisihDetik = waktu kredit dikurangi waktu debit
     * @param  list<array{sen: int, debit: list<MutasiRingkas>, kredit: list<MutasiRingkas>}>  $ambigu
     *         kelompok yang tidak bisa dipasangkan dengan pasti; sengaja TIDAK ditebak
     * @param  list<array{mutasi: MutasiRingkas, terdekatDetik: ?int}>  $debitSisa
     *         terdekatDetik = selisih ke kredit bernominal sama (rekening lain, belum berpasangan) yang paling dekat,
     *         bila ada; biasanya berarti kredit itu jatuh di luar jendela waktu
     * @param  list<array{mutasi: MutasiRingkas, terdekatDetik: ?int}>  $kreditSisa
     */
    public function __construct(
        public readonly array $pasangan,
        public readonly array $ambigu,
        public readonly array $debitSisa,
        public readonly array $kreditSisa,
    ) {
    }

    public function jumlahPasangan(?string $jenis = null): int
    {
        return count($this->saring($jenis));
    }

    /** Total nominal pasangan (sen), dihitung dari sisi debit. */
    public function totalPasanganSen(?string $jenis = null): int
    {
        return array_sum(array_map(fn (array $p) => $p['debit']->sen, $this->saring($jenis)));
    }

    /** @return list<array{debit: MutasiRingkas, kredit: MutasiRingkas, jenis: string, selisihDetik: int}> */
    private function saring(?string $jenis): array
    {
        return $jenis === null
            ? $this->pasangan
            : array_values(array_filter($this->pasangan, fn (array $p) => $p['jenis'] === $jenis));
    }
}