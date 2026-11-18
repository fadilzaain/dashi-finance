@extends('layouts.dasar')

@section('judul', 'Masuk · DASHI Finance')

@section('isi')
    <div class="kartu">
        <h1>DASHI Finance</h1>
        <p class="sub">Masuk untuk mengunggah mutasi rekening.</p>

        <form method="POST" action="{{ route('login.post') }}">
            @csrf
            <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
            <x-input name="password" label="Password" type="password" autocomplete="current-password" required />
            <button type="submit" class="tombol">Masuk</button>
        </form>
    </div>
@endsection