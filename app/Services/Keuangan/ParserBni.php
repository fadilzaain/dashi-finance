<?php

namespace App\Services\Keuangan;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Pembaca file mutasi rekening BNI.
 *
 * - .xls  : format asli dari bank. Memuat nomor rekening, periode, saldo awal, total debit/kredit
 *           di header dan saldo di setiap baris, jadi bisa direkonsiliasi. Sumber utama.
 * - .csv  : hanya cadangan. Tidak memuat saldo/total/nomor rekening, jadi rekonsiliasi saldo
 *           tidak bisa dilakukan.
 *
 * Kelas ini hanya MEMBACA dan MEMERIKSA. Tidak menulis ke database.
 */
final class ParserBni
{
    // Zona waktu jam transaksi di file bank (tidak bergantung pada zona waktu aplikasi)
    private const ZONA = 'Asia/Jakarta';

    // Judul kolom tabel transaksi di file .xls. Posisi kolom dicari dari judul ini,
    // bukan ditulis mati, supaya tahan terhadap pergeseran kolom.
    private const JUDUL_KOLOM_XLS = [
        'no' => 'No.',
        'tanggal' => 'Post Date',
        'kanal' => 'Branch',
        'jurnal' => 'Journal No.',
        'deskripsi' => 'Description',
        'jumlah' => 'Amount',
        'arah' => 'Db/Cr',
        'saldo' => 'Balance',
    ];

    // Tujuh kolom pertama CSV BNI (kolom ke-8 kosong karena ada koma di ujung baris)
    private const JUDUL_KOLOM_CSV = [
        'Post Date', 'Value Date', 'Branch', 'Journal No.', 'Description', 'Debit', 'Credit',
    ];

    public function __construct(private readonly string $nomorRekening)
    {
    }

    // ------------------------------------------------------------------ .xls / .xlsx

    public function parseXls(string $path): HasilParse
    {
        try {
            $jenis = IOFactory::identify($path);
            if (! in_array($jenis, ['Xls', 'Xlsx'], true)) {
                return $this->hasilGalat('xls', 'File bukan Excel (.xls/.xlsx). Terdeteksi sebagai: '.$jenis.'.');
            }

            $pembaca = IOFactory::createReader($jenis);
            // Data mentah apa adanya: tanpa hitung rumus, tanpa format (tanggal tetap angka serial)
            $pembaca->setReadDataOnly(true);
            $rows = $pembaca->load($path)->getSheet(0)->toArray(null, false, false, false);
        } catch (Throwable) {
            return $this->hasilGalat('xls', 'File Excel tidak dapat dibaca atau rusak.');
        }

        return $this->dariBarisXls($rows, strtolower($jenis));
    }

    /**
     * Memeriksa isi sheet yang sudah dibaca menjadi array baris x kolom.
     * Dipisah dari parseXls() agar logikanya bisa diuji tanpa membuka file.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function dariBarisXls(array $rows, string $format = 'xls'): HasilParse
    {
        $galat = [];
        $peringatan = [];
        $kolom = null;
        $header = ['rekening' => [], 'periode' => [], 'saldoAwal' => [], 'debit' => [], 'kredit' => []];
        $baris = [];

        foreach ($rows as $indeks => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row = array_values($row);

            // Baris judul kolom (muncul berulang di setiap halaman cetak)
            $petaBaru = $this->petaKolomXls($row);
            if ($petaBaru !== null) {
                if ($kolom === null) {
                    $kolom = $petaBaru;
                } elseif ($kolom !== $petaBaru) {
                    $galat[] = 'Susunan kolom berubah di tengah file (baris Excel ke-'.($indeks + 1).').';
                }
                continue;
            }

            // Baris transaksi: kolom "No." berisi angka
            if ($kolom !== null && isset($row[$kolom['no']]) && is_numeric($row[$kolom['no']])) {
                $baris[] = $this->bacaBarisXls($row, $kolom);
                continue;
            }

            // Selain itu: baris kosong, judul halaman, atau info header (rekening, periode, saldo, total)
            $this->catatHeaderXls($row, $header);
        }

        if ($kolom === null) {
            $galat[] = 'Judul kolom transaksi (No., Post Date, Branch, Journal No., Description, Amount, Db/Cr, Balance) '
                .'tidak ditemukan. Ini bukan format .xls BNI yang dikenal.';
        } elseif ($baris === []) {
            $galat[] = 'Tidak ada baris transaksi di file.';
        }

        $rekeningFile = $this->satuNilai(
            $header['rekening'],
            fn (array $n) => preg_match('/^\s*(\d{6,})/', (string) $n[0], $m) === 1 ? $m[1] : null,
            'Nomor rekening', $galat
        );
        $periode = $this->satuNilai(
            $header['periode'],
            fn (array $n) => (count($n) >= 2 && is_numeric($n[0]) && is_numeric($n[array_key_last($n)]))
                ? [(int) floor($n[0]), (int) floor($n[array_key_last($n)])] : null,
            'Periode', $galat
        );
        $saldoAwal = $this->satuNilai($header['saldoAwal'], fn (array $n) => Uang::dariNilai($n[0]), 'Saldo awal', $galat);
        $totalDebit = $this->satuNilai($header['debit'], fn (array $n) => Uang::dariNilai($n[0]), 'Total debit', $galat);
        $totalKredit = $this->satuNilai($header['kredit'], fn (array $n) => Uang::dariNilai($n[0]), 'Total kredit', $galat);

        if ($kolom !== null) {
            if ($rekeningFile === null) {
                $peringatan[] = 'Nomor rekening tidak ditemukan di header file; pastikan rekening yang dipilih benar.';
            } elseif ($rekeningFile !== $this->nomorRekening) {
                $galat[] = "File ini untuk rekening {$rekeningFile}, bukan rekening yang dipilih ({$this->nomorRekening}).";
            }
            if ($saldoAwal === null) {
                $peringatan[] = 'Saldo awal tidak ditemukan di header; rekonsiliasi saldo tidak bisa lengkap.';
            }
            if ($totalDebit === null || $totalKredit === null) {
                $peringatan[] = 'Total debit/kredit tidak ditemukan di header; pemeriksaan total tidak bisa dilakukan.';
            }
        }

        $this->tandaiDuplikat($baris);

        return new HasilParse(
            format: $format,
            nomorRekeningFile: $rekeningFile,
            periodeAwal: $periode !== null ? $this->tanggalDariHari($periode[0]) : null,
            periodeAkhir: $periode !== null ? $this->tanggalDariHari($periode[1]) : null,
            saldoAwal: $saldoAwal,
            saldoAkhir: $this->saldoBarisTerakhir($baris),
            totalDebitFile: $totalDebit,
            totalKreditFile: $totalKredit,
            baris: $baris,
            peringatan: $peringatan,
            galat: $galat,
        );
    }

    /**
     * Mencari posisi kolom dari baris judul. Null bila baris ini bukan baris judul lengkap.
     *
     * @return array<string,int>|null
     */
    private function petaKolomXls(array $row): ?array
    {
        $teks = array_map(fn ($v) => is_string($v) ? trim($v) : null, $row);
        $peta = [];

        foreach (self::JUDUL_KOLOM_XLS as $kunci => $judul) {
            $indeks = array_search($judul, $teks, true);
            if ($indeks === false) {
                return null;
            }
            $peta[$kunci] = $indeks;
        }

        return $peta;
    }

    /**
     * Mencatat info header (Account, Period, Beginning Balance, Total Debit, Total Credit).
     * Nilainya diambil dari sel-sel di kanan labelnya.
     */
    private function catatHeaderXls(array $row, array &$header): void
    {
        $teks = array_map(fn ($v) => is_string($v) ? trim($v) : null, $row);
        $label = [
            'Account' => 'rekening',
            'Period' => 'periode',
            'Beginning Balance' => 'saldoAwal',
            'Total Debit' => 'debit',
            'Total Credit' => 'kredit',
        ];

        foreach ($label as $nama => $kunci) {
            $indeks = array_search($nama, $teks, true);
            if ($indeks === false) {
                continue;
            }

            $nilai = [];
            foreach (array_slice($row, $indeks + 1) as $sel) {
                if ($sel === null || $sel === '' || (is_string($sel) && in_array(trim($sel), [':', '-'], true))) {
                    continue;
                }
                $nilai[] = $sel;
            }

            if ($nilai !== []) {
                $header[$kunci][] = $nilai;
            }
        }
    }

    /**
     * Header muncul berulang di tiap halaman. Semua kemunculan harus sama; kalau berbeda,
     * file dianggap tidak konsisten.
     *
     * @param  list<array<int,mixed>>  $daftar
     */
    private function satuNilai(array $daftar, callable $ubah, string $nama, array &$galat): mixed
    {
        $nilai = [];
        foreach ($daftar as $n) {
            $hasil = $ubah($n);
            if ($hasil !== null) {
                $nilai[json_encode($hasil)] = $hasil;
            }
        }

        if (count($nilai) > 1) {
            $galat[] = $nama.' di header berbeda-beda antar halaman. File tidak konsisten.';
        }

        return $nilai === [] ? null : reset($nilai);
    }

    private function bacaBarisXls(array $row, array $k): BarisTransaksi
    {
        $masalah = [];
        $peringatan = [];

        $tanggal = $this->tanggalDariSerial($row[$k['tanggal']] ?? null);
        if ($tanggal === null) {
            $masalah[] = 'Tanggal transaksi tidak valid.';
        }

        $jumlah = Uang::dariNilai($row[$k['jumlah']] ?? null);
        if ($jumlah === null || $jumlah <= 0) {
            $masalah[] = 'Nominal tidak valid.';
            $jumlah = null;
        }

        $kode = strtoupper(trim((string) ($row[$k['arah']] ?? '')));
        if (! in_array($kode, ['D', 'C'], true)) {
            $masalah[] = 'Kode Db/Cr tidak dikenal.';
            $kode = null;
        }

        $saldo = Uang::dariNilai($row[$k['saldo']] ?? null);
        if ($saldo === null) {
            $peringatan[] = 'Saldo baris kosong atau tidak valid.';
        }

        $jurnalMentah = $row[$k['jurnal']] ?? null;
        if (is_int($jurnalMentah) || is_float($jurnalMentah)) {
            $peringatan[] = 'Nomor jurnal terbaca sebagai angka; nol di depan mungkin hilang.';
        }

        $debit = $kredit = null;
        if ($jumlah !== null && $kode !== null) {
            $debit = $kode === 'D' ? $jumlah : 0;
            $kredit = $kode === 'C' ? $jumlah : 0;
        }

        $kanal = trim((string) ($row[$k['kanal']] ?? ''));

        return $this->buatBaris(
            nomorBaris: (int) $row[$k['no']],
            mentah: array_filter($row, fn ($v) => $v !== null && $v !== ''),
            tanggal: $tanggal,
            jurnal: trim((string) $jurnalMentah),
            kanal: $kanal !== '' ? $kanal : null,
            deskripsi: trim((string) ($row[$k['deskripsi']] ?? '')),
            debit: $debit,
            kredit: $kredit,
            saldo: $saldo,
            masalah: $masalah,
            peringatan: $peringatan,
        );
    }

    // ------------------------------------------------------------------ .csv

    public function parseCsv(string $path): HasilParse
    {
        $pegangan = @fopen($path, 'rb');
        if ($pegangan === false) {
            return $this->hasilGalat('csv', 'File tidak dapat dibuka.');
        }

        $rows = [];
        try {
            // Pemisah koma dan kutip dipaksa eksplisit (tanpa karakter escape), tidak ditebak otomatis:
            // deteksi otomatis pernah salah menganggap spasi di deskripsi sebagai pemisah kolom.
            while (($row = fgetcsv($pegangan, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
        } finally {
            fclose($pegangan);
        }

        return $this->dariBarisCsv($rows);
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows  hasil fgetcsv
     */
    public function dariBarisCsv(array $rows): HasilParse
    {
        $galat = [];
        $baris = [];
        $judulDitemukan = false;
        $urut = 0;

        foreach ($rows as $row) {
            $row = array_map(fn ($v) => $this->teksUtf8((string) $v), $row);
            if (count(array_filter($row, fn ($v) => trim($v) !== '')) === 0) {
                continue; // baris kosong
            }

            if (! $judulDitemukan) {
                $judul = array_map('trim', array_slice($row, 0, count(self::JUDUL_KOLOM_CSV)));
                $judul[0] = ltrim($judul[0], "\xEF\xBB\xBF"); // buang BOM UTF-8 bila ada
                if ($judul !== self::JUDUL_KOLOM_CSV) {
                    $galat[] = 'Judul kolom CSV tidak dikenal. Diharapkan: '.implode(', ', self::JUDUL_KOLOM_CSV).'.';
                    break;
                }
                $judulDitemukan = true;
                continue;
            }

            $baris[] = $this->bacaBarisCsv(++$urut, $row);
        }

        if (! $judulDitemukan && $galat === []) {
            $galat[] = 'File kosong atau tidak memiliki judul kolom.';
        } elseif ($judulDitemukan && $baris === []) {
            $galat[] = 'Tidak ada baris transaksi di file.';
        }

        $this->tandaiDuplikat($baris);

        $tanggal = array_values(array_filter(array_map(fn (BarisTransaksi $b) => $b->tanggalTransaksi, $baris)));
        $awal = $tanggal !== [] ? min($tanggal)->setTime(0, 0) : null;
        $akhir = $tanggal !== [] ? max($tanggal)->setTime(0, 0) : null;

        return new HasilParse(
            format: 'csv',
            nomorRekeningFile: null,
            periodeAwal: $awal,
            periodeAkhir: $akhir,
            saldoAwal: null,
            saldoAkhir: null,
            totalDebitFile: null,
            totalKreditFile: null,
            baris: $baris,
            peringatan: $galat === [] ? [
                'CSV BNI tidak memuat nomor rekening; pastikan rekening yang dipilih benar.',
                'CSV BNI tidak memuat saldo dan total, jadi rekonsiliasi saldo tidak bisa dilakukan. Gunakan file .xls dari bank.',
            ] : [],
            galat: $galat,
        );
    }

    private function bacaBarisCsv(int $nomorBaris, array $row): BarisTransaksi
    {
        $mentah = [];
        foreach ($row as $i => $nilai) {
            if (trim($nilai) !== '') {
                $mentah[$i] = $nilai;
            }
        }

        // Koma penutup di ujung baris menghasilkan satu kolom kosong ekstra; abaikan
        while (count($row) > count(self::JUDUL_KOLOM_CSV) && trim((string) end($row)) === '') {
            array_pop($row);
        }

        if (count($row) !== count(self::JUDUL_KOLOM_CSV)) {
            return $this->buatBaris(
                nomorBaris: $nomorBaris, mentah: $mentah, tanggal: null, jurnal: '', kanal: null, deskripsi: null,
                debit: null, kredit: null, saldo: null,
                masalah: ['Jumlah kolom tidak sesuai (kemungkinan koma di dalam deskripsi tanpa tanda kutip).'],
                peringatan: [],
            );
        }

        $masalah = [];

        $tanggal = $this->tanggalDariTeks($row[0], 'd/m/y H.i.s');
        if ($tanggal === null) {
            $masalah[] = 'Tanggal transaksi tidak valid.';
        }

        $debit = Uang::dariTeks($row[5]);
        $kredit = Uang::dariTeks($row[6]);
        if ($debit === null || $kredit === null || $debit < 0 || $kredit < 0) {
            $masalah[] = 'Nominal debit/kredit tidak valid.';
            $debit = $kredit = null;
        } elseif ($debit > 0 && $kredit > 0) {
            $masalah[] = 'Debit dan kredit terisi bersamaan.';
            $debit = $kredit = null;
        } elseif ($debit === 0 && $kredit === 0) {
            $masalah[] = 'Debit dan kredit sama-sama nol.';
            $debit = $kredit = null;
        }

        $cabang = trim($row[2]);

        return $this->buatBaris(
            nomorBaris: $nomorBaris,
            mentah: $mentah,
            tanggal: $tanggal,
            jurnal: trim($row[3]),
            kanal: $cabang !== '' ? 'Cabang '.$cabang : null,
            deskripsi: trim($row[4]),
            debit: $debit,
            kredit: $kredit,
            saldo: null,
            masalah: $masalah,
            peringatan: [],
        );
    }

    // ------------------------------------------------------------------ bagian bersama

    /**
     * Menyusun satu BarisTransaksi: pemeriksaan umum, kunci unik, dan status.
     *
     * @param  list<string>  $masalah  alasan baris tidak valid
     * @param  list<string>  $peringatan  hal yang perlu diperhatikan tapi baris tetap bisa dipakai
     */
    private function buatBaris(
        int $nomorBaris,
        array $mentah,
        ?DateTimeImmutable $tanggal,
        string $jurnal,
        ?string $kanal,
        ?string $deskripsi,
        ?int $debit,
        ?int $kredit,
        ?int $saldo,
        array $masalah,
        array $peringatan,
    ): BarisTransaksi {
        if ($jurnal === '') {
            $peringatan[] = 'Nomor jurnal kosong.';
        }
        if ($deskripsi === null || $deskripsi === '') {
            $peringatan[] = 'Deskripsi kosong.';
        }

        $kunci = null;
        if ($tanggal !== null && $debit !== null && $kredit !== null) {
            $kunci = KunciUnik::hitung(
                $this->nomorRekening,
                $tanggal,
                $jurnal,
                $debit > 0 ? BarisTransaksi::ARAH_DEBIT : BarisTransaksi::ARAH_KREDIT,
                $debit > 0 ? $debit : $kredit,
            );
        }

        if ($masalah !== []) {
            $status = BarisTransaksi::TIDAK_VALID;
        } elseif ($peringatan !== []) {
            $status = BarisTransaksi::PERINGATAN;
        } else {
            $status = BarisTransaksi::VALID;
        }

        return new BarisTransaksi(
            nomorBaris: $nomorBaris,
            dataMentah: $mentah,
            tanggalTransaksi: $tanggal,
            nomorReferensi: $jurnal !== '' ? $jurnal : null,
            kanal: $kanal,
            deskripsi: $deskripsi,
            debit: $debit,
            kredit: $kredit,
            saldo: $saldo,
            status: $status,
            pesan: $masalah === [] && $peringatan === [] ? null : implode(' ', [...$masalah, ...$peringatan]),
            kunciUnik: $kunci,
        );
    }

    /**
     * Baris dengan kunci unik yang sudah muncul sebelumnya di file yang sama ditandai duplikat.
     *
     * @param  list<BarisTransaksi>  $baris
     */
    private function tandaiDuplikat(array $baris): void
    {
        $terlihat = [];

        foreach ($baris as $b) {
            if ($b->kunciUnik === null) {
                continue;
            }

            if (isset($terlihat[$b->kunciUnik])) {
                $b->status = BarisTransaksi::DUPLIKAT;
                $b->pesan = trim(($b->pesan !== null ? $b->pesan.' ' : '')
                    .'Transaksi sama dengan baris nomor '.$terlihat[$b->kunciUnik].' di file ini.');
            } else {
                $terlihat[$b->kunciUnik] = $b->nomorBaris;
            }
        }
    }

    /**
     * @param  list<BarisTransaksi>  $baris
     */
    private function saldoBarisTerakhir(array $baris): ?int
    {
        for ($i = count($baris) - 1; $i >= 0; $i--) {
            if ($baris[$i]->saldo !== null) {
                return $baris[$i]->saldo;
            }
        }

        return null;
    }

    /**
     * Angka serial Excel (hari sejak 30 Des 1899, pecahan = jam) -> tanggal-waktu zona Jakarta.
     */
    private function tanggalDariSerial(mixed $serial): ?DateTimeImmutable
    {
        if (is_string($serial) && is_numeric($serial)) {
            $serial = (float) $serial;
        }
        if (! is_int($serial) && ! is_float($serial)) {
            return null;
        }
        // Batas wajar tahun 2000-2099; di luar itu hampir pasti salah baca
        if ($serial < 36526 || $serial >= 73051) {
            return null;
        }

        $hari = (int) floor($serial);
        $detik = (int) round(($serial - $hari) * 86400);

        return $this->tanggalDariHari($hari)->modify("+{$detik} seconds");
    }

    private function tanggalDariHari(int $hari): DateTimeImmutable
    {
        return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone(self::ZONA)))
            ->modify("+{$hari} days");
    }

    private function tanggalDariTeks(string $teks, string $format): ?DateTimeImmutable
    {
        $tanggal = DateTimeImmutable::createFromFormat('!'.$format, trim($teks), new DateTimeZone(self::ZONA));
        $kesalahan = DateTimeImmutable::getLastErrors();

        if ($tanggal === false || ($kesalahan !== false && ($kesalahan['warning_count'] + $kesalahan['error_count']) > 0)) {
            return null;
        }

        return $tanggal;
    }

    private function teksUtf8(string $teks): string
    {
        return mb_check_encoding($teks, 'UTF-8') ? $teks : mb_convert_encoding($teks, 'UTF-8', 'Windows-1252');
    }

    private function hasilGalat(string $format, string $pesan): HasilParse
    {
        return new HasilParse(
            format: $format,
            nomorRekeningFile: null,
            periodeAwal: null,
            periodeAkhir: null,
            saldoAwal: null,
            saldoAkhir: null,
            totalDebitFile: null,
            totalKreditFile: null,
            baris: [],
            peringatan: [],
            galat: [$pesan],
        );
    }
}
