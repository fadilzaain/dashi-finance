<?php

namespace App\Console\Commands;

use App\Services\Keuangan\MutasiRingkas;
use App\Services\Keuangan\PencocokTransfer;
use App\Services\Keuangan\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UjiTransferInternal extends Command
{
    protected $signature = 'keu:uji-transfer-internal
        {--dari= : Hanya mutasi mulai tanggal ini (YYYY-MM-DD)}
        {--sampai= : Hanya mutasi sampai tanggal ini, termasuk (YYYY-MM-DD)}
        {--jendela=72 : Jendela waktu dalam JAM: kredit boleh terjadi sampai sekian jam sesudah debit}
        {--mundur=0 : Toleransi dalam MENIT bila kredit tercatat sebelum debit (selisih jam antar bank)}
        {--nominal= : Hanya nominal ini (rupiah, tanpa pemisah ribuan, mis. 600000000)}
        {--maks=40 : Jumlah baris maksimum pada tiap daftar yang ditampilkan}
        {--tampilkan-deskripsi : Tampilkan deskripsi (berisi nama orang, jadi default tidak diambil sama sekali)}';

    protected $description = 'Uji kering pencocokan transfer internal antar rekening: hanya MEMBACA keu_mutasi_bank, tidak menulis apa pun';

    public function handle(): int
    {
        $opsi = $this->bacaOpsi();
        if ($opsi === null) {
            return self::FAILURE;
        }

        $pencocok = new PencocokTransfer($opsi['jendelaJam'] * 3600, $opsi['mundurMenit'] * 60);
        $dilewati = 0;
        $semua = [];

        // Data dibaca per baris (cursor) dan hanya kolom yang dipakai
        foreach ($this->kueri($opsi)->cursor() as $r) {
            $waktu = strtotime((string) $r->tanggal_transaksi);
            $sen = Uang::dariTeks((string) $r->nominal);
            if ($waktu === false || $sen === null || ! in_array($r->arah, ['debit', 'kredit'], true)) {
                $dilewati++;

                continue;
            }
            $semua[] = new MutasiRingkas((int) $r->id, (int) $r->rekening_id, $waktu, $r->arah, $sen);
        }

        $hasil = $pencocok->cocokkan($semua);
        $kode = DB::connection('dashi_keu')->table('keu_rekening_bank')->pluck('kode', 'id');
        $maks = $opsi['maks'];

        $this->info('Uji kering pencocokan transfer internal (hanya membaca, tidak ada yang ditulis)');
        $this->table(['Parameter', 'Nilai'], [
            ['Rentang tanggal', ($opsi['dari'] ?? 'awal').' s/d '.($opsi['sampai'] ?? 'akhir')],
            ['Nominal', $opsi['sen'] === null ? 'semua' : Uang::rupiah($opsi['sen'])],
            ['Jendela waktu', $opsi['jendelaJam'].' jam sesudah debit (bawaan 72 jam = TEBAKAN, tentukan dari data)'],
            ['Toleransi kredit sebelum debit', $opsi['mundurMenit'].' menit'],
            ['Mutasi dibaca', count($semua).($dilewati > 0 ? " (+{$dilewati} dilewati karena data tidak terbaca)" : '')],
        ]);

        $debitSisaSen = array_sum(array_map(fn (array $s) => $s['mutasi']->sen, $hasil->debitSisa));
        $kreditSisaSen = array_sum(array_map(fn (array $s) => $s['mutasi']->sen, $hasil->kreditSisa));
        $ambiguDebit = array_sum(array_map(fn (array $a) => count($a['debit']) * $a['sen'], $hasil->ambigu));
        $jumlahAmbiguDebit = array_sum(array_map(fn (array $a) => count($a['debit']), $hasil->ambigu));
        $jumlahAmbiguKredit = array_sum(array_map(fn (array $a) => count($a['kredit']), $hasil->ambigu));

        $this->table(['Hasil', 'Jumlah', 'Nominal'], [
            ['Pasangan pasti (1 debit : 1 kredit)', $hasil->jumlahPasangan(PencocokTransfer::JENIS_PASTI), Uang::rupiah($hasil->totalPasanganSen(PencocokTransfer::JENIS_PASTI))],
            ['Pasangan kelompok (n:n, urut waktu)', $hasil->jumlahPasangan(PencocokTransfer::JENIS_KELOMPOK), Uang::rupiah($hasil->totalPasanganSen(PencocokTransfer::JENIS_KELOMPOK))],
            ['TOTAL transfer internal terpasang', $hasil->jumlahPasangan(), Uang::rupiah($hasil->totalPasanganSen())],
            ['Ambigu (TIDAK dipasangkan)', count($hasil->ambigu)." kelompok: {$jumlahAmbiguDebit} debit, {$jumlahAmbiguKredit} kredit", Uang::rupiah($ambiguDebit).' (sisi debit)'],
            ['Debit tanpa pasangan', count($hasil->debitSisa), Uang::rupiah($debitSisaSen)],
            ['Kredit tanpa pasangan', count($hasil->kreditSisa), Uang::rupiah($kreditSisaSen)],
        ]);

        $this->sebaranWaktu('Sebaran selisih waktu pasangan (untuk memilih jendela)', array_map(fn (array $p) => $p['selisihDetik'], $hasil->pasangan));

        $terdekatDebit = array_values(array_filter(array_map(fn (array $s) => $s['terdekatDetik'], $hasil->debitSisa), fn ($d) => $d !== null));
        $terdekatKredit = array_values(array_filter(array_map(fn (array $s) => $s['terdekatDetik'], $hasil->kreditSisa), fn ($d) => $d !== null));
        if ($terdekatDebit !== [] || $terdekatKredit !== []) {
            $this->newLine();
            $this->warn('Ada debit/kredit tanpa pasangan yang PUNYA lawan bernominal sama di rekening lain, tetapi di luar jendela.');
            $this->line('Kalau jendela terlalu sempit, jalankan lagi dengan --jendela yang lebih besar (mis. 240) dan bandingkan.');
            $this->sebaranWaktu('Jarak ke lawan terdekat, debit tanpa pasangan', $terdekatDebit);
            $this->sebaranWaktu('Jarak ke lawan terdekat, kredit tanpa pasangan', $terdekatKredit);
        }

        // Deskripsi hanya diambil bila diminta, dan hanya untuk baris yang akan ditampilkan
        $idTampil = [];
        if ($opsi['deskripsi']) {
            foreach (array_slice($hasil->pasangan, 0, $maks) as $p) {
                array_push($idTampil, $p['debit']->id, $p['kredit']->id);
            }
            foreach (array_slice($hasil->debitSisa, 0, $maks) as $s) {
                $idTampil[] = $s['mutasi']->id;
            }
            foreach (array_slice($hasil->kreditSisa, 0, $maks) as $s) {
                $idTampil[] = $s['mutasi']->id;
            }
        }
        $deskripsi = $idTampil === [] ? collect() : DB::connection('dashi_keu')->table('keu_mutasi_bank')->whereIn('id', $idTampil)->pluck('deskripsi', 'id');
        $desk = fn (int $id) => $opsi['deskripsi'] ? Str::limit((string) ($deskripsi[$id] ?? ''), 30) : null;
        $judulDesk = $opsi['deskripsi'] ? ['Deskripsi'] : [];
        $sel = fn (MutasiRingkas $m) => "#{$m->id} ".($kode[$m->rekeningId] ?? '?').' '.date('d/m H:i', $m->waktu);

        if ($hasil->pasangan !== []) {
            $this->newLine();
            $this->line('Pasangan terpasang (maks '.$maks.' baris):');
            $this->table(
                ['Nominal', 'Debit', 'Kredit', 'Selisih', 'Jenis', ...$judulDesk],
                array_map(fn (array $p) => [
                    Uang::rupiah($p['debit']->sen), $sel($p['debit']), $sel($p['kredit']), $this->durasi($p['selisihDetik']), $p['jenis'],
                    ...($opsi['deskripsi'] ? [$desk($p['debit']->id).' | '.$desk($p['kredit']->id)] : []),
                ], array_slice($hasil->pasangan, 0, $maks)),
            );
        }

        if ($hasil->ambigu !== []) {
            $this->newLine();
            $this->warn('Kelompok ambigu: tidak dipasangkan otomatis, perlu keputusan manusia.');
            $this->table(['Nominal', 'Debit', 'Kredit'], array_map(fn (array $a) => [
                Uang::rupiah($a['sen']),
                implode("\n", array_map($sel, $a['debit'])),
                implode("\n", array_map($sel, $a['kredit'])),
            ], array_slice($hasil->ambigu, 0, $maks)));
        }

        foreach ([['Debit tanpa pasangan', $hasil->debitSisa], ['Kredit tanpa pasangan', $hasil->kreditSisa]] as [$judul, $daftar]) {
            if ($daftar === []) {
                continue;
            }
            $this->newLine();
            $this->line($judul.' (maks '.$maks.' baris):');
            $this->table(['Nominal', 'Mutasi', 'Lawan bernominal sama terdekat', ...$judulDesk], array_map(fn (array $s) => [
                Uang::rupiah($s['mutasi']->sen),
                $sel($s['mutasi']),
                $s['terdekatDetik'] === null ? '-' : $this->durasi($s['terdekatDetik']),
                ...($opsi['deskripsi'] ? [$desk($s['mutasi']->id)] : []),
            ], array_slice($daftar, 0, $maks)));
        }

        $this->newLine();
        $this->line('Catatan: pasangan di atas baru USULAN hitungan. Belum disimpan, dan nominal bulat bisa kebetulan sama dengan transaksi non-transfer.');

        return self::SUCCESS;
    }

    /** @return array{dari: ?string, sampai: ?string, jendelaJam: int, mundurMenit: int, sen: ?int, maks: int, deskripsi: bool}|null */
    private function bacaOpsi(): ?array
    {
        $tanggal = function (string $nama): string|false|null {
            $v = $this->option($nama);
            if ($v === null || $v === '') {
                return null;
            }
            try {
                $d = CarbonImmutable::createFromFormat('!Y-m-d', (string) $v);
            } catch (Throwable) {
                $d = false;
            }

            return ($d && $d->format('Y-m-d') === $v) ? $v : false;
        };

        $dari = $tanggal('dari');
        $sampai = $tanggal('sampai');
        if ($dari === false || $sampai === false) {
            $this->error('Format tanggal harus YYYY-MM-DD (mis. 2026-09-30).');

            return null;
        }
        if ($dari !== null && $sampai !== null && $sampai < $dari) {
            $this->error('--sampai tidak boleh sebelum --dari.');

            return null;
        }

        $bulat = function (string $nama, int $min, int $maks): ?int {
            $v = (string) $this->option($nama);

            return (ctype_digit($v) && (int) $v >= $min && (int) $v <= $maks) ? (int) $v : null;
        };
        $jendela = $bulat('jendela', 0, 720);
        $mundur = $bulat('mundur', 0, 1440);
        $maks = $bulat('maks', 1, 1000);
        if ($jendela === null || $mundur === null || $maks === null) {
            $this->error('--jendela (0-720 jam), --mundur (0-1440 menit), dan --maks (1-1000) harus berupa bilangan bulat dalam rentang itu.');

            return null;
        }

        $sen = null;
        $nominal = (string) $this->option('nominal');
        if ($nominal !== '') {
            $sen = preg_match('/^\d{1,15}(\.\d{1,2})?$/', $nominal) ? Uang::dariTeks($nominal) : null;
            if ($sen === null || $sen <= 0) {
                $this->error('--nominal harus angka rupiah tanpa pemisah ribuan, mis. 600000000');

                return null;
            }
        }

        return [
            'dari' => $dari, 'sampai' => $sampai, 'jendelaJam' => $jendela, 'mundurMenit' => $mundur,
            'sen' => $sen, 'maks' => $maks, 'deskripsi' => (bool) $this->option('tampilkan-deskripsi'),
        ];
    }

    private function kueri(array $opsi)
    {
        return DB::connection('dashi_keu')->table('keu_mutasi_bank')
            ->select('id', 'rekening_id', 'tanggal_transaksi', 'arah', 'nominal')
            ->when($opsi['dari'], fn ($q, $v) => $q->where('tanggal_transaksi', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($opsi['sampai'], fn ($q, $v) => $q->where('tanggal_transaksi', '<', CarbonImmutable::parse($v)->addDay()->startOfDay()))
            ->when($opsi['sen'], fn ($q, $v) => $q->where('nominal', Uang::format($v)))
            ->orderBy('id');
    }

    /** @param list<int> $detik */
    private function sebaranWaktu(string $judul, array $detik): void
    {
        if ($detik === []) {
            return;
        }

        $batas = [['kurang dari 0 (kredit sebelum debit)', null, -1], ['0 - 1 jam', 0, 3600], ['1 - 6 jam', 3601, 6 * 3600],
            ['6 - 24 jam', 6 * 3600 + 1, 24 * 3600], ['1 - 3 hari', 24 * 3600 + 1, 72 * 3600], ['lebih dari 3 hari', 72 * 3600 + 1, null]];
        $baris = [];
        foreach ($batas as [$label, $min, $maks]) {
            $n = count(array_filter($detik, fn (int $d) => ($min === null || $d >= $min) && ($maks === null || $d <= $maks)));
            if ($n > 0) {
                $baris[] = [$label, $n];
            }
        }

        $this->newLine();
        $this->line($judul.':');
        $this->table(['Selisih (kredit - debit)', 'Jumlah'], $baris);
    }

    private function durasi(int $detik): string
    {
        $tanda = $detik < 0 ? '-' : '';
        $m = intdiv(abs($detik), 60);

        return sprintf('%s%dj %02dm', $tanda, intdiv($m, 60), $m % 60);
    }
}