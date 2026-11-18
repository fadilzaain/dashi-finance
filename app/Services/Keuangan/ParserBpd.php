<?php

namespace App\Services\Keuangan;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Pembaca file mutasi rekening BPD.
 *
 * Susunan file (CSV maupun Excel):
 *  - 6 baris info di atas, tiap baris satu sel berbentuk "Label : nilai":
 *      Account : 0111009133 / RSUD JOMBANG ( IDR )
 *      Period  : 01/09/2026 - 30/09/2026
 *      Starting Balance : IDR 842804408.36
 *      Ending Balance : IDR 188230039.50
 *  - Baris judul kolom: No, Posting Date, Effective Date, Account, Name, Description,
 *    Crrency (ejaan asli bank), Debit, Credit, Balance, Reference No.
 *  - Baris transaksi (kolom "No" berisi angka). Angka memakai titik desimal tanpa pemisah ribuan.
 *  - Satu baris total di bawah (kolom "No" kosong): total debit, total kredit, saldo akhir.
 *
 * Aturan khusus BPD:
 *  - CSV: deskripsi bisa berisi koma tanpa tanda kutip sehingga kolom bergeser. Baris seperti itu
 *    disambung kembali di kolom Description dan diberi peringatan. Posisi kolom dicari dari judul,
 *    dan kolom di kanan Description (Crrency s/d Reference No) dianggap berurutan.
 *  - Excel: ditolak bila ada baris yang kolom Debit-nya berisi teks (mis. "IDR"), atau nomor
 *    referensinya terbaca sebagai angka. Itu ciri file CSV yang dibuka lalu disimpan ulang di Excel
 *    (kolom bergeser, nol di depan hilang). File asli dari bank tidak punya masalah ini.
 *  - Kolom "Account" di baris transaksi TIDAK dipakai (isinya "null" atau rekening lawan transaksi,
 *    bukan rekening ini). Rekening diambil dari baris info "Account : ...".
 *
 * Kelas ini hanya MEMBACA dan MEMERIKSA. Tidak menulis ke database.
 */
final class ParserBpd
{
    // Zona waktu jam transaksi di file bank (tidak bergantung pada zona waktu aplikasi)
    private const ZONA = 'Asia/Jakarta';

    // Judul kolom tabel transaksi. Posisi kolom dicari dari judul, bukan ditulis mati.
    // Setiap kolom boleh punya beberapa ejaan (bank menulis "Crrency").
    private const JUDUL_KOLOM = [
        'no' => ['No'],
        'tanggal' => ['Posting Date'],
        'efektif' => ['Effective Date'],
        'rekening' => ['Account'],
        'nama' => ['Name'],
        'deskripsi' => ['Description'],
        'mataUang' => ['Crrency', 'Currency'],
        'debit' => ['Debit'],
        'kredit' => ['Credit'],
        'saldo' => ['Balance'],
        'referensi' => ['Reference No'],
    ];

    public function __construct(private readonly string $nomorRekening)
    {
    }

    // ------------------------------------------------------------------ CSV

    public function parseCsv(string $path): HasilParse
    {
        $pegangan = @fopen($path, 'rb');
        if ($pegangan === false) {
            return $this->hasilGalat('csv', 'File tidak dapat dibuka.');
        }

        $rows = [];
        try {
            // Pemisah koma dan kutip dipaksa eksplisit (tanpa karakter escape), tidak ditebak otomatis.
            while (($row = fgetcsv($pegangan, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
        } finally {
            fclose($pegangan);
        }

        return $this->dariBaris($rows, 'csv');
    }

    // ------------------------------------------------------------------ Excel

    public function parseExcel(string $path): HasilParse
    {
        try {
            $jenis = IOFactory::identify($path);
            if (! in_array($jenis, ['Xls', 'Xlsx'], true)) {
                return $this->hasilGalat('xlsx', 'File bukan Excel (.xls/.xlsx). Terdeteksi sebagai: '.$jenis.'.');
            }

            $pembaca = IOFactory::createReader($jenis);
            // Data mentah apa adanya: tanpa hitung rumus, tanpa format (tanggal tetap angka serial)
            $pembaca->setReadDataOnly(true);
            $rows = $pembaca->load($path)->getSheet(0)->toArray(null, false, false, false);
        } catch (Throwable) {
            return $this->hasilGalat('xlsx', 'File Excel tidak dapat dibaca atau rusak.');
        }

        return $this->dariBaris($rows, strtolower($jenis));
    }

    // ------------------------------------------------------------------ inti pembacaan

    /**
     * Memeriksa isi file yang sudah dibaca menjadi array baris x kolom.
     * Dipisah dari parseCsv()/parseExcel() agar logikanya bisa diuji tanpa membuka file.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  string  $format  csv | xls | xlsx
     */
    public function dariBaris(array $rows, string $format): HasilParse
    {
        $adalahCsv = $format === 'csv';
        $galat = [];
        $peringatan = [];
        $kolom = null;
        $info = [];
        $footer = [];
        $baris = [];
        $rusak = [];          // nomor baris Excel yang kolomnya bergeser
        $refAngka = [];       // nomor baris Excel yang nomor referensinya terbaca angka
        $dilewati = [];       // nomor baris file (urutan ke-n) yang bukan transaksi/info/total

        foreach ($rows as $indeks => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row = array_values($row);
            if ($adalahCsv) {
                $row = array_map(fn ($v) => $this->teksUtf8((string) $v), $row);
                if ($indeks === 0 && isset($row[0])) {
                    $row[0] = ltrim($row[0], "\xEF\xBB\xBF"); // buang BOM UTF-8 bila ada
                }
            }
            if ($this->barisKosong($row)) {
                continue;
            }

            $nomorExcel = $indeks + 1;

            // Baris judul kolom
            $petaBaru = $this->petaKolom($row);
            if ($petaBaru !== null) {
                if ($kolom === null) {
                    $kolom = $petaBaru;
                } elseif ($kolom !== $petaBaru) {
                    $galat[] = 'Susunan kolom berubah di tengah file (baris ke-'.$nomorExcel.').';
                }
                continue;
            }

            // Sebelum judul kolom: baris info (rekening, periode, saldo awal/akhir)
            if ($kolom === null) {
                $this->catatInfo($row, $info);
                continue;
            }

            // Baris transaksi: kolom "No" berisi angka
            $no = $row[$kolom['no']] ?? null;
            if ($no !== null && $no !== '' && is_numeric($no)) {
                $baris[] = $this->bacaBaris($row, $kolom, $adalahCsv, $nomorExcel, $rusak, $refAngka);
                continue;
            }

            // Baris total: kolom "No" kosong, tapi Debit, Credit, Balance berisi angka
            $total = $this->bacaFooter($row, $kolom);
            if ($total !== null) {
                $footer[] = $total;
                continue;
            }

            $dilewati[] = $nomorExcel;
        }

        // ---- Pemeriksaan struktur
        if ($kolom === null) {
            $galat[] = 'Judul kolom transaksi (No, Posting Date, Effective Date, Account, Name, Description, '
                .'Crrency, Debit, Credit, Balance, Reference No) tidak ditemukan. Ini bukan format BPD yang dikenal.';
        } elseif ($baris === []) {
            $galat[] = 'Tidak ada baris transaksi di file.';
        }

        if ($rusak !== []) {
            $galat[] = 'File Excel BPD rusak: kolom bergeser di '.count($rusak).' baris (kolom Debit berisi teks, '
                .'contoh baris Excel ke-'.implode(', ', array_slice($rusak, 0, 5)).'). '
                .'Kemungkinan file CSV dibuka lalu disimpan ulang di Excel. Unggah file asli dari bank.';
        }
        if ($refAngka !== []) {
            $galat[] = 'File Excel BPD: nomor referensi terbaca sebagai angka di '.count($refAngka).' baris '
                .'(contoh baris Excel ke-'.implode(', ', array_slice($refAngka, 0, 5)).'), nol di depan mungkin hilang. '
                .'Kemungkinan file disimpan ulang di Excel. Unggah file asli dari bank.';
        }
        if (count($footer) > 1) {
            $galat[] = 'Ditemukan lebih dari satu baris total di file. File tidak konsisten.';
        }

        $rekeningFile = $this->tunggal(
            $info['Account'] ?? [],
            fn (string $v) => preg_match('/^(\d{6,})/', $v, $m) === 1 ? $m[1] : null,
            'Nomor rekening', $galat
        );
        $periode = $this->tunggal(
            $info['Period'] ?? [],
            function (string $v) {
                if (preg_match('#(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})#', $v, $m) !== 1) {
                    return null;
                }
                $a = $this->tanggalDariTeks($m[1], 'd/m/Y');
                $b = $this->tanggalDariTeks($m[2], 'd/m/Y');

                return ($a !== null && $b !== null) ? [$a, $b] : null;
            },
            'Periode', $galat
        );
        $saldoAwal = $this->tunggal($info['Starting Balance'] ?? [], fn (string $v) => $this->saldoInfo($v), 'Saldo awal', $galat);
        $saldoAkhirInfo = $this->tunggal($info['Ending Balance'] ?? [], fn (string $v) => $this->saldoInfo($v), 'Saldo akhir', $galat);

        $totalDebit = count($footer) === 1 ? $footer[0]['debit'] : null;
        $totalKredit = count($footer) === 1 ? $footer[0]['kredit'] : null;
        $saldoFooter = count($footer) === 1 ? $footer[0]['saldo'] : null;

        // Saldo akhir di header dan di baris total harus sama bila keduanya ada
        if ($saldoAkhirInfo !== null && $saldoFooter !== null && $saldoAkhirInfo !== $saldoFooter) {
            $galat[] = 'Saldo akhir di header ('.Uang::rupiah($saldoAkhirInfo).') berbeda dari baris total ('
                .Uang::rupiah($saldoFooter).'). File tidak konsisten.';
        }
        $saldoAkhir = $saldoAkhirInfo ?? $saldoFooter;

        if ($kolom !== null) {
            if ($rekeningFile === null) {
                $peringatan[] = 'Nomor rekening tidak ditemukan di header file; pastikan rekening yang dipilih benar.';
            } elseif ($rekeningFile !== $this->nomorRekening) {
                $galat[] = "File ini untuk rekening {$rekeningFile}, bukan rekening yang dipilih ({$this->nomorRekening}).";
            }
            if ($saldoAwal === null) {
                $peringatan[] = 'Saldo awal tidak ditemukan di header; rekonsiliasi saldo tidak bisa lengkap.';
            }
            if ($saldoAkhir === null) {
                $peringatan[] = 'Saldo akhir tidak ditemukan di header maupun baris total.';
            }
            if ($totalDebit === null || $totalKredit === null) {
                $peringatan[] = 'Baris total (debit/kredit) tidak ditemukan; pemeriksaan total tidak bisa dilakukan.';
            }
            if ($dilewati !== []) {
                $peringatan[] = count($dilewati).' baris tidak dikenali dan dilewati (contoh baris ke-'
                    .implode(', ', array_slice($dilewati, 0, 5)).').';
            }
            if (! $adalahCsv) {
                $peringatan[] = 'File Excel BPD hanya aman dipakai bila rekonsiliasi cocok. Utamakan CSV asli dari bank.';
            }
        }

        // Nomor urut file harus unik (dipakai sebagai nomor_baris di staging)
        $nomorTerlihat = [];
        foreach ($baris as $b) {
            if (isset($nomorTerlihat[$b->nomorBaris])) {
                $galat[] = 'Nomor urut '.$b->nomorBaris.' muncul lebih dari sekali di file. File tidak konsisten.';
                break;
            }
            $nomorTerlihat[$b->nomorBaris] = true;
        }

        $this->tandaiDuplikat($baris);

        // Periode: dari header; bila tidak ada, dari tanggal transaksi
        $tanggalBaris = array_values(array_filter(array_map(fn (BarisTransaksi $b) => $b->tanggalTransaksi, $baris)));
        $awal = $periode !== null ? $periode[0] : ($tanggalBaris !== [] ? min($tanggalBaris)->setTime(0, 0) : null);
        $akhir = $periode !== null ? $periode[1] : ($tanggalBaris !== [] ? max($tanggalBaris)->setTime(0, 0) : null);

        if ($periode !== null) {
            $diLuar = 0;
            foreach ($tanggalBaris as $t) {
                $hari = $t->setTime(0, 0);
                if ($hari < $awal || $hari > $akhir) {
                    $diLuar++;
                }
            }
            if ($diLuar > 0) {
                $peringatan[] = "{$diLuar} transaksi bertanggal di luar periode file.";
            }
        }

        return new HasilParse(
            format: $format,
            nomorRekeningFile: $rekeningFile,
            periodeAwal: $awal,
            periodeAkhir: $akhir,
            saldoAwal: $saldoAwal,
            saldoAkhir: $saldoAkhir,
            totalDebitFile: $totalDebit,
            totalKreditFile: $totalKredit,
            baris: $baris,
            peringatan: $peringatan,
            galat: $galat,
        );
    }

    // ------------------------------------------------------------------ header, info, total

    /**
     * Mencari posisi kolom dari baris judul. Null bila baris ini bukan baris judul lengkap.
     *
     * @return array<string,int>|null
     */
    private function petaKolom(array $row): ?array
    {
        $teks = array_map(fn ($v) => is_string($v) ? trim($v) : null, $row);
        $peta = [];

        foreach (self::JUDUL_KOLOM as $kunci => $ejaan) {
            $ketemu = false;
            foreach ($ejaan as $judul) {
                $indeks = array_search($judul, $teks, true);
                if ($indeks !== false) {
                    $peta[$kunci] = $indeks;
                    $ketemu = true;
                    break;
                }
            }
            if (! $ketemu) {
                return null;
            }
        }

        return $peta;
    }

    /**
     * Mencatat baris info "Label : nilai" (sel pertama yang terisi).
     */
    private function catatInfo(array $row, array &$info): void
    {
        foreach ($row as $sel) {
            $teks = trim((string) $sel);
            if ($teks === '') {
                continue;
            }

            if (preg_match('/^(Account|Period|Starting Balance|Ending Balance)\s*:\s*(.+)$/u', $teks, $m) === 1) {
                $info[$m[1]][] = trim($m[2]);
            }

            return; // hanya sel pertama yang terisi
        }
    }

    /**
     * Nilai saldo di baris info, contoh "IDR 842804408.36" -> sen. Null bila bukan angka valid.
     */
    private function saldoInfo(string $nilai): ?int
    {
        return Uang::dariTeks(preg_replace('/^[A-Za-z]{3}\s*/', '', trim($nilai)) ?? '');
    }

    /**
     * Baris total di bawah transaksi. Null bila baris ini bukan baris total.
     *
     * @return array{debit:int, kredit:int, saldo:int}|null
     */
    private function bacaFooter(array $row, array $k): ?array
    {
        // Kolom teks (nama, deskripsi, referensi) harus kosong
        foreach (['rekening', 'nama', 'deskripsi', 'referensi'] as $kunci) {
            $nilai = $row[$k[$kunci]] ?? null;
            if ($nilai !== null && trim((string) $nilai) !== '') {
                return null;
            }
        }

        $debit = Uang::dariNilai($row[$k['debit']] ?? null);
        $kredit = Uang::dariNilai($row[$k['kredit']] ?? null);
        $saldo = Uang::dariNilai($row[$k['saldo']] ?? null);

        if ($debit === null || $kredit === null || $saldo === null) {
            return null;
        }

        return ['debit' => $debit, 'kredit' => $kredit, 'saldo' => $saldo];
    }

    /**
     * Info bisa muncul lebih dari sekali; semua kemunculan harus sama.
     *
     * @param  list<string>  $daftar
     */
    private function tunggal(array $daftar, callable $ubah, string $nama, array &$galat): mixed
    {
        $nilai = [];
        foreach ($daftar as $v) {
            $hasil = $ubah($v);
            if ($hasil !== null) {
                $kunci = is_array($hasil)
                    ? implode('|', array_map(fn ($x) => $x instanceof DateTimeImmutable ? $x->format('Y-m-d') : (string) $x, $hasil))
                    : (string) $hasil;
                $nilai[$kunci] = $hasil;
            }
        }

        if (count($nilai) > 1) {
            $galat[] = $nama.' di header berbeda-beda. File tidak konsisten.';
        }

        return $nilai === [] ? null : reset($nilai);
    }

    // ------------------------------------------------------------------ baris transaksi

    /**
     * @param  list<int>  $rusak  diisi nomor baris Excel yang kolom Debit-nya berisi teks (khusus Excel)
     * @param  list<int>  $refAngka  diisi nomor baris Excel yang nomor referensinya terbaca angka (khusus Excel)
     */
    private function bacaBaris(array $row, array $k, bool $adalahCsv, int $nomorExcel, array &$rusak, array &$refAngka): BarisTransaksi
    {
        $masalah = [];
        $peringatan = [];
        $mentah = array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '');

        // CSV: deskripsi berkoma tanpa kutip membuat kolom bergeser ke kanan. Sambung kembali di
        // kolom Description. Dikenali dari mata uang ("IDR") yang tidak ada di posisinya.
        if ($adalahCsv) {
            $lebar = max($k) + 1;
            $mu = $k['mataUang'];
            if (count($row) > $lebar && strtoupper(trim((string) ($row[$mu] ?? ''))) !== 'IDR') {
                $geser = count($row) - $lebar;
                if (strtoupper(trim((string) ($row[$mu + $geser] ?? ''))) === 'IDR') {
                    $d = $k['deskripsi'];
                    $gabung = implode(',', array_slice($row, $d, $geser + 1));
                    $row = array_merge(array_slice($row, 0, $d), [$gabung], array_slice($row, $d + $geser + 1));
                    $peringatan[] = "Kolom bergeser {$geser} (koma di dalam deskripsi tanpa tanda kutip); disambung kembali.";
                }
            }
        }

        $tanggal = $this->tanggalTransaksi($row[$k['tanggal']] ?? null);
        if ($tanggal === null) {
            $masalah[] = 'Tanggal transaksi tidak valid.';
        }

        $mataUang = strtoupper(trim((string) ($row[$k['mataUang']] ?? '')));
        if ($mataUang !== 'IDR') {
            $masalah[] = 'Mata uang bukan IDR (kolom kemungkinan bergeser).';
        }

        $sel = [
            'debit' => $row[$k['debit']] ?? null,
            'kredit' => $row[$k['kredit']] ?? null,
        ];
        $debit = Uang::dariNilai($sel['debit']);
        $kredit = Uang::dariNilai($sel['kredit']);

        if (! $adalahCsv && is_string($sel['debit']) && trim($sel['debit']) !== '' && $debit === null) {
            $rusak[] = $nomorExcel;
        }

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

        $saldo = Uang::dariNilai($row[$k['saldo']] ?? null);
        if ($saldo === null) {
            $peringatan[] = 'Saldo baris kosong atau tidak valid.';
        }

        $refMentah = $row[$k['referensi']] ?? null;
        if (is_int($refMentah) || is_float($refMentah)) {
            $refAngka[] = $nomorExcel;
        }

        return $this->buatBaris(
            nomorBaris: (int) $row[$k['no']],
            mentah: $mentah,
            tanggal: $tanggal,
            referensi: trim((string) $refMentah),
            deskripsi: trim((string) ($row[$k['deskripsi']] ?? '')),
            debit: $debit,
            kredit: $kredit,
            saldo: $saldo,
            masalah: $masalah,
            peringatan: $peringatan,
        );
    }

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
        string $referensi,
        string $deskripsi,
        ?int $debit,
        ?int $kredit,
        ?int $saldo,
        array $masalah,
        array $peringatan,
    ): BarisTransaksi {
        if ($referensi === '') {
            $peringatan[] = 'Nomor referensi kosong.';
        }
        if ($deskripsi === '') {
            $peringatan[] = 'Deskripsi kosong.';
        }

        $kunci = null;
        if ($tanggal !== null && $debit !== null && $kredit !== null) {
            $kunci = KunciUnik::hitung(
                $this->nomorRekening,
                $tanggal,
                $referensi,
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
            nomorReferensi: $referensi !== '' ? $referensi : null,
            kanal: null, // file BPD tidak memuat kanal
            deskripsi: $deskripsi !== '' ? $deskripsi : null,
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

    // ------------------------------------------------------------------ tanggal dan utilitas

    /**
     * Tanggal transaksi: teks "dd/mm/yyyy hh:mm:ss" (CSV) atau angka serial Excel.
     */
    private function tanggalTransaksi(mixed $nilai): ?DateTimeImmutable
    {
        if (is_int($nilai) || is_float($nilai)) {
            return $this->tanggalDariSerial($nilai);
        }
        if (! is_string($nilai) || trim($nilai) === '') {
            return null;
        }
        if (is_numeric(trim($nilai))) {
            return $this->tanggalDariSerial((float) trim($nilai));
        }

        return $this->tanggalDariTeks($nilai, 'd/m/Y H:i:s');
    }

    private function tanggalDariSerial(int|float $serial): ?DateTimeImmutable
    {
        // Batas wajar tahun 2000-2099; di luar itu hampir pasti salah baca
        if ($serial < 36526 || $serial >= 73051) {
            return null;
        }

        $hari = (int) floor($serial);
        $detik = (int) round(($serial - $hari) * 86400);

        return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone(self::ZONA)))
            ->modify("+{$hari} days")
            ->modify("+{$detik} seconds");
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

    private function barisKosong(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
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