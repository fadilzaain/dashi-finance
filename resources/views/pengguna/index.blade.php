@extends('layouts.dasar')

@section('judul', 'Kelola Akun · DASHI Finance')

@section('isi')
    @php
        $sayaId = auth()->id();
    @endphp

    {{-- Galat dari tindakan di tabel (mis. mengubah akun sendiri). Galat form tambah tampil di bawah form-nya --}}
    @if ($errors->default->any())
        <div class="info peringatan">
            @foreach ($errors->default->all() as $pesan)
                <div>{{ $pesan }}</div>
            @endforeach
        </div>
    @endif

    {{-- Password sementara: tampil sekali, tidak bisa dibuka lagi setelah halaman ini ditinggalkan --}}
    @if ($sandi)
        <div class="info sandi-sementara" role="status">
            <strong>Password sementara untuk {{ $sandi['email'] }}</strong>
            <div class="sandi-baris">
                <code class="sandi" id="sandi-teks">{{ $sandi['password'] }}</code>
                <button type="button" class="tombol kecil" data-salin="#sandi-teks">Salin</button>
            </div>
            <span class="petunjuk">Catat dan berikan ke yang bersangkutan sekarang. Password ini hanya tampil sekali dan wajib diganti saat login pertama.</span>
        </div>
    @endif

    <div class="kartu lebar">
        <h1>Kelola akun</h1>
        <p class="sub">Tambah petugas, atur peran, aktifkan atau nonaktifkan akun, dan reset password. Semua tindakan tercatat di audit log.</p>

        <h2>Tambah akun</h2>

        <form method="POST" action="{{ route('pengguna.simpan') }}" class="form-tambah" data-sekali>
            @csrf

            <div class="field">
                <label for="name">Nama lengkap</label>
                <input id="name" name="name" type="text" class="input" value="{{ old('name') }}" maxlength="100" autocomplete="off" required>
                @if ($errors->tambah->has('name'))
                    <p class="galat">{{ $errors->tambah->first('name') }}</p>
                @endif
            </div>

            <div class="field">
                <label for="email">Email (untuk login)</label>
                <input id="email" name="email" type="email" class="input" value="{{ old('email') }}" maxlength="255" autocomplete="off" required>
                @if ($errors->tambah->has('email'))
                    <p class="galat">{{ $errors->tambah->first('email') }}</p>
                @endif
            </div>

            <div class="field">
                <label for="role">Peran</label>
                <select id="role" name="role" class="input" required>
                    @foreach (\App\Models\User::LABEL_ROLE as $kode => $label)
                        <option value="{{ $kode }}" @selected(old('role', \App\Models\User::ROLE_KEUANGAN) === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
                @if ($errors->tambah->has('role'))
                    <p class="galat">{{ $errors->tambah->first('role') }}</p>
                @endif
            </div>

            <button type="submit" class="tombol">Buat akun</button>
        </form>

        <h2 class="atas">Daftar akun ({{ $daftar->count() }})</h2>

        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Peran</th>
                        <th>Status</th>
                        <th>Login terakhir</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($daftar as $u)
                        @php
                            $diriSendiri = $u->id === $sayaId;
                            $labelPeran = \App\Models\User::LABEL_ROLE[$u->role] ?? $u->role;
                        @endphp
                        <tr>
                            <td>
                                {{ $u->name }}
                                @if ($diriSendiri)
                                    <span class="lencana netral">Kamu</span>
                                @endif
                            </td>
                            <td>{{ $u->email }}</td>

                            {{-- Peran: akun sendiri tidak bisa diubah dari sini --}}
                            <td>
                                @if ($diriSendiri)
                                    {{ $labelPeran }}
                                @else
                                    <form method="POST" action="{{ route('pengguna.peran', $u->id) }}" class="sel-peran" data-sekali>
                                        @csrf
                                        @method('PUT')
                                        <select name="role" class="input kecil" aria-label="Peran {{ $u->name }}">
                                            @foreach (\App\Models\User::LABEL_ROLE as $kode => $label)
                                                <option value="{{ $kode }}" @selected($u->role === $kode)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="tombol kecil">Simpan</button>
                                    </form>
                                @endif
                            </td>

                            <td>
                                <x-lencana :status="$u->status" />
                                @if ($u->wajib_ganti_password)
                                    <span class="waktu-kecil">Belum ganti password sementara</span>
                                @endif
                            </td>

                            <td>
                                @if (isset($loginTerakhir[$u->id]))
                                    {{ \Illuminate\Support\Carbon::parse($loginTerakhir[$u->id])->format('d/m/Y H:i') }}
                                @else
                                    <span class="petunjuk">Belum pernah</span>
                                @endif
                            </td>

                            <td>
                                @if ($diriSendiri)
                                    <span class="petunjuk">Akunmu sendiri. Ganti password lewat menu di atas.</span>
                                @else
                                    <div class="aksi-baris">
                                        <form method="POST" action="{{ route('pengguna.status', $u->id) }}" data-sekali
                                              @if ($u->isAktif()) data-konfirmasi="Nonaktifkan akun {{ $u->name }}? Dia langsung dikeluarkan dan tidak bisa masuk lagi sampai diaktifkan." @endif>
                                            @csrf
                                            <input type="hidden" name="aksi" value="{{ $u->isAktif() ? 'nonaktifkan' : 'aktifkan' }}">
                                            <button type="submit" class="tombol kecil">{{ $u->isAktif() ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        </form>

                                        <form method="POST" action="{{ route('pengguna.reset', $u->id) }}" data-sekali
                                              data-konfirmasi="Reset password {{ $u->name }}? Password lamanya langsung tidak berlaku dan sesi yang sedang berjalan diakhiri.">
                                            @csrf
                                            <button type="submit" class="tombol kecil">Reset password</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection