<?php

namespace App\Http\Controllers;

use App\Models\KeuImporBatch;
use App\Models\KeuMutasiBank;
use App\Models\KeuRekeningBank;
use App\Models\KeuTransferInternal;
use App\Services\Keuangan\Uang;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BerandaController extends Controller
{
    public function index()
    {
        // Mutasi terakhir dan jumlah mutasi di data final, per rekening (satu query)
        $ringkasMutasi = KeuMutasiBank::query()
            ->selectRaw('rekening_id, MAX(tanggal_transaksi) as terakhir, COUNT(*) as jumlah')
            ->groupBy('rekening_id')
            ->get()
            ->keyBy('rekening_id');

        $hariIni = now('Asia/Jakarta')->startOfDay();

        $rekening = KeuRekeningBank::where('aktif', true)->orderBy('bank')->orderBy('kode')->get()
            ->map(function (KeuRekeningBank $r) use ($ringkasMutasi, $hariIni) {
                $baris = $ringkasMutasi->get($r->id);
                $terakhir = $baris?->terakhir ? Carbon::parse($baris->terakhir, 'Asia/Jakarta') : null;

                return [
                    'kode'     => $r->kode,
                    'nama'     => $r->nama,
                    'bank'     => $r->bank,
                    'terakhir' => $terakhir,
                    'hariLalu' => $terakhir ? (int) $terakhir->copy()->startOfDay()->diffInDays($hariIni, true) : null,
                    'jumlah'   => (int) ($baris?->jumlah ?? 0),
                ];
            });

        // Usulan pindah dana yang menunggu konfirmasi: jumlah dan nilai total
        $pindah = DB::connection('dashi_keu')->table('keu_transfer_internal as t')
            ->join('keu_mutasi_bank as d', 'd.id', '=', 't.debit_mutasi_id')
            ->where('t.status', KeuTransferInternal::STATUS_MENUNGGU)
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(d.nominal), 0) as total')
            ->first();

        return view('beranda', [
            'rekening'      => $rekening,
            'batchMenunggu' => KeuImporBatch::where('status', KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI)->count(),
            'pindahJumlah'  => (int) $pindah->jumlah,
            'pindahSen'     => Uang::dariTeks((string) $pindah->total) ?? 0,
        ]);
    }
}