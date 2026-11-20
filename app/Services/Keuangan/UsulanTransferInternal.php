<?php

namespace App\Services\Keuangan;

use App\Models\KeuTransferInternal;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tombol "Cari usulan baru": menjalankan PencocokTransfer pada mutasi yang BELUM berada di pasangan
 * mana pun, lalu menulis hasilnya sebagai usulan berstatus needs_review.
 *
 * - Mutasi yang sudah ada di pasangan (menunggu, terkonfirmasi, maupun ditolak) dilewati,
 *   jadi aman dijalankan berulang dan keputusan manusia tidak pernah ditimpa.
 * - Semua dalam satu transaksi bersama audit log. UNIQUE di database jadi pengaman terakhir
 *   bila ada dua pencarian bersamaan.
 * - keu_mutasi_bank hanya DIBACA.
 */
final class UsulanTransferInternal
{
    private const UKURAN_POTONGAN = 500;

    /**
     * @return array{baru: int, totalSen: int, ambigu: int, jendelaJam: int}
     */
    public function cari(User $pengguna): array
    {
        $jam = (int) config('keuangan.jendela_transfer_jam');
        if ($jam < 1 || $jam > 720) {
            throw new RuntimeException('KEU_JENDELA_TRANSFER_JAM di .env harus bilangan 1 sampai 720 (jam).');
        }

        $db = DB::connection('dashi_keu');

        return $db->transaction(function () use ($db, $jam, $pengguna) {
            $mutasi = [];
            $dilewati = 0;

            // Hanya kolom yang dipakai (deskripsi berisi nama orang, tidak diambil). Dibaca per baris (cursor).
            $kueri = $db->table('keu_mutasi_bank as m')
                ->select('m.id', 'm.rekening_id', 'm.tanggal_transaksi', 'm.arah', 'm.nominal')
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('keu_transfer_internal as t')->whereColumn('t.debit_mutasi_id', 'm.id');
                })
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('keu_transfer_internal as t')->whereColumn('t.kredit_mutasi_id', 'm.id');
                })
                ->orderBy('m.id');

            foreach ($kueri->cursor() as $r) {
                $waktu = strtotime((string) $r->tanggal_transaksi);
                $sen = Uang::dariTeks((string) $r->nominal);
                if ($waktu === false || $sen === null || ! in_array($r->arah, ['debit', 'kredit'], true)) {
                    $dilewati++;

                    continue;
                }
                $mutasi[] = new MutasiRingkas((int) $r->id, (int) $r->rekening_id, $waktu, $r->arah, $sen);
            }

            $hasil = (new PencocokTransfer($jam * 3600))->cocokkan($mutasi);

            $sekarang = now();
            $baris = array_map(fn (array $p) => [
                'debit_mutasi_id'  => $p['debit']->id,
                'kredit_mutasi_id' => $p['kredit']->id,
                'jenis'            => $p['jenis'],
                'selisih_detik'    => $p['selisihDetik'],
                'status'           => KeuTransferInternal::STATUS_MENUNGGU,
                'dibuat_oleh'      => $pengguna->id,
                'created_at'       => $sekarang,
                'updated_at'       => $sekarang,
            ], $hasil->pasangan);

            foreach (array_chunk($baris, self::UKURAN_POTONGAN) as $potongan) {
                KeuTransferInternal::insert($potongan);
            }

            $ringkasan = [
                'baru'       => count($hasil->pasangan),
                'totalSen'   => $hasil->totalPasanganSen(),
                'ambigu'     => count($hasil->ambigu),
                'jendelaJam' => $jam,
            ];

            // Hanya angka ringkasan; isi transaksi tidak dicatat
            AuditLog::catat(AuditLog::CARI_TRANSFER, null, null, [
                'usulan_baru'      => $ringkasan['baru'],
                'nominal_usulan'   => Uang::format($ringkasan['totalSen']),
                'kelompok_ambigu'  => $ringkasan['ambigu'],
                'mutasi_diperiksa' => count($mutasi),
                'mutasi_dilewati'  => $dilewati,
                'jendela_jam'      => $jam,
            ], null, $pengguna);

            return $ringkasan;
        });
    }
}