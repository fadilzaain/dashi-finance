@extends('layouts.dasar')

@section('judul', 'Beranda · DASHI Finance')

@section('isi')
    @if (session('status'))
        <div class="info">{{ session('status') }}</div>
    @endif

    <div class="kartu lebar">
        <h1>Beranda</h1>
        <p class="sub">Unggah mutasi rekening BNI dan BPD untuk dibaca dan diperiksa sebelum disimpan.</p>
        <a href="{{ route('unggah.form') }}" class="tombol sempit">Unggah mutasi</a>
    </div>
@endsection