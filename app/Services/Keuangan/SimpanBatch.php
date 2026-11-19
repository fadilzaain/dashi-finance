<?php

namespace App\Services\Keuangan;

use App\Models\KeuImporBatch;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan baris staging yang sudah diperiksa ke keu_mutasi_bank (data final yang dibaca DASH-i),
 * atau membatalkan batch sebelum itu.
 *
 * Prinsip:
 *  - Semua dalam SATU transaksi bersama audit log: berhasil semua atau tidak sama sekali.
 *  - Baris batch dikunci (lockForUpdate) dan statusnya diperiksa ulang di dalam transaksi,
 *    jadi dua klik Simpan bersamaan tidak bisa sama-sama lolos.
 *  - Pemindahan dilakukan satu perintah SQL (INSERT ... SELECT), bukan loop PHP: cepat dan tidak
 *    memakan memori berapa pun jumlah barisnya. Baris yang kunci uniknya sudah ada di data final
 *    dilewati oleh perintah yang sama, dan UNIQUE di database tetap jadi pengaman terakhir.
 */
final class SimpanBatch
{
    // Baris staging yang layak masuk data final. Dipakai bersama oleh hitungan dan penyimpanan
    // supaya keduanya tidak mungkin berbeda.
    private const SUMBER_LAYAK = "FROM keu_impor_baris b
        WHERE b.batch_id = ?
          AND b.status IN ('valid', 'peringatan')
          AND b.kunci_unik IS NOT NULL
          AND b.tanggal_transaksi IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM keu_mutasi_bank m WHERE m.kunci_unik = b.kunci_unik)";

    /**
     * Baris yang dianggap duplikat DI DALAM file yang sama dengan baris lain yang ikut disimpan.
     * Kunci unik tidak bisa membedakan dua transaksi yang benar-benar kembar, jadi hanya satu yang masuk.
     */
    private const SQL_KEMBAR_DALAM_FILE = "SELECT COUNT(*) AS jumlah FROM keu_impor_baris d
        WHERE d.batch_id = ?
          AND d.status = 'duplikat'
          AND EXISTS (
              SELECT 1 FROM keu_impor_baris v
              WHERE v.batch_id = d.batch_id
                AND v.kunci_unik = d.kunci_unik
                AND v.status IN ('valid', 'peringatan')
          )";

    public function periksa(KeuImporBatch $batch): KelayakanSimpan
    {
        if ($batch->status !== KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI) {
            return new KelayakanSimpan(['Batch ini tidak berstatus menunggu verifikasi, jadi tidak bisa disimpan.'], [], 0, 0);
        }

        $db = $this->db();
        $baru = (int) $db->selectOne('SELECT COUNT(*) AS jumlah '.self::SUMBER_LAYAK, [$batch->id])->jumlah;

        $blokir = [];
        if ($batch->hasil_rekonsiliasi === KeuImporBatch::REKON_SELISIH) {
            $blokir[] = 'Rekonsiliasi saldo berselisih, jadi batch tidak boleh disimpan. Periksa file dari bank, lalu unggah ulang.';
        }
        if ($batch->jumlah_tidak_valid > 0) {
            $blokir[] = "Ada {$batch->jumlah_tidak_valid} baris tidak valid. Batch hanya boleh disimpan utuh; perbaiki file dan unggah ulang.";
        }
        if ($baru === 0) {
            $blokir[] = 'Tidak ada transaksi baru untuk disimpan (semua baris sudah tersimpan sebelumnya).';
        }

        $konfirmasi = [];
        if ($batch->hasil_rekonsiliasi === null) {
            $konfirmasi[] = 'File ini tidak memuat saldo, jadi rekonsiliasi tidak bisa dilakukan. Angkanya belum terbukti cocok dengan saldo bank.';
        }
        $kembar = (int) $db->selectOne(self::SQL_KEMBAR_DALAM_FILE, [$batch->id])->jumlah;
        if ($kembar > 0) {
            $konfirmasi[] = "Ada {$kembar} baris yang identik dengan baris lain di file ini (waktu, referensi, arah, nominal sama). "
                .'Hanya satu yang disimpan, sehingga total di data final bisa lebih kecil dari total bank.';
        }

        return new KelayakanSimpan($blokir, $konfirmasi, $baru, $batch->jumlah_duplikat);
    }

    /**
     * @return int jumlah transaksi yang disimpan ke data final
     *
     * @throws SimpanDitolak bila aturan tidak terpenuhi (tidak ada yang berubah)
     */
    public function simpan(KeuImporBatch $batch, User $pengguna, bool $konfirmasi): int
    {
        $db = $this->db();

        return $db->transaction(function () use ($db, $batch, $pengguna, $konfirmasi) {
            // Baca ulang dengan kunci: status di halaman yang dibuka pengguna bisa sudah basi
            $terkunci = KeuImporBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $kelayakan = $this->periksa($terkunci);
            if (! $kelayakan->bisaDisimpan()) {
                throw new SimpanDitolak($kelayakan->blokir[0]);
            }
            if ($kelayakan->konfirmasi !== [] && ! $konfirmasi) {
                throw new SimpanDitolak('Centang konfirmasi dulu setelah membaca peringatan di atas.');
            }

            $waktu = now()->format('Y-m-d H:i:s');
            $masuk = $db->affectingStatement(
                "INSERT INTO keu_mutasi_bank
                    (rekening_id, batch_id, baris_id, kunci_unik, tanggal_transaksi, tanggal_efektif,
                     nomor_referensi, kanal, deskripsi, arah, nominal, saldo_setelah, created_at, updated_at)
                 SELECT ?, b.batch_id, b.id, b.kunci_unik, b.tanggal_transaksi, b.tanggal_efektif,
                        b.nomor_referensi, b.kanal, b.deskripsi,
                        CASE WHEN b.debit > 0 THEN 'debit' ELSE 'kredit' END,
                        CASE WHEN b.debit > 0 THEN b.debit ELSE b.kredit END,
                        b.saldo, ?, ?
                 ".self::SUMBER_LAYAK.'
                 ORDER BY b.nomor_baris',
                [$terkunci->rekening_id, $waktu, $waktu, $terkunci->id]
            );

            // Harus persis sama dengan yang dihitung tadi; selisih berarti ada yang berubah di tengah jalan
            if ($masuk !== $kelayakan->barisBaru) {
                throw new SimpanDitolak('Data berubah saat disimpan (mungkin ada unggahan lain bersamaan). Tidak ada yang disimpan; muat ulang halaman lalu coba lagi.');
            }

            $terkunci->update([
                'status' => KeuImporBatch::STATUS_DISIMPAN,
                'disimpan_oleh' => $pengguna->id,
                'disimpan_pada' => now(),
            ]);

            // Di dalam transaksi yang sama: kalau audit gagal tercatat, penyimpanan ikut dibatalkan
            AuditLog::catat(
                AuditLog::SIMPAN_BATCH,
                $terkunci,
                ['status' => KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI],
                [
                    'status' => KeuImporBatch::STATUS_DISIMPAN,
                    'kode_batch' => $terkunci->kode_batch,
                    'transaksi_disimpan' => $masuk,
                    'baris_duplikat_dilewati' => $terkunci->jumlah_duplikat,
                    'hasil_rekonsiliasi' => $terkunci->hasil_rekonsiliasi ?? 'tidak_bisa_diperiksa',
                    'total_debit_hitung' => $terkunci->total_debit_hitung,
                    'total_kredit_hitung' => $terkunci->total_kredit_hitung,
                    'dikonfirmasi_pengguna' => $kelayakan->konfirmasi !== [],
                ],
                $terkunci->nama_file_asli,
                $pengguna,
            );

            return $masuk;
        });
    }

    /**
     * Membuang batch yang belum disimpan (mis. rekonsiliasi berselisih). Berkas dan barisnya tetap
     * ada sebagai arsip, tetapi file yang sama boleh diunggah ulang.
     *
     * @throws SimpanDitolak
     */
    public function batalkan(KeuImporBatch $batch, User $pengguna): void
    {
        $this->db()->transaction(function () use ($batch, $pengguna) {
            $terkunci = KeuImporBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($terkunci->status !== KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI) {
                throw new SimpanDitolak('Hanya batch yang menunggu verifikasi yang bisa dibatalkan.');
            }

            $terkunci->update([
                'status' => KeuImporBatch::STATUS_DITOLAK,
                'catatan' => trim('Dibatalkan oleh pengguna sebelum disimpan.'."\n".$terkunci->catatan),
            ]);

            AuditLog::catat(
                AuditLog::BATALKAN_BATCH,
                $terkunci,
                ['status' => KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI],
                ['status' => KeuImporBatch::STATUS_DITOLAK, 'kode_batch' => $terkunci->kode_batch],
                $terkunci->nama_file_asli,
                $pengguna,
            );
        });
    }

    private function db(): Connection
    {
        return DB::connection('dashi_keu');
    }
}