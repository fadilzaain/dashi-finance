<?php

namespace App\Services\Keuangan;

use DateTimeInterface;

/**
 * Kunci pencegah transaksi ganda.
 *
 * Dibentuk dari: nomor rekening | tanggal-waktu transaksi | nomor referensi | arah | nominal.
 * Saldo, kanal, dan deskripsi SENGAJA tidak ikut, karena nilainya bisa berbeda antar versi
 * file yang sama (mis. deskripsi terpotong di .xls BNI, kode cabang vs nama kanal).
 * Dengan begitu transaksi yang sama dari file .xls dan .csv menghasilkan kunci yang sama.
 */
final class KunciUnik
{
    public static function hitung(
        string $nomorRekening,
        DateTimeInterface $tanggal,
        string $nomorReferensi,
        string $arah,
        int $nominalSen,
    ): string {
        return hash('sha256', implode('|', [
            $nomorRekening,
            $tanggal->format('Y-m-d H:i:s'),
            $nomorReferensi,
            $arah,
            Uang::format($nominalSen),
        ]));
    }
}
