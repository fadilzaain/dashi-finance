@extends('layouts.dasar')

@section('judul', 'Ganti Password · DASHI Finance')

@section('isi')
    <div class="kartu">
        <h1>Ganti password</h1>
        <p class="sub">Minimal 10 karakter, memuat huruf besar, huruf kecil, dan angka.</p>

        @if (auth()->user()->wajib_ganti_password)
            <div class="info peringatan">Kamu memakai password sementara. Ganti dulu sebelum melanjutkan.</div>
        @endif

        <form method="POST" action="{{ route('akun.password.simpan') }}">
            @csrf
            @method('PUT')
            <x-input name="password_lama" label="Password lama" type="password" autocomplete="current-password" required />
            <x-input name="password" label="Password baru" type="password" autocomplete="new-password" required />
            <x-input name="password_confirmation" label="Ulangi password baru" type="password" autocomplete="new-password" required />
            <button type="submit" class="tombol">Simpan password</button>
        </form>
    </div>
@endsection