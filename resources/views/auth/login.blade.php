@extends('layouts.dasar')

@section('judul', 'Masuk · DASHI Finance')

@section('isi')
    <div class="masuk">
        {{-- Tombol tema: halaman login tidak punya header, jadi ditaruh di pojok kanan atas --}}
        <x-tombol-tema class="masuk-tema" />
        <div class="masuk-kartu @if ($errors->any()) getar @endif">
            <div class="masuk-kepala">
                {{-- Logo dibaca dari public/images/logo-rsud-jombang.png; bila belum ada, tampil tanda bawaan --}}
                @if (file_exists(public_path('images/logo-rsud-jombang.png')))
                    <img src="{{ asset('images/logo-rsud-jombang.png') }}" alt="Logo RSUD Kabupaten Jombang" class="masuk-logo muncul" style="--d:.1s">
                @else
                    <span class="masuk-logo-cadangan muncul" style="--d:.1s" aria-hidden="true"></span>
                @endif

                <h1 class="muncul" style="--d:.2s">DASHI Finance</h1>
                <p class="masuk-instansi muncul" style="--d:.28s">RSUD Kabupaten Jombang</p>
            </div>

            <p class="masuk-sapa muncul" style="--d:.36s">Masuk untuk mencatat dan memeriksa mutasi rekening bank.</p>

            <form method="POST" action="{{ route('login.post') }}" id="form-masuk">
                @csrf

                {{-- Label melayang: placeholder dibuat spasi supaya CSS tahu kapan kolom sudah terisi --}}
                <div class="fl muncul @error('email') ada-galat @enderror" style="--d:.44s">
                    <div class="fl-kotak">
                        <input id="email" name="email" type="email" class="fl-input" placeholder=" "
                               value="{{ old('email') }}" autocomplete="username" required autofocus>
                        <label for="email">Email</label>
                    </div>
                    @error('email')
                        <p class="galat">{{ $message }}</p>
                    @enderror
                </div>

                <div class="fl fl-sandi muncul @error('password') ada-galat @enderror" style="--d:.52s">
                    <div class="fl-kotak">
                        <input id="password" name="password" type="password" class="fl-input" placeholder=" "
                               autocomplete="current-password" required>
                        <label for="password">Password</label>
                        <button type="button" class="fl-mata" data-lihat-sandi="#password" aria-label="Tampilkan password" aria-pressed="false">
                            <svg class="mata-buka" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="mata-tutup" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2"/><path d="M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.4-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                    @error('password')
                        <p class="galat">{{ $message }}</p>
                    @enderror
                    {{-- Peringatan Caps Lock: dinyalakan masuk.js --}}
                    <p class="fl-caps" hidden>Caps Lock sedang aktif.</p>
                </div>

                <button type="submit" class="tombol tombol-masuk muncul" style="--d:.6s">
                    <span class="tombol-teks">Masuk</span>
                    <span class="tombol-putar" aria-hidden="true"></span>
                </button>
            </form>

            <p class="masuk-catatan muncul" style="--d:.68s">Akses hanya untuk petugas yang akunnya dibuat admin.</p>
        </div>

        <p class="masuk-kaki muncul" style="--d:.76s">&copy; {{ date('Y') }} IT WORKS RSUD Jombang</p>
    </div>
@endsection