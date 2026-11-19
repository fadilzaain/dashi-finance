<?php

namespace App\Http\Controllers;

use App\Models\KeuAuditLog;
use App\Models\KeuImporBatch;
use App\Models\User;
use App\Services\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    private const PER_HALAMAN = 50;

    public function index(Request $request)
    {
        $filter = $request->validate([
            'aktivitas' => ['nullable', Rule::in(array_keys(AuditLog::LABEL))],
            'user_id'   => ['nullable', 'integer', 'min:1'],
            'dari'      => ['nullable', 'date_format:Y-m-d'],
            'sampai'    => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ], [
            'dari.date_format'       => 'Format tanggal awal tidak valid.',
            'sampai.date_format'     => 'Format tanggal akhir tidak valid.',
            'sampai.after_or_equal'  => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $log = KeuAuditLog::query()
            ->when($filter['aktivitas'] ?? null, fn ($q, $v) => $q->where('aktivitas', $v))
            ->when($filter['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            // Rentang waktu dibuat setengah terbuka agar memakai indeks created_at (whereDate tidak bisa)
            ->when($filter['dari'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($filter['sampai'] ?? null, fn ($q, $v) => $q->where('created_at', '<', CarbonImmutable::parse($v)->addDay()->startOfDay()))
            ->orderByDesc('id')
            ->simplePaginate(self::PER_HALAMAN)
            ->withQueryString();

        // Satu query untuk semua tautan batch di halaman ini (hindari N+1)
        $idBatch = $log->getCollection()->where('objek_tipe', 'batch')->pluck('objek_id')->filter()->unique()->all();
        $kodeBatch = $idBatch === [] ? collect() : KeuImporBatch::whereIn('id', $idBatch)->pluck('kode_batch', 'id');

        return view('audit.index', [
            'log' => $log,
            'kodeBatch' => $kodeBatch,
            'filter' => $filter,
            'daftarAktivitas' => AuditLog::LABEL,
            'daftarPengguna' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}