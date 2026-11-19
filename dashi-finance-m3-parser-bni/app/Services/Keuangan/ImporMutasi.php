<?php

namespace App\Services\Keuangan;

use App\Models\KeuImporBaris;
use App\Models\KeuImporBatch;
use App\Models\KeuMutasiBank;
use App\Models\KeuRekeningBank;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Alur unggah mutasi bank: simpan file, baca dengan parser yang sesuai, rekonsiliasi,
 * lalu tulis hasilnya ke tabel STAGING (keu_impor_batch dan keu_impor_baris).
 *
 * Kelas ini TIDAK menulis ke keu_mutasi_bank (data final yang dibaca DASH-i).
 * Pemindahan ke data final hanya lewat tombol Simpan (milestone berikutnya).
 */
final class ImporMutasi
{
    // storage/app/private: tidak bisa dibuka lewat URL
    private const DISK = 'local';

    // Batch berstatus "diproses" lebih lama dari ini dianggap macet dan tidak lagi menghalangi unggah ulang
    private const MENIT_DIPROSES_MACET = 10;

    private const UKURAN_POTONGAN = 500;

    public function hash(UploadedFile $berkas): string
    {
        return hash_file('sha256', (string) $berkas->getRealPath())
            ?: throw new RuntimeException('File tidak dapat dibaca untuk dihitung hash-nya.');
    }

    /**
     * Unggahan lain dengan isi file yang persis sama untuk rekening yang sama.
     * Batch ditolak/gagal tidak dihitung, jadi file yang sama boleh diunggah ulang setelah diperbaiki.
     */
    public function cariUnggahanSama(KeuRekeningBank $rekening, string $hash): ?KeuImporBatch
    {
        return KeuImporBatch::where('rekening_id', $rekening->id)
            ->where('hash_sha256', $hash)
            ->where(function ($q) {
                $q->whereIn('status', [KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI, KeuImporBatch::STATUS_DISIMPAN])
                    ->orWhere(function ($q) {
                        $q->where('status', KeuImporBatch::STATUS_DIPROSES)
                            ->where('created_at', '>=', now()->subMinutes(self::MENIT_DIPROSES_MACET));
                    });
            })
            ->latest('id')
            ->first();
    }

    /**
     * Memproses satu file. Selalu mengembalikan batch; lihat kolom status untuk hasilnya:
     * menunggu_verifikasi (berhasil dibaca), ditolak (file tidak layak), atau gagal (galat sistem).
     */
    public function proses(KeuRekeningBank $rekening, UploadedFile $berkas, string $hash, int $penggunaId): KeuImporBatch
    {
        $ekstensi = strtolower($berkas->getClientOriginalExtension());
        $ukuran = (int) $berkas->getSize();

        // Nama di disk diacak; nama asli hanya dicatat untuk tampilan
        $path = $berkas->storeAs(
            'keuangan/impor/'.now()->format('Y/m'),
            Str::uuid()->toString().'.'.$ekstensi,
            self::DISK
        );
        if ($path === false) {
            throw new RuntimeException('File gagal disimpan ke storage.');
        }

        // Nama asli hanya untuk tampilan. mb_scrub membuang byte UTF-8 rusak yang akan ditolak MySQL strict.
        $namaAsli = mb_substr(mb_scrub(basename(str_replace('\\', '/', $berkas->getClientOriginalName()))), 0, 255);

        try {
            $batch = KeuImporBatch::create([
                'rekening_id' => $rekening->id,
                'nama_file_asli' => $namaAsli,
                'path_file' => $path,
                'format_file' => $ekstensi,
                'ukuran_byte' => $ukuran,
                'hash_sha256' => $hash,
                'status' => KeuImporBatch::STATUS_DIPROSES,
                'diunggah_oleh' => $penggunaId,
            ]);
        } catch (Throwable $e) {
            // Batch gagal dicatat: jangan tinggalkan file yatim di storage
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        try {
            $hasil = $this->baca($rekening, Storage::disk(self::DISK)->path($path), $ekstensi);

            if (! $hasil->bisaDiproses()) {
                $batch->update([
                    'format_file' => $hasil->format,
                    'status' => KeuImporBatch::STATUS_DITOLAK,
                    'catatan' => implode("\n", $hasil->galat),
                ]);

                return $batch;
            }

            $this->tandaiSudahTersimpan($hasil);
            $this->simpanStaging($batch, $hasil, (new Rekonsiliator())->periksa($hasil));
        } catch (Throwable $e) {
            // Rincian galat hanya masuk log; pengguna cukup diberi pesan umum
            report($e);

            $batch->update([
                'status' => KeuImporBatch::STATUS_GAGAL,
                'catatan' => 'Terjadi kesalahan saat memproses file. Coba unggah ulang; bila terulang, hubungi admin.',
            ]);
        }

        return $batch;
    }

    private function baca(KeuRekeningBank $rekening, string $path, string $ekstensi): HasilParse
    {
        $csv = $ekstensi === 'csv';

        if (! $csv && ! in_array($ekstensi, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Ekstensi file tidak didukung: '.$ekstensi);
        }

        return match ($rekening->bank) {
            'BNI' => $csv
                ? (new ParserBni($rekening->nomor_rekening))->parseCsv($path)
                : (new ParserBni($rekening->nomor_rekening))->parseXls($path),
            'BPD' => $csv
                ? (new ParserBpd($rekening->nomor_rekening))->parseCsv($path)
                : (new ParserBpd($rekening->nomor_rekening))->parseExcel($path),
            default => throw new RuntimeException('Bank belum didukung: '.$rekening->bank),
        };
    }

    /**
     * Baris yang kunci uniknya sudah ada di data final (dari unggahan sebelumnya) ditandai duplikat,
     * supaya tidak tersimpan dua kali. Kunci unik sudah memuat nomor rekening, jadi tidak perlu difilter lagi.
     */
    private function tandaiSudahTersimpan(HasilParse $hasil): void
    {
        $kandidat = [];
        foreach ($hasil->baris as $baris) {
            if ($baris->kunciUnik !== null && $baris->status !== BarisTransaksi::DUPLIKAT) {
                $kandidat[$baris->kunciUnik][] = $baris;
            }
        }

        foreach (array_chunk(array_keys($kandidat), self::UKURAN_POTONGAN) as $kunci) {
            $sudahAda = KeuMutasiBank::whereIn('kunci_unik', $kunci)->pluck('kunci_unik')->all();

            foreach ($sudahAda as $k) {
                foreach ($kandidat[$k] as $baris) {
                    $baris->status = BarisTransaksi::DUPLIKAT;
                    $baris->pesan = trim(($baris->pesan !== null ? $baris->pesan.' ' : '')
                        .'Transaksi ini sudah tersimpan dari unggahan sebelumnya.');
                }
            }
        }
    }

    /**
     * Menulis semua baris dan ringkasan batch dalam satu transaksi: berhasil semua atau tidak sama sekali.
     * Kolom catatan di sini berisi peringatan parser dan catatan rekonsiliasi, satu per baris.
     */
    private function simpanStaging(KeuImporBatch $batch, HasilParse $hasil, HasilRekonsiliasi $rekon): void
    {
        DB::connection('dashi_keu')->transaction(function () use ($batch, $hasil, $rekon) {
            foreach (array_chunk($hasil->baris, self::UKURAN_POTONGAN) as $potongan) {
                $waktu = now();

                KeuImporBaris::insert(array_map(
                    fn (BarisTransaksi $baris) => array_merge($baris->keArrayStaging(), [
                        'batch_id' => $batch->id,
                        // insert() massal tidak lewat cast model, jadi JSON dibuat di sini
                        'data_mentah' => json_encode($baris->dataMentah, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'created_at' => $waktu,
                        'updated_at' => $waktu,
                    ]),
                    $potongan
                ));
            }

            $jumlah = $hasil->jumlahPerStatus();
            $catatan = array_values(array_unique([...$hasil->peringatan, ...$rekon->catatan]));

            $batch->update([
                'format_file' => $hasil->format,
                'periode_awal' => $hasil->periodeAwal?->format('Y-m-d'),
                'periode_akhir' => $hasil->periodeAkhir?->format('Y-m-d'),
                'saldo_awal_file' => $this->desimal($hasil->saldoAwal),
                'saldo_akhir_file' => $this->desimal($hasil->saldoAkhir),
                'total_debit_file' => $this->desimal($hasil->totalDebitFile),
                'total_kredit_file' => $this->desimal($hasil->totalKreditFile),
                'total_debit_hitung' => Uang::format($rekon->debitHitung),
                'total_kredit_hitung' => Uang::format($rekon->kreditHitung),
                'jumlah_baris' => count($hasil->baris),
                'jumlah_valid' => $jumlah[BarisTransaksi::VALID],
                'jumlah_peringatan' => $jumlah[BarisTransaksi::PERINGATAN],
                'jumlah_tidak_valid' => $jumlah[BarisTransaksi::TIDAK_VALID],
                'jumlah_duplikat' => $jumlah[BarisTransaksi::DUPLIKAT],
                'status' => KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI,
                'hasil_rekonsiliasi' => $rekon->hasil(),
                'selisih' => $this->desimal($rekon->selisih()),
                'catatan' => $catatan === [] ? null : implode("\n", $catatan),
            ]);
        });
    }

    private function desimal(?int $sen): ?string
    {
        return $sen === null ? null : Uang::format($sen);
    }
}