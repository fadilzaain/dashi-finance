<?php

namespace App\Services\Keuangan;

/**
 * Pengolah nominal uang.
 *
 * Semua nominal dihitung dalam "sen" (bilangan bulat), bukan float, supaya
 * penjumlahan ratusan transaksi tidak pernah menghasilkan selisih pembulatan.
 * Contoh: Rp636.774,00 disimpan sebagai 63677400.
 */
final class Uang
{
    /**
     * Membaca teks angka berformat BNI: koma = pemisah ribuan, titik = desimal.
     * Contoh yang valid: "636,774.00", "1,034,000.00", ".00", "0".
     * Mengembalikan null kalau teksnya bukan angka yang valid.
     */
    public static function dariTeks(string $teks): ?int
    {
        $bersih = str_replace([',', ' '], '', trim($teks));
        if ($bersih === '' || ! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $bersih, $m)) {
            return null;
        }

        $bulat = $m[2];
        $pecahan = $m[3] ?? '';

        if ($bulat === '' && $pecahan === '') {
            return null;
        }
        // Batas wajar supaya tidak melewati kapasitas bilangan bulat PHP
        if (strlen($bulat) > 15) {
            return null;
        }
        // Pecahan lebih kecil dari satu sen dianggap data rusak
        if (strlen($pecahan) > 2 && trim(substr($pecahan, 2), '0') !== '') {
            return null;
        }

        $sen = ((int) ($bulat === '' ? '0' : $bulat)) * 100
            + (int) str_pad(substr($pecahan, 0, 2), 2, '0');

        return $m[1] === '-' ? -$sen : $sen;
    }

    /**
     * Membaca nilai sel Excel: angka (int/float) atau teks. Selain itu dianggap tidak valid.
     */
    public static function dariNilai(mixed $nilai): ?int
    {
        if (is_int($nilai) || is_float($nilai)) {
            return is_finite((float) $nilai) ? (int) round($nilai * 100) : null;
        }

        if (is_string($nilai)) {
            return self::dariTeks($nilai);
        }

        return null;
    }

    /**
     * Sen -> teks desimal untuk disimpan ke kolom DECIMAL, contoh 63677400 -> "636774.00".
     */
    public static function format(int $sen): string
    {
        $tanda = $sen < 0 ? '-' : '';
        $mutlak = abs($sen);

        return sprintf('%s%d.%02d', $tanda, intdiv($mutlak, 100), $mutlak % 100);
    }

    /**
     * Sen -> tampilan rupiah, contoh 63677400 -> "Rp636.774".
     */
    public static function rupiah(int $sen): string
    {
        $tanda = $sen < 0 ? '-' : '';
        $mutlak = abs($sen);
        $desimal = $mutlak % 100 === 0 ? 0 : 2;

        return $tanda.'Rp'.number_format($mutlak / 100, $desimal, ',', '.');
    }
}
