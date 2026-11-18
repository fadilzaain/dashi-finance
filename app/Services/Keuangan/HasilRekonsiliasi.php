<?php

namespace App\Services\Keuangan;

/**
 * Hasil membandingkan angka hasil hitung dari baris transaksi dengan angka kontrol di file bank.
 * Nominal dalam sen (lihat Uang).
 */
final class HasilRekonsiliasi
{
    public const COCOK = 'cocok';
    public const SELISIH = 'selisih';

    /**
     * @param  bool|null  $cocok  true = semua pemeriksaan lolos, false = ada selisih, null = tidak ada angka kontrol di file
     * @param  list<int>  $contohBarisSaldoTidakCocok  nomor baris (maksimal 5) tempat saldo berjalan putus
     * @param  list<string>  $catatan  penjelasan untuk pengguna
     */
    public function __construct(
        public readonly ?bool $cocok,
        public readonly int $debitHitung,
        public readonly int $kreditHitung,
        public readonly ?int $selisihDebit,
        public readonly ?int $selisihKredit,
        public readonly ?int $saldoAkhirHitung,
        public readonly ?int $selisihSaldoAkhir,
        public readonly int $barisSaldoTidakCocok,
        public readonly array $contohBarisSaldoTidakCocok,
        public readonly array $catatan,
    ) {
    }

    // Nilai untuk kolom keu_impor_batch.hasil_rekonsiliasi (null bila tidak bisa diperiksa)
    public function hasil(): ?string
    {
        return match ($this->cocok) {
            true => self::COCOK,
            false => self::SELISIH,
            null => null,
        };
    }

    // Nilai untuk kolom keu_impor_batch.selisih: selisih saldo akhir bila ada, kalau tidak selisih total
    public function selisih(): ?int
    {
        if ($this->selisihSaldoAkhir !== null) {
            return $this->selisihSaldoAkhir;
        }

        if ($this->selisihDebit !== null || $this->selisihKredit !== null) {
            return ($this->selisihKredit ?? 0) - ($this->selisihDebit ?? 0);
        }

        return null;
    }
}
