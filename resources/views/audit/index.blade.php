@extends('layouts.dasar')

@section('judul', 'Audit Log · DASHI Finance')

@section('isi')
    <div class="kartu lebar">
        <h1>Audit log</h1>
        <p class="sub">Jejak aktivitas penting. Hanya bisa bertambah: tidak bisa diubah atau dihapus lewat aplikasi.</p>

        {{-- Filter --}}
        <form method="GET" action="{{ route('audit.index') }}" class="filter-audit">
            <div class="field">
                <label for="aktivitas">Aktivitas</label>
                <select id="aktivitas" name="aktivitas" class="input">
                    <option value="">Semua</option>
                    @foreach ($daftarAktivitas as $kode => $label)
                        <option value="{{ $kode }}" @selected(($filter['aktivitas'] ?? null) === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="user_id">Pengguna</label>
                <select id="user_id" name="user_id" class="input">
                    <option value="">Semua</option>
                    @foreach ($daftarPengguna as $p)
                        <option value="{{ $p->id }}" @selected((int) ($filter['user_id'] ?? 0) === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="dari">Dari tanggal</label>
                <input id="dari" name="dari" type="date" class="input" value="{{ $filter['dari'] ?? '' }}">
                @error('dari')<p class="galat">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="sampai">Sampai tanggal</label>
                <input id="sampai" name="sampai" type="date" class="input" value="{{ $filter['sampai'] ?? '' }}">
                @error('sampai')<p class="galat">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="tombol sempit">Terapkan</button>
            <a href="{{ route('audit.index') }}" class="tombol kecil">Reset</a>
        </form>

        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Aktivitas</th>
                        <th>Objek</th>
                        <th>Keterangan</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($log as $l)
                        <tr>
                            <td>{{ $l->created_at->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $l->user_nama ?? '–' }}</td>
                            <td>{{ $daftarAktivitas[$l->aktivitas] ?? $l->aktivitas }}</td>
                            <td>
                                @if ($l->objek_tipe === 'batch' && isset($kodeBatch[$l->objek_id]))
                                    <a href="{{ route('unggah.tampil', $kodeBatch[$l->objek_id]) }}">Batch #{{ $l->objek_id }}</a>
                                @elseif ($l->objek_tipe)
                                    {{ $l->objek_tipe }} #{{ $l->objek_id }}
                                @else
                                    –
                                @endif
                            </td>
                            <td>
                                {{ $l->keterangan ?? '–' }}

                                @if ($l->data_sebelum || $l->data_sesudah)
                                    <details class="audit-detail">
                                        <summary>Rincian</summary>
                                        @if ($l->data_sebelum)
                                            <strong>Sebelum</strong>
                                            <pre>{{ json_encode($l->data_sebelum, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                        @if ($l->data_sesudah)
                                            <strong>Sesudah</strong>
                                            <pre>{{ json_encode($l->data_sesudah, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                    </details>
                                @endif
                            </td>
                            <td>{{ $l->ip ?? '–' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="kosong">Tidak ada catatan untuk filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($log->hasPages())
            <nav class="halaman" aria-label="Navigasi halaman">
                @if ($log->onFirstPage())
                    <span>‹ Sebelumnya</span>
                @else
                    <a href="{{ $log->previousPageUrl() }}" rel="prev">‹ Sebelumnya</a>
                @endif

                @if ($log->hasMorePages())
                    <a href="{{ $log->nextPageUrl() }}" rel="next">Berikutnya ›</a>
                @else
                    <span>Berikutnya ›</span>
                @endif
            </nav>
        @endif
    </div>
@endsection