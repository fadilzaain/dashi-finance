@extends('layouts.dasar')

@section('judul', 'Beranda · DASHI Finance')

@section('isi')
    @if (session('status'))
        <div class="info">{{ session('status') }}</div>
    @endif

    <div class="kartu lebar">
        <h1>Beranda</h1>
        <p class="sub">Pilih pekerjaan yang mau dilakukan.</p>

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
        </ul>
    </div>
@endsection