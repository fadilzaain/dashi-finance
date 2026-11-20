<?php

namespace App\Http\Controllers;

use App\Models\KeuTransferInternal;
use App\Models\User;
use App\Services\Keuangan\KeputusanDitolak;
use App\Services\Keuangan\KeputusanTransferInternal;
use App\Services\Keuangan\PencocokTransfer;
use App\Services\Keuangan\Uang;
use App\Services\Keuangan\UsulanTransferInternal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PindahDanaController extends Controller
{
    private const PER_HALAMAN = 25;

    public function __construct(
        private readonly UsulanTransferInternal $usulan,
        private readonly KeputusanTransferInternal $keputusan,
    ) {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(KeuTransferInternal::DAFTAR_STATUS)],
        ]);
        $status = $data['status'] ?? KeuTransferInternal::STATUS_MENUNGGU;

        $daftar = KeuTransferInternal::with(['debit.rekening', 'kredit.rekening'])
            ->where('status', $status)
            ->orderByDesc('id')
            ->simplePaginate(self::PER_HALAMAN)
            ->withQueryString();

        // Nama petugas pemutus: satu query untuk seluruh halaman (hindari N+1)
        $idPemutus = $daftar->getCollection()->pluck('diputuskan_oleh')->filter()->unique()->all();

        return view('pindah.index', [
            'daftar' => $daftar,
            'status' => $status,
            'jumlah' => KeuTransferInternal::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'kelompokMenunggu' => KeuTransferInternal::where('status', KeuTransferInternal::STATUS_MENUNGGU)
                ->where('jenis', PencocokTransfer::JENIS_KELOMPOK)->count(),
            'massal' => $this->keputusan->ringkasanMassal(),
            'namaPemutus' => $idPemutus === [] ? collect() : User::whereIn('id', $idPemutus)->pluck('name', 'id'),
        ]);
    }

    public function cari(Request $request)
    {
        try {
            $hasil = $this->usulan->cari($request->user());
        } catch (UniqueConstraintViolationException $e) {
            // Ada pencarian lain yang baru saja menulis pasangan yang sama; transaksi dibatalkan
            report($e);

            return back()->withErrors(['aksi' => 'Ada pencarian lain yang baru saja berjalan. Tidak ada yang tersimpan dari pencarian ini; muat ulang halaman lalu ulangi.']);
        }

        $kembali = redirect()->route('pindah.index')->with('status', $hasil['baru'] > 0
            ? "Ditemukan {$hasil['baru']} usulan baru senilai ".Uang::rupiah($hasil['totalSen'])." (jendela {$hasil['jendelaJam']} jam). Silakan periksa dan konfirmasi."
            : 'Tidak ada usulan baru.');

        if ($hasil['ambigu'] > 0) {
            $kembali->with('peringatan', "{$hasil['ambigu']} kelompok transaksi bernominal sama tidak bisa dipasangkan otomatis "
                .'(jumlah yang keluar dan yang masuk tidak seimbang). Kelompok ini tidak diusulkan; periksa manual.');
        }

        return $kembali;
    }

    public function setujui(Request $request, KeuTransferInternal $transfer)
    {
        return $this->jalankan(
            fn () => $this->keputusan->konfirmasi($transfer->id, $request->user()),
            'Dikonfirmasi sebagai pindah dana antar rekening RS.'
        );
    }

    public function tolak(Request $request, KeuTransferInternal $transfer)
    {
        return $this->jalankan(
            fn () => $this->keputusan->tolak($transfer->id, $request->user()),
            'Ditandai bukan pindah dana; kedua transaksi dihitung sebagai transaksi biasa.'
        );
    }

    public function kembalikan(Request $request, KeuTransferInternal $transfer)
    {
        return $this->jalankan(
            fn () => $this->keputusan->kembalikan($transfer->id, $request->user()),
            'Dikembalikan ke menunggu konfirmasi.'
        );
    }

    public function konfirmasiMassal(Request $request)
    {
        $data = $request->validate([
            'jumlah'    => ['required', 'integer', 'min:1'],
            'total_sen' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $jumlah = $this->keputusan->konfirmasiMassal($request->user(), (int) $data['jumlah'], (int) $data['total_sen']);
        } catch (KeputusanDitolak $e) {
            return back()->withErrors(['aksi' => $e->getMessage()]);
        }

        return back()->with('status', "{$jumlah} pasangan dikonfirmasi sebagai pindah dana antar rekening RS.");
    }

    private function jalankan(callable $aksi, string $pesanSukses)
    {
        try {
            $aksi();
        } catch (KeputusanDitolak $e) {
            return back()->withErrors(['aksi' => $e->getMessage()]);
        }

        return back()->with('status', $pesanSukses);
    }
}