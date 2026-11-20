@extends('layouts.dasar')

@section('judul', 'Masuk · DASHI Finance')

@section('isi')
    <div class="masuk">
        <section class="masuk-panel">
            <div class="merek-besar">DASHI Finance</div>

            <div>
                <p class="masuk-judul">Pencatatan mutasi rekening bank RSUD Jombang</p>
                <p>Unggah file mutasi BNI dan BPD, periksa hasil rekonsiliasinya, lalu simpan ke data final.</p>
            </div>

            <p>Akses hanya untuk petugas yang akunnya dibuat admin.</p>
        </section>

        <section class="masuk-form">
            <div class="kartu">
                <h1>Masuk</h1>
                <p class="sub">Gunakan email dan password akunmu.</p>

                <form method="POST" action="{{ route('login.post') }}">
                    @csrf
                    <x-input name="email" label="Email" type="email" autocomplete="username" required autofocus />
                    <x-input name="password" label="Password" type="password" autocomplete="current-password" required />
                    <button type="submit" class="tombol">Masuk</button>
                </form>
            </div>
        </section>
    </div>
@endsection