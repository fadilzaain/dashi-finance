@extends('layouts.dasar')

@section('judul', 'Beranda · DASHI Finance')

@use('App\Services\Keuangan\Uang')

@section('isi')
    @php
        // Sapaan awal dihitung di server (zona waktu WIB) supaya tidak berkedip; jam berjalan diurus tampilan.js
        $sekarang = now()->timezone('Asia/Jakarta')->locale('id');
        $jam = (int) $sekarang->format('G');
        $salam = match (true) {
            $jam >= 4 && $jam < 11 => 'Selamat pagi',
            $jam >= 11 && $jam < 15 => 'Selamat siang',
            $jam >= 15 && $jam < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };
        $namaPeran = \App\Models\User::LABEL_ROLE[auth()->user()->role] ?? auth()->user()->role;
        $angkaPindah = substr(Uang::rupiah($pindahSen), 2);   // tanpa awalan "Rp"
    @endphp

    {{-- Kartu selamat datang: sapaan sesuai waktu, nama pengguna, tanggal, dan jam berjalan --}}
    <div class="kartu lebar sambutan">
        <div class="sambutan-teks">
            <p class="sambutan-salam" data-salam>{{ $salam }}</p>
            <h1>Selamat datang, {{ auth()->user()->name }}</h1>
            <p class="sub">{{ $namaPeran }} · RSUD Kabupaten Jombang</p>
        </div>

        <div class="sambutan-jam" aria-label="Waktu saat ini">
            <time class="jam" data-jam>{{ $sekarang->format('H:i:s') }}</time>
            <span class="tanggal" data-tanggal>{{ $sekarang->isoFormat('dddd, D MMMM YYYY') }}</span>
            <span class="zona">WIB</span>
        </div>
    </div>

    <div class="beranda-grid">
        {{-- Kartu: yang menunggu tindakan petugas --}}
        <div class="kartu lebar">
            <h2>Perlu tindakan</h2>

            @if ($batchMenunggu > 0 || $pindahJumlah > 0)
                <ul class="tindakan">
                    @if ($batchMenunggu > 0)
                        <li>
                            <a href="{{ route('unggah.form') }}">
                                <span class="tindakan-angka" data-hitung="{{ $batchMenunggu }}">{{ $batchMenunggu }}</span>
                                <span class="tindakan-teks">
                                    <strong>Unggahan menunggu disimpan</strong>
                                    <span>Periksa hasil rekonsiliasi, lalu simpan ke data final.</span>
                                </span>
                                <span></span>
                            </a>
                        </li>
                    @endif
                    @if ($pindahJumlah > 0)
                        <li>
                            <a href="{{ route('pindah.index') }}">
                                <span class="tindakan-angka" data-hitung="{{ $pindahJumlah }}">{{ $pindahJumlah }}</span>
                                <span class="tindakan-teks">
                                    <strong>Usulan pindah dana menunggu konfirmasi</strong>
                                    <span>Uang RS yang dipindah antar rekening sendiri.</span>
                                </span>
                                <span class="tindakan-nilai">Rp<span data-hitung="{{ intdiv($pindahSen, 100) }}">{{ $angkaPindah }}</span></span>
                            </a>
                        </li>
                    @endif
                </ul>
            @else
                <p class="petunjuk">Tidak ada yang menunggu tindakan.</p>
            @endif
        </div>

        {{-- Kartu: pintasan pekerjaan --}}
        <div class="kartu lebar">
            <h2>Pekerjaan</h2>

            <ul class="pintasan">
                <li>
                    <a href="{{ route('unggah.form') }}">
                        <strong>Unggah mutasi</strong>
                        <span>Baca file mutasi BNI dan BPD, periksa hasilnya, lalu simpan ke data final.</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('pindah.index') }}">
                        <strong>Pindah dana antar rekening</strong>
                        <span>Konfirmasi uang RS yang dipindahkan antar rekening milik sendiri.</span>
                    </a>
                </li>
                @if (auth()->user()->isAdmin())
                    <li>
                        <a href="{{ route('pengguna.index') }}">
                            <strong>Kelola akun</strong>
                            <span>Tambah petugas, atur peran, nonaktifkan akun, dan reset password.</span>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    </div>

    {{-- Kartu: keadaan data final tiap rekening --}}
    <div class="kartu lebar terpisah">
        <h2>Data final per rekening</h2>

        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Rekening</th>
                        <th>Bank</th>
                        <th>Mutasi terakhir</th>
                        <th class="kanan">Jumlah mutasi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rekening as $r)
                        <tr>
                            <td>{{ $r['kode'] }}<span class="waktu-kecil">{{ $r['nama'] }}</span></td>
                            <td>{{ $r['bank'] }}</td>
                            <td>
                                @if ($r['terakhir'])
                                    {{ $r['terakhir']->format('d/m/Y') }}
                                    <span class="waktu-kecil">{{ $r['hariLalu'] === 0 ? 'Hari ini' : $r['hariLalu'].' hari lalu' }}</span>
                                @else
                                    <span class="lencana netral">Belum ada data</span>
                                @endif
                            </td>
                            <td class="kanan">{{ number_format($r['jumlah'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="kosong">Belum ada rekening aktif.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="petunjuk">Tanggal transaksi terakhir yang sudah tersimpan di data final.</p>
    </div>
@endsection