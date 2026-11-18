<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BedahFileBank extends Command
{
    protected $signature = 'keu:bedah-file
        {path : Lokasi file mutasi di komputer ini}
        {--kolom-no=2 : Indeks kolom (mulai 0) yang berisi nomor urut transaksi}
        {--sembunyikan= : Indeks kolom yang disamarkan, pisahkan koma (mis. 12 untuk deskripsi BNI .xls)}';

    protected $description = 'Menampilkan struktur file mutasi bank (header, contoh satu baris, baris non-transaksi) untuk menulis parser';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error('File tidak ditemukan: '.$path);
            return self::FAILURE;
        }

        $kolomNo = (int) $this->option('kolom-no');
        $samar = array_filter(array_map('trim', explode(',', (string) $this->option('sembunyikan'))), 'strlen');
        $samar = array_map('intval', $samar);

        // Data mentah apa adanya: tanpa hitung rumus, tanpa format (tanggal tetap angka serial)
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);
        $baris = $sheet->toArray(null, false, false, false);

        $this->info('Sheet pertama: "'.$sheet->getTitle().'", '.count($baris).' baris');

        $transaksi = 0;
        $pertama = null;
        foreach ($baris as $i => $row) {
            if (isset($row[$kolomNo]) && is_numeric($row[$kolomNo])) {
                $transaksi++;
                $pertama ??= $i;
            }
        }
        $this->info("Baris transaksi (kolom {$kolomNo} berisi angka): {$transaksi}");

        $this->newLine();
        $this->line('== Baris sebelum transaksi pertama ==');
        // Tanpa transaksi terdeteksi (mis. CSV), cukup 5 baris pertama. Penyamaran berlaku di semua bagian.
        $batas = min($pertama ?? 5, 40);
        foreach (array_slice($baris, 0, $batas, true) as $i => $row) {
            $this->tampil($i, $row, $samar);
        }

        if ($pertama !== null) {
            $this->newLine();
            $this->line('== Transaksi pertama (kolom disamarkan: '.($samar ? implode(',', $samar) : 'tidak ada').') ==');
            $this->tampil($pertama, $baris[$pertama], $samar);
        }

        $this->newLine();
        $this->line('== 15 baris non-transaksi pertama setelah transaksi pertama ==');
        $jumlah = 0;
        foreach ($baris as $i => $row) {
            if ($pertama === null || $i <= $pertama) {
                continue;
            }
            if (isset($row[$kolomNo]) && is_numeric($row[$kolomNo])) {
                continue;
            }
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $this->tampil($i, $row, $samar);
            if (++$jumlah >= 15) {
                break;
            }
        }

        return self::SUCCESS;
    }

    // Satu baris: hanya sel yang terisi, format [indeks]=nilai. Teks berkutip, angka tidak.
    private function tampil(int $nomor, array $row, array $samar): void
    {
        $sel = [];
        foreach ($row as $idx => $nilai) {
            if ($nilai === null || $nilai === '') {
                continue;
            }
            if (in_array($idx, $samar, true)) {
                $sel[] = "[{$idx}]=(disamarkan)";
                continue;
            }
            $teks = is_string($nilai) ? mb_strimwidth($nilai, 0, 60, '...') : $nilai;
            $sel[] = "[{$idx}]=".json_encode($teks, JSON_UNESCAPED_UNICODE);
        }
        $this->line('#'.$nomor.' '.implode('  ', $sel));
    }
}