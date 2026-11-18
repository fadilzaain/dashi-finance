@extends('layouts.dasar')

@section('judul', 'Beranda · DASHI Finance')

@section('isi')
    @if (session('status'))
        <div class="info">{{ session('status') }}</div>
    @endif

    <div class="kartu lebar">
        <h1>Beranda</h1>
        <p class="sub">Fondasi aplikasi sudah berjalan. Unggah mutasi rekening menyusul di milestone berikutnya.</p>
    </div>
@endsection