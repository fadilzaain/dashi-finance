<?php

namespace App\Console\Commands;

use App\Services\Keuangan\BarisTransaksi;
use App\Services\Keuangan\HasilParse;
use App\Services\Keuangan\ParserBni;
use App\Services\Keuangan\ParserBpd;
use App\Services\Keuangan\Rekonsiliator;
use App\Services\Keuangan\Uang;
use Illuminate\Console\Command;

class UjiParser extends Command
{
    protected $signature = 'keu:uji-parser
        {path : Lokasi file mutasi (.xls/.xlsx/.csv) di komputer ini}
        {--bank=BNI : Bank asal file: BNI atau BPD}
        {--rekening= : Nomor rekening yang dianggap dipilih pengguna (default: BNI 400986417, BPD 0111009133)}
        {--tampilkan-deskripsi : Tampilkan deskripsi pada contoh baris (berisi nama orang, jadi default disamarkan)}';

    protected $description = 'Uji kering parser file mutasi bank: membaca dan memeriksa file TANPA menulis ke database';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error('File tidak ditemukan: '.$path);
            return self::FAILURE;
        }

        $bank = strtoupper((string) $this->option('bank'));
        $rekeningDefault = ['BNI' => '400986417', 'BPD' => '0111009133'];

        if (! isset($rekeningDefault[$bank])) {
            $this->error('Bank tidak dikenal: '.$bank.' (gunakan BNI atau BPD).');
            return self::FAILURE;
        }

        $rekening = (string) ($this->option('rekening') ?: $rekeningDefault[$bank]);
        $ekstensi = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $hasil = $this->baca($bank, $rekening, $path, $ekstensi);

        if ($hasil === null) {
            $this->error('Ekstensi file tidak didukung: .'.$ekstensi.' (gunakan .xls, .xlsx, atau .csv)');
            return self::FAILURE;
        }

        $this->info("Uji kering parser {$bank} (tidak ada yang ditulis ke database)");

        $status = $hasil->jumlahPerStatus();
        $this->table(['Item', 'Nilai'], [
            ['File', basename($path)],
            ['Format', strtoupper($hasil->format)],
            ['Rekening di file', $hasil->nomorRekeningFile ?? '(tidak tercantum)'],
            ['Rekening dipilih', $rekening],
            ['Periode', ($hasil->periodeAwal?->format('Y-m-d') ?? '-').' s/d '.($hasil->periodeAkhir?->format('Y-m-d') ?? '-')],
            ['Jumlah baris transaksi', count($hasil->baris)],
            ['Status baris', "valid {$status['valid']}, peringatan {$status['peringatan']}, tidak_valid {$status['tidak_valid']}, duplikat {$status['duplikat']}"],
        ]);

        foreach ($hasil->galat as $galat) {
            $this->error('GALAT: '.$galat);
        }
        foreach ($hasil->peringatan as $peringatan) {
            $this->warn('Peringatan: '.$peringatan);
        }

        if (! $hasil->bisaDiproses()) {
            $this->newLine();
            $this->error('File TIDAK bisa diproses.');
            return self::FAILURE;
        }

        // Rekonsiliasi: bandingkan hasil hitung dengan angka kontrol di file
        $rekon = (new Rekonsiliator())->periksa($hasil);

        $this->newLine();
        $this->line('== Rekonsiliasi ==');
        $this->table(['Pemeriksaan', 'Di file', 'Hasil hitung', 'Selisih'], [
            ['Saldo awal', $this->rp($hasil->saldoAwal), '-', '-'],
            ['Total debit', $this->rp($hasil->totalDebitFile), $this->rp($rekon->debitHitung), $this->rp($rekon->selisihDebit)],
            ['Total kredit', $this->rp($hasil->totalKreditFile), $this->rp($rekon->kreditHitung), $this->rp($rekon->selisihKredit)],
            ['Saldo akhir', $this->rp($hasil->saldoAkhir), $this->rp($rekon->saldoAkhirHitung), $this->rp($rekon->selisihSaldoAkhir)],
            ['Baris saldo berjalan tidak cocok', '-', (string) $rekon->barisSaldoTidakCocok, '-'],
        ]);

        $label = match ($rekon->cocok) {
            true => 'COCOK',
            false => 'SELISIH',
            null => 'TIDAK BISA DIPERIKSA (file tanpa angka kontrol)',
        };
        $this->line('Hasil rekonsiliasi: '.$label);
        foreach ($rekon->catatan as $catatan) {
            $this->warn($catatan);
        }

        // Baris bermasalah (tanpa deskripsi, karena bisa berisi nama orang)
        $bermasalah = array_values(array_filter(
            $hasil->baris,
            fn (BarisTransaksi $b) => $b->status !== BarisTransaksi::VALID
        ));
        if ($bermasalah !== []) {
            $this->newLine();
            $this->line('== Baris bermasalah (maksimal 10) ==');
            foreach (array_slice($bermasalah, 0, 10) as $b) {
                $this->line("#{$b->nomorBaris} [{$b->status}] {$b->pesan}");
            }
        }

        // Contoh tiga baris pertama
        $this->newLine();
        $this->line('== Contoh 3 baris pertama ==');
        $tampilkan = (bool) $this->option('tampilkan-deskripsi');
        $contoh = [];
        foreach (array_slice($hasil->baris, 0, 3) as $b) {
            $contoh[] = [
                $b->nomorBaris,
                $b->tanggalTransaksi?->format('Y-m-d H:i:s') ?? '-',
                $b->nomorReferensi ?? '-',
                $b->arah() ?? '-',
                $this->rp($b->nominal()),
                $this->rp($b->saldo),
                $b->kunciUnik !== null ? substr($b->kunciUnik, 0, 12).'...' : '-',
                $tampilkan ? mb_strimwidth((string) $b->deskripsi, 0, 40, '...') : '(disamarkan)',
            ];
        }
        $this->table(['No', 'Waktu', 'Jurnal', 'Arah', 'Nominal', 'Saldo', 'Kunci unik', 'Deskripsi'], $contoh);

        return $rekon->cocok === false ? self::FAILURE : self::SUCCESS;
    }

    private function baca(string $bank, string $rekening, string $path, string $ekstensi): ?HasilParse
    {
        if ($bank === 'BPD') {
            $parser = new ParserBpd($rekening);

            return match (true) {
                in_array($ekstensi, ['xls', 'xlsx'], true) => $parser->parseExcel($path),
                $ekstensi === 'csv' => $parser->parseCsv($path),
                default => null,
            };
        }

        $parser = new ParserBni($rekening);

        return match (true) {
            in_array($ekstensi, ['xls', 'xlsx'], true) => $parser->parseXls($path),
            $ekstensi === 'csv' => $parser->parseCsv($path),
            default => null,
        };
    }

    private function rp(?int $sen): string
    {
        return $sen === null ? '-' : Uang::rupiah($sen);
    }
}