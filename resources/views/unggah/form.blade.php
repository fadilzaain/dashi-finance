@extends('layouts.dasar')

@section('judul', 'Unggah Mutasi · DASHI Finance')

@section('isi')
    <div class="kartu sedang">
        <h1>Unggah mutasi rekening</h1>
        <p class="sub">Pilih rekening, lalu unggah file mutasi dari bank. File dibaca dan diperiksa dulu; belum ada yang masuk ke data final.</p>

        <form method="POST" action="{{ route('unggah.simpan') }}" enctype="multipart/form-data" id="form-unggah">
            @csrf

            <div class="field">
                <label for="rekening_id">Rekening</label>
                <select id="rekening_id" name="rekening_id" class="input" required>
                    <option value="">Pilih rekening…</option>
                    @foreach ($rekening as $r)
                        <option value="{{ $r->id }}" @selected(old('rekening_id') == $r->id)>
                            {{ $r->kode }} · {{ $r->bank }} {{ $r->nomor_rekening }} · {{ $r->nama }}
                        </option>
                    @endforeach
                </select>
                @error('rekening_id')
                    <p class="galat">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="berkas">File mutasi (.xls, .xlsx, .csv · maksimal 10 MB)</label>
                <input id="berkas" name="berkas" type="file" accept=".xls,.xlsx,.csv" class="input" required>
                <p class="petunjuk" id="info-berkas"></p>
                @error('berkas')
                    <p class="galat">
                        {{ $message }}
                        @if (session('batch_sama'))
                            <a href="{{ route('unggah.tampil', session('batch_sama')) }}">Lihat unggahan itu</a>
                        @endif
                    </p>
                @enderror
            </div>

            <ul class="daftar">
                <li><strong>BNI:</strong> pakai file .xls asli dari bank. CSV hanya cadangan; isinya tanpa saldo, jadi tidak bisa direkonsiliasi.</li>
                <li><strong>BPD:</strong> pakai CSV asli dari bank. File Excel BPD ditolak bila pernah dibuka lalu disimpan ulang di Excel.</li>
                <li>File yang sama persis tidak diproses dua kali untuk rekening yang sama.</li>
            </ul>

            <button type="submit" class="tombol" id="tombol-unggah">Unggah dan periksa</button>
        </form>
    </div>

    <div class="kartu lebar terpisah">
        <h2>Unggahan terakhir</h2>

        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Rekening</th>
                        <th>File</th>
                        <th>Periode</th>
                        <th class="kanan">Baris</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $b)
                        <tr>
                            <td>{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $b->rekening?->kode ?? '–' }}</td>
                            <td title="{{ $b->nama_file_asli }}">{{ \Illuminate\Support\Str::limit($b->nama_file_asli, 36) }}</td>
                            <td>
                                @if ($b->periode_awal && $b->periode_akhir)
                                    {{ $b->periode_awal->format('d/m/Y') }} – {{ $b->periode_akhir->format('d/m/Y') }}
                                @else
                                    –
                                @endif
                            </td>
                            <td class="kanan">{{ $b->jumlah_baris }}</td>
                            <td><x-lencana :status="$b->status" /></td>
                            <td><a href="{{ route('unggah.tampil', $b) }}">Lihat</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="kosong">Belum ada unggahan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection