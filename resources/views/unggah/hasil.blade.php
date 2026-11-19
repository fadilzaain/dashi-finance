@extends('layouts.dasar')

@section('judul', 'Hasil Unggah · DASHI Finance')

@use('App\Models\KeuImporBatch')
@use('App\Services\Keuangan\Uang')

@section('isi')
    @php
        // Hanya pemformatan tampilan. Hitungan rekonsiliasi dibuat di controller.
        $rp = fn (?int $nilai) => $nilai === null ? '–' : Uang::rupiah($nilai);
        $rpBaris = function ($nilai) {
            $sen = $nilai === null ? null : Uang::dariTeks((string) $nilai);

            return ($sen === null || $sen === 0) ? '–' : Uang::rupiah($sen);
        };

        $daftarCatatan = $batch->catatan !== null ? array_values(array_filter(explode("\n", $batch->catatan))) : [];
    @endphp

    <p><a href="{{ route('unggah.form') }}">← Kembali ke unggah</a></p>

    <div class="kartu lebar">
        <h1>Hasil unggah <x-lencana :status="$batch->status" /></h1>
        <p class="sub">{{ $batch->nama_file_asli }}</p>

        <dl class="rincian">
            <dt>Rekening</dt>
            <dd>{{ $batch->rekening?->kode }} · {{ $batch->rekening?->bank }} {{ $batch->rekening?->nomor_rekening }}</dd>
            <dt>Format</dt>
            <dd>{{ strtoupper($batch->format_file) }} · {{ number_format($batch->ukuran_byte / 1024, 0, ',', '.') }} KB</dd>
            <dt>Periode</dt>
            <dd>
                @if ($batch->periode_awal && $batch->periode_akhir)
                    {{ $batch->periode_awal->format('d/m/Y') }} – {{ $batch->periode_akhir->format('d/m/Y') }}
                @else
                    –
                @endif
            </dd>
            <dt>Diunggah</dt>
            <dd>{{ $batch->created_at->format('d/m/Y H:i') }}</dd>
        </dl>

        @if (in_array($batch->status, [KeuImporBatch::STATUS_DITOLAK, KeuImporBatch::STATUS_GAGAL], true))
            <div class="info peringatan">
                <strong>File ini tidak diproses.</strong>
                <ul>
                    @foreach ($daftarCatatan as $teks)
                        <li>{{ $teks }}</li>
                    @endforeach
                </ul>
            </div>
            <a href="{{ route('unggah.form') }}" class="tombol sempit">Unggah file lain</a>
        @endif

        @if ($batch->status === KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI)
            <div class="ringkasan">
                <div><strong>{{ $batch->jumlah_baris }}</strong><span>Total baris</span></div>
                <div><strong>{{ $batch->jumlah_valid }}</strong><span>Valid</span></div>
                <div><strong>{{ $batch->jumlah_peringatan }}</strong><span>Peringatan</span></div>
                <div><strong>{{ $batch->jumlah_tidak_valid }}</strong><span>Tidak valid</span></div>
                <div><strong>{{ $batch->jumlah_duplikat }}</strong><span>Duplikat</span></div>
            </div>

            <h2>
                Rekonsiliasi
                @if ($batch->hasil_rekonsiliasi)
                    <x-lencana :status="$batch->hasil_rekonsiliasi" />
                @else
                    <span class="lencana netral">Tidak bisa diperiksa</span>
                @endif
            </h2>

            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th>Pemeriksaan</th>
                            <th class="kanan">Di file</th>
                            <th class="kanan">Hasil hitung</th>
                            <th class="kanan">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($kontrol as $k)
                            <tr>
                                <td>{{ $k['nama'] }}</td>
                                <td class="kanan">{{ $rp($k['file']) }}</td>
                                <td class="kanan">{{ $rp($k['hitung']) }}</td>
                                <td class="kanan @if ($k['selisih'] !== null && $k['selisih'] !== 0) bahaya @endif">{{ $rp($k['selisih']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($daftarCatatan !== [])
                <h2 class="atas">Catatan</h2>
                <ul class="catatan">
                    @foreach ($daftarCatatan as $teks)
                        <li>{{ $teks }}</li>
                    @endforeach
                </ul>
            @endif

            <h2 class="atas">Baris yang perlu diperiksa</h2>

            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Waktu</th>
                            <th>Referensi</th>
                            <th>Deskripsi</th>
                            <th class="kanan">Debit</th>
                            <th class="kanan">Kredit</th>
                            <th>Status</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bermasalah as $b)
                            <tr>
                                <td>{{ $b->nomor_baris }}</td>
                                <td>{{ $b->tanggal_transaksi?->format('d/m/Y H:i') ?? '–' }}</td>
                                <td>{{ $b->nomor_referensi ?? '–' }}</td>
                                <td title="{{ $b->deskripsi }}">{{ \Illuminate\Support\Str::limit($b->deskripsi, 60) }}</td>
                                <td class="kanan">{{ $rpBaris($b->debit) }}</td>
                                <td class="kanan">{{ $rpBaris($b->kredit) }}</td>
                                <td><x-lencana :status="$b->status" /></td>
                                <td>{{ $b->pesan_validasi }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="kosong">Tidak ada baris yang perlu diperiksa. Semua baris valid.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($bermasalah->previousPageUrl() || $bermasalah->nextPageUrl())
                <div class="halaman">
                    @if ($bermasalah->previousPageUrl())
                        <a href="{{ $bermasalah->previousPageUrl() }}">← Sebelumnya</a>
                    @else
                        <span></span>
                    @endif
                    <span>Halaman {{ $bermasalah->currentPage() }}</span>
                    @if ($bermasalah->nextPageUrl())
                        <a href="{{ $bermasalah->nextPageUrl() }}">Berikutnya →</a>
                    @else
                        <span></span>
                    @endif
                </div>
            @endif

            <div class="info atas">
                Data ini baru tahap pemeriksaan. Belum masuk ke data final yang dibaca DASH-i; tombol Simpan menyusul di milestone berikutnya.
            </div>
        @endif
    </div>
@endsection