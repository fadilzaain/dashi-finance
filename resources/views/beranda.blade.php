@extends('layouts.dasar')

@section('judul', 'Beranda · DASHI Finance')

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

    <div class="kartu lebar terpisah">
        <h2>Pilih pekerjaan</h2>

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
@endsection