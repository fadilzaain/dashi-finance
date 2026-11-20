@extends('layouts.dasar')

@section('judul', 'Pindah Dana Antar Rekening · DASHI Finance')

@use('App\Models\KeuTransferInternal')
@use('App\Services\Keuangan\Uang')

@section('isi')
    @php
        // Hanya pemformatan tampilan
        $durasi = function (int $detik): string {
            $menit = intdiv(abs($detik), 60);

            return ($detik < 0 ? '-' : '').intdiv($menit, 60).' j '.sprintf('%02d', $menit % 60).' m';
        };
        $rp = fn ($nilai) => Uang::rupiah(Uang::dariTeks((string) $nilai) ?? 0);

        $labelStatus = [
            KeuTransferInternal::STATUS_MENUNGGU => 'Menunggu konfirmasi',
            KeuTransferInternal::STATUS_TERKONFIRMASI => 'Terkonfirmasi: pindah dana internal',
            KeuTransferInternal::STATUS_DITOLAK => 'Bukan pindah dana',
        ];
    @endphp

    @if ($errors->any())
        <div class="info peringatan">
            @foreach ($errors->all() as $pesan)
                <div>{{ $pesan }}</div>
            @endforeach
        </div>
    @endif

    <div class="kartu lebar">
        <h1>Pindah dana antar rekening</h1>
        <p class="sub">
            Uang RS yang dipindahkan antar rekening milik sendiri (misalnya BNI ke BPD) bukan pendapatan dan bukan belanja.
            Sistem hanya mengusulkan transaksi keluar dan masuk yang nominalnya sama persis dan waktunya berdekatan.
            Petugas yang memutuskan. Hanya yang terkonfirmasi yang dikeluarkan dari angka pendapatan dan belanja.
        </p>

        <div class="panel-aksi">
            <form method="POST" action="{{ route('pindah.cari') }}" data-sekali>
                @csrf
                <button type="submit" class="tombol sempit">Cari usulan baru</button>
            </form>

            @if ($status === KeuTransferInternal::STATUS_MENUNGGU && $massal['jumlah'] > 0)
                <form method="POST" action="{{ route('pindah.massal') }}" data-sekali
                      data-konfirmasi="{{ $massal['jumlah'] }} pasangan senilai {{ Uang::rupiah($massal['totalSen']) }} akan dikonfirmasi sebagai pindah dana antar rekening RS dan tidak dihitung sebagai pendapatan/belanja. Lanjutkan?">
                    @csrf
                    <input type="hidden" name="jumlah" value="{{ $massal['jumlah'] }}">
                    <input type="hidden" name="total_sen" value="{{ $massal['totalSen'] }}">
                    <button type="submit" class="tombol sempit">Konfirmasi semua yang cocok persis ({{ $massal['jumlah'] }})</button>
                </form>
                <span class="petunjuk">Senilai {{ Uang::rupiah($massal['totalSen']) }}</span>
            @endif
        </div>

        @if ($status === KeuTransferInternal::STATUS_MENUNGGU && $kelompokMenunggu > 0)
            <div class="info peringatan">
                {{ $kelompokMenunggu }} usulan berjenis <strong>kelompok</strong> (beberapa transfer bernominal sama pada waktu berdekatan,
                dipasangkan berurutan waktu) tidak ikut konfirmasi massal. Periksa dan putuskan satu per satu.
            </div>
        @endif

        <nav class="tab-status" aria-label="Status usulan">
            @foreach ($labelStatus as $kode => $teks)
                <a href="{{ route('pindah.index', ['status' => $kode]) }}" @class(['aktif' => $status === $kode])>{{ $teks }} ({{ $jumlah[$kode] ?? 0 }})</a>
            @endforeach
        </nav>

        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Keluar dari</th>
                        <th>Masuk ke</th>
                        <th class="kanan">Nominal</th>
                        <th>Selisih waktu</th>
                        <th>Kecocokan</th>
                        <th>Status</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $t)
                        <tr>
                            <td>
                                {{ $t->debit->rekening->kode }}
                                <span class="waktu-kecil">{{ $t->debit->tanggal_transaksi->format('d/m/Y H:i') }}</span>
                            </td>
                            <td>
                                {{ $t->kredit->rekening->kode }}
                                <span class="waktu-kecil">{{ $t->kredit->tanggal_transaksi->format('d/m/Y H:i') }}</span>
                            </td>
                            <td class="kanan">{{ $rp($t->debit->nominal) }}</td>
                            <td>{{ $durasi($t->selisih_detik) }}</td>
                            <td>
                                @if ($t->jenis === 'pasti')
                                    <span class="lencana ok">Cocok persis</span>
                                @else
                                    <span class="lencana peringatan" title="Beberapa transfer bernominal sama, dipasangkan berurutan waktu. Periksa teliti.">Kelompok</span>
                                @endif
                            </td>
                            <td>
                                <x-lencana :status="$t->status" />
                                @if ($t->diputuskan_pada)
                                    <span class="waktu-kecil">
                                        {{ $namaPemutus[$t->diputuskan_oleh] ?? 'pengguna #'.$t->diputuskan_oleh }},
                                        {{ $t->diputuskan_pada->format('d/m/Y H:i') }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if ($t->status === KeuTransferInternal::STATUS_MENUNGGU)
                                    <div class="aksi-baris">
                                        <form method="POST" action="{{ route('pindah.setujui', $t->id) }}" data-sekali>
                                            @csrf
                                            <button type="submit" class="tombol kecil utama">Ya, pindah dana internal</button>
                                        </form>
                                        <form method="POST" action="{{ route('pindah.tolak', $t->id) }}" data-sekali>
                                            @csrf
                                            <button type="submit" class="tombol kecil">Bukan, transaksi biasa</button>
                                        </form>
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('pindah.kembalikan', $t->id) }}" data-sekali
                                          data-konfirmasi="{{ $t->status === KeuTransferInternal::STATUS_TERKONFIRMASI
                                              ? 'Batalkan konfirmasi? Kedua transaksi akan dihitung sebagai pendapatan/belanja biasa lagi sampai dikonfirmasi ulang.'
                                              : 'Kembalikan ke menunggu konfirmasi?' }}">
                                        @csrf
                                        <button type="submit" class="tombol kecil">Kembalikan ke menunggu</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="kosong">
                                @if ($status === KeuTransferInternal::STATUS_MENUNGGU)
                                    Tidak ada usulan yang menunggu. Klik "Cari usulan baru" setelah mutasi rekening diunggah dan disimpan.
                                @else
                                    Belum ada data di status ini.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($daftar->hasPages())
            <nav class="halaman" aria-label="Navigasi halaman">
                @if ($daftar->onFirstPage())
                    <span>‹ Sebelumnya</span>
                @else
                    <a href="{{ $daftar->previousPageUrl() }}" rel="prev">‹ Sebelumnya</a>
                @endif

                @if ($daftar->hasMorePages())
                    <a href="{{ $daftar->nextPageUrl() }}" rel="next">Berikutnya ›</a>
                @else
                    <span>Berikutnya ›</span>
                @endif
            </nav>
        @endif
    </div>
@endsection