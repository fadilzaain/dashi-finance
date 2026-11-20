<?php

namespace App\Services\Keuangan;

use App\Models\KeuMutasiBank;
use App\Models\KeuTransferInternal;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan petugas atas usulan pindah dana antar rekening: konfirmasi, tolak, atau kembalikan.
 *
 * Prinsip (sama dengan SimpanBatch):
 *  - Setiap keputusan DAN audit log-nya dalam satu transaksi: kalau audit gagal, keputusan batal.
 *  - Baris dikunci (lockForUpdate) dan statusnya diperiksa ulang di dalam transaksi, jadi dua petugas
 *    yang klik bersamaan tidak bisa sama-sama lolos.
 *  - Sebelum dikonfirmasi, pasangan diperiksa ulang: debit memang debit, kredit memang kredit,
 *    beda rekening, nominal sama persis.
 */
final class KeputusanTransferInternal
{
    public function konfirmasi(int $id, User $pengguna): void
    {
        $this->putuskan($id, [KeuTransferInternal::STATUS_MENUNGGU], KeuTransferInternal::STATUS_TERKONFIRMASI, AuditLog::KONFIRMASI_TRANSFER, $pengguna);
    }

    public function tolak(int $id, User $pengguna): void
    {
        $this->putuskan($id, [KeuTransferInternal::STATUS_MENUNGGU], KeuTransferInternal::STATUS_DITOLAK, AuditLog::TOLAK_TRANSFER, $pengguna);
    }

    /** Membatalkan keputusan: terkonfirmasi atau ditolak kembali menjadi menunggu konfirmasi. */
    public function kembalikan(int $id, User $pengguna): void
    {
        $this->putuskan(
            $id,
            [KeuTransferInternal::STATUS_TERKONFIRMASI, KeuTransferInternal::STATUS_DITOLAK],
            KeuTransferInternal::STATUS_MENUNGGU,
            AuditLog::KEMBALIKAN_TRANSFER,
            $pengguna,
        );
    }

    /**
     * Angka yang ditampilkan di tombol konfirmasi massal. Memakai penghitungan yang sama
     * dengan konfirmasiMassal() supaya keduanya tidak mungkin berbeda.
     *
     * @return array{jumlah: int, totalSen: int}
     */
    public function ringkasanMassal(): array
    {
        $daftar = $this->kandidatMassal(false);

        return ['jumlah' => $daftar->count(), 'totalSen' => $this->totalSen($daftar)];
    }

    /**
     * Konfirmasi semua usulan jenis "cocok persis" (1 debit : 1 kredit) yang menunggu.
     * Jumlah dan total yang dilihat petugas di layar dikirim kembali; bila daftar sudah berubah
     * sejak halaman dibuka, SEMUANYA ditolak (tidak ada yang dikonfirmasi).
     *
     * @return int jumlah pasangan yang dikonfirmasi
     *
     * @throws KeputusanDitolak
     */
    public function konfirmasiMassal(User $pengguna, int $jumlahDilihat, int $totalSenDilihat): int
    {
        return $this->db()->transaction(function () use ($pengguna, $jumlahDilihat, $totalSenDilihat) {
            $daftar = $this->kandidatMassal(true);
            $jumlah = $daftar->count();
            $total = $this->totalSen($daftar);

            if ($jumlah === 0) {
                throw new KeputusanDitolak('Tidak ada pasangan cocok persis yang menunggu konfirmasi.');
            }
            if ($jumlah !== $jumlahDilihat || $total !== $totalSenDilihat) {
                throw new KeputusanDitolak("Daftar berubah sejak halaman dibuka (sekarang {$jumlah} pasangan senilai "
                    .Uang::rupiah($total).'). Tidak ada yang dikonfirmasi; muat ulang halaman lalu periksa lagi.');
            }

            foreach ($daftar as $pasangan) {
                $this->pastikanSah($pasangan);
                $this->terapkan($pasangan, KeuTransferInternal::STATUS_TERKONFIRMASI, AuditLog::KONFIRMASI_TRANSFER, $pengguna, 'Konfirmasi massal (cocok persis)');
            }

            return $jumlah;
        });
    }

    // ------------------------------------------------------------------ bagian dalam

    /**
     * @param  list<string>  $statusAwal  status yang boleh menjadi titik awal keputusan ini
     *
     * @throws KeputusanDitolak
     */
    private function putuskan(int $id, array $statusAwal, string $statusAkhir, string $aktivitas, User $pengguna): void
    {
        $this->db()->transaction(function () use ($id, $statusAwal, $statusAkhir, $aktivitas, $pengguna) {
            // Baca ulang dengan kunci: status di halaman yang dibuka petugas bisa sudah basi
            $pasangan = KeuTransferInternal::with(['debit', 'kredit'])->whereKey($id)->lockForUpdate()->first();

            if ($pasangan === null) {
                throw new KeputusanDitolak('Usulan tidak ditemukan.');
            }
            if (! in_array($pasangan->status, $statusAwal, true)) {
                throw new KeputusanDitolak('Usulan ini sudah berubah (kemungkinan sudah diputuskan petugas lain). Muat ulang halaman.');
            }
            if ($statusAkhir === KeuTransferInternal::STATUS_TERKONFIRMASI) {
                $this->pastikanSah($pasangan);
            }

            $this->terapkan($pasangan, $statusAkhir, $aktivitas, $pengguna, null);
        });
    }

    private function terapkan(KeuTransferInternal $pasangan, string $statusAkhir, string $aktivitas, User $pengguna, ?string $keterangan): void
    {
        $sebelum = $pasangan->status;
        $kembaliMenunggu = $statusAkhir === KeuTransferInternal::STATUS_MENUNGGU;

        $pasangan->update([
            'status'          => $statusAkhir,
            'diputuskan_oleh' => $kembaliMenunggu ? null : $pengguna->id,
            'diputuskan_pada' => $kembaliMenunggu ? null : now(),
        ]);

        // Di dalam transaksi yang sama dengan keputusan
        AuditLog::catat($aktivitas, $pasangan, ['status' => $sebelum], [
            'status'           => $statusAkhir,
            'debit_mutasi_id'  => $pasangan->debit_mutasi_id,
            'kredit_mutasi_id' => $pasangan->kredit_mutasi_id,
            'jenis'            => $pasangan->jenis,
            'nominal'          => $pasangan->debit->nominal,
        ], $keterangan, $pengguna);
    }

    /** @throws KeputusanDitolak */
    private function pastikanSah(KeuTransferInternal $pasangan): void
    {
        $debit = $pasangan->debit;
        $kredit = $pasangan->kredit;

        $sah = $debit->arah === KeuMutasiBank::ARAH_DEBIT
            && $kredit->arah === KeuMutasiBank::ARAH_KREDIT
            && (int) $debit->rekening_id !== (int) $kredit->rekening_id
            && (string) $debit->nominal === (string) $kredit->nominal;

        if (! $sah) {
            throw new KeputusanDitolak('Pasangan ini tidak memenuhi syarat (nominal tidak sama persis, arah salah, atau rekeningnya sama), jadi tidak bisa dikonfirmasi.');
        }
    }

    /** @return Collection<int, KeuTransferInternal> */
    private function kandidatMassal(bool $kunci): Collection
    {
        return KeuTransferInternal::with(['debit', 'kredit'])
            ->where('status', KeuTransferInternal::STATUS_MENUNGGU)
            ->where('jenis', PencocokTransfer::JENIS_PASTI)
            ->orderBy('id')
            ->when($kunci, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /** @param  Collection<int, KeuTransferInternal>  $daftar */
    private function totalSen(Collection $daftar): int
    {
        return (int) $daftar->sum(fn (KeuTransferInternal $p) => Uang::dariTeks((string) $p->debit->nominal) ?? 0);
    }

    private function db(): Connection
    {
        return DB::connection('dashi_keu');
    }
}