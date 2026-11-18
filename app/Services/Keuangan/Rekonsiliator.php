<?php

namespace App\Services\Keuangan;

/**
 * Rekonsiliasi: memastikan baris transaksi yang terbaca cocok dengan angka kontrol di file bank.
 *
 * Tiga pemeriksaan (dilakukan bila angkanya ada di file):
 *  1. Total debit dan total kredit hasil hitung = total di header/footer file.
 *  2. Saldo akhir: saldo awal + total kredit - total debit = saldo baris terakhir / saldo akhir file.
 *  3. Saldo berjalan: untuk setiap baris, saldo sebelumnya + kredit - debit = saldo baris itu.
 *
 * Semua baris yang nominalnya terbaca ikut dihitung (termasuk yang berstatus peringatan/duplikat),
 * karena angka kontrol bank memang mencakup seluruh baris di file.
 */
final class Rekonsiliator
{
    public function periksa(HasilParse $hasil): HasilRekonsiliasi
    {
        $debit = 0;
        $kredit = 0;
        foreach ($hasil->baris as $baris) {
            $debit += $baris->debit ?? 0;
            $kredit += $baris->kredit ?? 0;
        }

        $kontrol = [];   // setiap elemen: true bila satu pemeriksaan lolos
        $catatan = [];

        // 1. Total
        $selisihDebit = $hasil->totalDebitFile !== null ? $debit - $hasil->totalDebitFile : null;
        $selisihKredit = $hasil->totalKreditFile !== null ? $kredit - $hasil->totalKreditFile : null;
        if ($selisihDebit !== null) {
            $kontrol[] = $selisihDebit === 0;
            if ($selisihDebit !== 0) {
                $catatan[] = 'Total debit hasil hitung berbeda '.Uang::rupiah($selisihDebit).' dari total di file.';
            }
        }
        if ($selisihKredit !== null) {
            $kontrol[] = $selisihKredit === 0;
            if ($selisihKredit !== 0) {
                $catatan[] = 'Total kredit hasil hitung berbeda '.Uang::rupiah($selisihKredit).' dari total di file.';
            }
        }

        // 2. Saldo akhir
        $saldoAkhirHitung = $hasil->saldoAwal !== null ? $hasil->saldoAwal + $kredit - $debit : null;
        $selisihSaldoAkhir = ($saldoAkhirHitung !== null && $hasil->saldoAkhir !== null)
            ? $saldoAkhirHitung - $hasil->saldoAkhir
            : null;
        if ($selisihSaldoAkhir !== null) {
            $kontrol[] = $selisihSaldoAkhir === 0;
            if ($selisihSaldoAkhir !== 0) {
                $catatan[] = 'Saldo akhir hasil hitung berbeda '.Uang::rupiah($selisihSaldoAkhir).' dari saldo akhir di file.';
            }
        }

        // 3. Saldo berjalan per baris
        $sebelumnya = $hasil->saldoAwal;
        $diperiksa = 0;
        $tidakCocok = 0;
        $contoh = [];
        foreach ($hasil->baris as $baris) {
            if ($baris->saldo === null || $baris->debit === null || $baris->kredit === null) {
                $sebelumnya = $baris->saldo;   // rantai putus; mulai lagi dari saldo baris ini bila ada
                continue;
            }

            if ($sebelumnya !== null) {
                $diperiksa++;
                if ($sebelumnya + $baris->kredit - $baris->debit !== $baris->saldo) {
                    $tidakCocok++;
                    if (count($contoh) < 5) {
                        $contoh[] = $baris->nomorBaris;
                    }
                }
            }
            $sebelumnya = $baris->saldo;       // sambung dari saldo yang tertulis, agar satu putusan tidak menjalar
        }
        if ($diperiksa > 0) {
            $kontrol[] = $tidakCocok === 0;
            if ($tidakCocok > 0) {
                $catatan[] = "Saldo berjalan tidak sesuai di {$tidakCocok} baris (contoh nomor baris: ".implode(', ', $contoh).').';
            }
        }

        if ($kontrol === []) {
            $catatan[] = 'File tidak memuat angka kontrol (saldo/total), jadi rekonsiliasi tidak bisa dilakukan.';
        }

        return new HasilRekonsiliasi(
            cocok: $kontrol === [] ? null : ! in_array(false, $kontrol, true),
            debitHitung: $debit,
            kreditHitung: $kredit,
            selisihDebit: $selisihDebit,
            selisihKredit: $selisihKredit,
            saldoAkhirHitung: $saldoAkhirHitung,
            selisihSaldoAkhir: $selisihSaldoAkhir,
            barisSaldoTidakCocok: $tidakCocok,
            contohBarisSaldoTidakCocok: $contoh,
            catatan: $catatan,
        );
    }
}
