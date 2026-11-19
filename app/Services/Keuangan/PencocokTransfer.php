<?php

namespace App\Services\Keuangan;

use App\Models\KeuMutasiBank;
use InvalidArgumentException;

/**
 * Mencari pasangan transfer internal: debit di satu rekening dan kredit di rekening LAIN dengan
 * nominal sama persis (sampai sen), kredit terjadi dalam jendela waktu sesudah debit.
 *
 * Murni hitungan di memori: tidak menyentuh database, mudah diuji.
 *
 * Aturan (sesuai PRD 7.3 dan temuan analisis 4.3):
 *  - Deskripsi TIDAK dilihat. Debit Rp600 juta yang hanya tertulis "TARIK CHQ" tetap bisa terpasang.
 *  - Hanya pasangan yang pasti yang dipasangkan. Kandidat yang saling bersilang dikelompokkan dulu:
 *      * 1 debit + 1 kredit                -> "pasti"
 *      * n debit + n kredit (n > 1)        -> "kelompok": dipasangkan berurutan waktu (nominal sama,
 *                                             jadi total tetap benar walau pasangan persisnya tertukar)
 *      * jumlah debit dan kredit berbeda   -> "ambigu": tidak dipasangkan, dilaporkan apa adanya.
 *    Salah pasang bisa menghapus transaksi sungguhan dari total, jadi lebih baik tidak menebak.
 *  - Urutan memakai waktu lalu id (urutan baris file), karena jam transaksi tidak selalu naik di file.
 */
final class PencocokTransfer
{
    public const JENIS_PASTI = 'pasti';
    public const JENIS_KELOMPOK = 'kelompok';

    /**
     * @param  int  $jendelaDetik  batas maksimum kredit terjadi SESUDAH debit
     * @param  int  $mundurDetik  toleransi kredit tercatat SEBELUM debit (selisih jam antar bank); bawaan 0
     */
    public function __construct(
        private readonly int $jendelaDetik,
        private readonly int $mundurDetik = 0,
    ) {
        if ($jendelaDetik < 0 || $mundurDetik < 0) {
            throw new InvalidArgumentException('Jendela dan toleransi waktu tidak boleh negatif.');
        }
    }

    /**
     * @param  iterable<MutasiRingkas>  $mutasi
     */
    public function cocokkan(iterable $mutasi): HasilPencocokan
    {
        // Kelompokkan per nominal (kunci = sen): pasangan hanya mungkin terjadi di antara nominal yang sama
        $debitPerSen = [];
        $kreditPerSen = [];
        foreach ($mutasi as $m) {
            if ($m->arah === KeuMutasiBank::ARAH_DEBIT) {
                $debitPerSen[$m->sen][] = $m;
            } elseif ($m->arah === KeuMutasiBank::ARAH_KREDIT) {
                $kreditPerSen[$m->sen][] = $m;
            }
        }

        $pasangan = [];
        $ambigu = [];
        $debitSisa = [];
        $kreditSisa = [];

        foreach ($debitPerSen as $sen => $debit) {
            $this->proses($sen, $debit, $kreditPerSen[$sen] ?? [], $pasangan, $ambigu, $debitSisa, $kreditSisa);
        }
        // Kredit pada nominal yang tidak punya debit sama sekali
        foreach ($kreditPerSen as $sen => $kredit) {
            if (! isset($debitPerSen[$sen])) {
                foreach ($kredit as $k) {
                    $kreditSisa[] = ['mutasi' => $k, 'terdekatDetik' => null];
                }
            }
        }

        // Keluaran berurutan stabil supaya hasil dua kali jalan identik
        usort($pasangan, fn (array $a, array $b) => [$a['debit']->waktu, $a['debit']->id] <=> [$b['debit']->waktu, $b['debit']->id]);
        usort($ambigu, fn (array $a, array $b) => $a['sen'] <=> $b['sen']);
        usort($debitSisa, fn (array $a, array $b) => $this->urut($a['mutasi'], $b['mutasi']));
        usort($kreditSisa, fn (array $a, array $b) => $this->urut($a['mutasi'], $b['mutasi']));

        return new HasilPencocokan($pasangan, $ambigu, $debitSisa, $kreditSisa);
    }

    /**
     * @param  list<MutasiRingkas>  $debit
     * @param  list<MutasiRingkas>  $kredit
     */
    private function proses(int $sen, array $debit, array $kredit, array &$pasangan, array &$ambigu, array &$debitSisa, array &$kreditSisa): void
    {
        usort($debit, fn (MutasiRingkas $a, MutasiRingkas $b) => $this->urut($a, $b));
        usort($kredit, fn (MutasiRingkas $a, MutasiRingkas $b) => $this->urut($a, $b));

        // Sisi (edge) = debit i dan kredit j yang memenuhi aturan: rekening beda dan dalam jendela waktu.
        // Kredit sudah urut waktu, jadi batas jendela dicari dengan pencarian biner, bukan memeriksa semua.
        $waktuKredit = array_map(fn (MutasiRingkas $k) => $k->waktu, $kredit);
        $tetanggaDebit = [];
        $tetanggaKredit = [];
        $sisi = [];
        foreach ($debit as $i => $d) {
            for ($j = $this->batasBawah($waktuKredit, $d->waktu - $this->mundurDetik); $j < count($kredit); $j++) {
                if ($kredit[$j]->waktu > $d->waktu + $this->jendelaDetik) {
                    break;
                }
                if ($kredit[$j]->rekeningId === $d->rekeningId) {
                    continue;
                }
                $tetanggaDebit[$i][] = $j;
                $tetanggaKredit[$j][] = $i;
                $sisi[$i.':'.$j] = true;
            }
        }

        // Komponen terhubung: debit dan kredit yang saling bersilangan satu kelompok
        $debitTerpakai = [];
        $kreditTerpakai = [];
        foreach (array_keys($tetanggaDebit) as $awal) {
            if (isset($debitTerpakai[$awal])) {
                continue;
            }

            $anggotaDebit = [];
            $anggotaKredit = [];
            $antrean = [['d', $awal]];
            $debitTerpakai[$awal] = true;
            while ($antrean !== []) {
                [$jenis, $idx] = array_shift($antrean);
                if ($jenis === 'd') {
                    $anggotaDebit[] = $idx;
                    foreach ($tetanggaDebit[$idx] as $j) {
                        if (! isset($kreditTerpakai[$j])) {
                            $kreditTerpakai[$j] = true;
                            $antrean[] = ['k', $j];
                        }
                    }
                } else {
                    $anggotaKredit[] = $idx;
                    foreach ($tetanggaKredit[$idx] as $i) {
                        if (! isset($debitTerpakai[$i])) {
                            $debitTerpakai[$i] = true;
                            $antrean[] = ['d', $i];
                        }
                    }
                }
            }

            sort($anggotaDebit);
            sort($anggotaKredit);
            $this->putuskanKomponen($sen, $debit, $kredit, $anggotaDebit, $anggotaKredit, $sisi, $pasangan, $ambigu);
        }

        // Yang tidak punya sisi sama sekali: tidak berpasangan
        $kreditBebas = array_values(array_filter(array_keys($kredit), fn (int $j) => ! isset($kreditTerpakai[$j])));
        $debitBebas = array_values(array_filter(array_keys($debit), fn (int $i) => ! isset($debitTerpakai[$i])));

        foreach ($debitBebas as $i) {
            $debitSisa[] = ['mutasi' => $debit[$i], 'terdekatDetik' => $this->terdekat($debit[$i], $kredit, $kreditBebas, true)];
        }
        foreach ($kreditBebas as $j) {
            $kreditSisa[] = ['mutasi' => $kredit[$j], 'terdekatDetik' => $this->terdekat($kredit[$j], $debit, $debitBebas, false)];
        }
    }

    /**
     * @param  list<MutasiRingkas>  $debit
     * @param  list<MutasiRingkas>  $kredit
     * @param  list<int>  $anggotaDebit
     * @param  list<int>  $anggotaKredit
     * @param  array<string, true>  $sisi
     */
    private function putuskanKomponen(int $sen, array $debit, array $kredit, array $anggotaDebit, array $anggotaKredit, array $sisi, array &$pasangan, array &$ambigu): void
    {
        $jumlah = count($anggotaDebit);

        if ($jumlah === count($anggotaKredit)) {
            // Anggota sudah urut waktu (indeks mengikuti urutan). Dipasangkan berurutan; bila ada pasangan
            // yang melanggar aturan jendela, kelompok tidak dipercaya dan dilaporkan ambigu.
            $hasil = [];
            foreach ($anggotaDebit as $n => $i) {
                $j = $anggotaKredit[$n];
                if (! isset($sisi[$i.':'.$j])) {
                    $hasil = null;
                    break;
                }
                $hasil[] = [
                    'debit' => $debit[$i],
                    'kredit' => $kredit[$j],
                    'jenis' => $jumlah === 1 ? self::JENIS_PASTI : self::JENIS_KELOMPOK,
                    'selisihDetik' => $kredit[$j]->waktu - $debit[$i]->waktu,
                ];
            }
            if ($hasil !== null) {
                array_push($pasangan, ...$hasil);

                return;
            }
        }

        $ambigu[] = [
            'sen' => $sen,
            'debit' => array_map(fn (int $i) => $debit[$i], $anggotaDebit),
            'kredit' => array_map(fn (int $j) => $kredit[$j], $anggotaKredit),
        ];
    }

    /**
     * Selisih waktu (kredit - debit, detik) ke lawan terdekat dari yang belum berpasangan, atau null.
     *
     * @param  list<MutasiRingkas>  $lawan
     * @param  list<int>  $indeksBebas
     */
    private function terdekat(MutasiRingkas $m, array $lawan, array $indeksBebas, bool $mIniDebit): ?int
    {
        $terbaik = null;
        foreach ($indeksBebas as $idx) {
            $l = $lawan[$idx];
            if ($l->rekeningId === $m->rekeningId) {
                continue;
            }
            $selisih = $mIniDebit ? $l->waktu - $m->waktu : $m->waktu - $l->waktu;
            if ($terbaik === null || abs($selisih) < abs($terbaik)) {
                $terbaik = $selisih;
            }
        }

        return $terbaik;
    }

    /** Indeks pertama yang waktunya >= $batas (daftar sudah urut naik). */
    private function batasBawah(array $waktu, int $batas): int
    {
        $kiri = 0;
        $kanan = count($waktu);
        while ($kiri < $kanan) {
            $tengah = intdiv($kiri + $kanan, 2);
            if ($waktu[$tengah] < $batas) {
                $kiri = $tengah + 1;
            } else {
                $kanan = $tengah;
            }
        }

        return $kiri;
    }

    private function urut(MutasiRingkas $a, MutasiRingkas $b): int
    {
        return [$a->waktu, $a->id] <=> [$b->waktu, $b->id];
    }
}