<?php

namespace App\Services\Keuangan;

use DateTimeImmutable;

/**
 * Satu baris transaksi hasil pembacaan file bank (belum disimpan ke database).
 * Nominal dalam sen (lihat Uang).
 */
final class BarisTransaksi
{
    public const VALID = 'valid';
    public const PERINGATAN = 'peringatan';
    public const TIDAK_VALID = 'tidak_valid';
    public const DUPLIKAT = 'duplikat';

    public const ARAH_DEBIT = 'debit';
    public const ARAH_KREDIT = 'kredit';

    public function __construct(
        public readonly int $nomorBaris,              // urutan di file sumber
        public readonly array $dataMentah,            // isi baris apa adanya (sel yang terisi saja)
        public readonly ?DateTimeImmutable $tanggalTransaksi,
        public readonly ?string $nomorReferensi,      // selalu teks
        public readonly ?string $kanal,
        public readonly ?string $deskripsi,
        public readonly ?int $debit,                  // sen; null bila baris rusak
        public readonly ?int $kredit,                 // sen; null bila baris rusak
        public readonly ?int $saldo,                  // sen; null bila file tidak memuat saldo
        public string $status,
        public ?string $pesan,
        public readonly ?string $kunciUnik,
    ) {
    }

    public function arah(): ?string
    {
        if ($this->debit === null || $this->kredit === null) {
            return null;
        }

        return $this->debit > 0 ? self::ARAH_DEBIT : self::ARAH_KREDIT;
    }

    public function nominal(): ?int
    {
        return match ($this->arah()) {
            self::ARAH_DEBIT => $this->debit,
            self::ARAH_KREDIT => $this->kredit,
            default => null,
        };
    }

    /**
     * Bentuk siap simpan ke tabel keu_impor_baris (kecuali batch_id, diisi saat menyimpan).
     */
    public function keArrayStaging(): array
    {
        return [
            'nomor_baris' => $this->nomorBaris,
            'data_mentah' => $this->dataMentah,
            'tanggal_transaksi' => $this->tanggalTransaksi?->format('Y-m-d H:i:s'),
            'nomor_referensi' => $this->nomorReferensi,
            'kanal' => $this->kanal,
            'deskripsi' => $this->deskripsi,
            'debit' => $this->debit !== null ? Uang::format($this->debit) : null,
            'kredit' => $this->kredit !== null ? Uang::format($this->kredit) : null,
            'saldo' => $this->saldo !== null ? Uang::format($this->saldo) : null,
            'status' => $this->status,
            'pesan_validasi' => $this->pesan,
            'kunci_unik' => $this->kunciUnik,
        ];
    }
}
