<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>@yield('judul', 'DASHI Finance')</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%2317293A'/%3E%3Cpath d='M9 11h14M9 16h14M9 21h9' stroke='%238CC4E6' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E">
    {{-- Pasang tema sebelum halaman digambar supaya tidak berkedip. Tanpa pilihan tersimpan, ikut pengaturan sistem. --}}
    <script>
        (function () {
            var tema = null;
            try { tema = localStorage.getItem('tema'); } catch (e) {}
            if (tema !== 'dark' && tema !== 'light') {
                tema = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.dataset.theme = tema;
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="lompat" href="#isi">Lewati ke isi halaman</a>
    <div class="memuat-bar" aria-hidden="true"></div>

    {{-- Toast: pesan sukses (status) dan peringatan dari server; dikelola tampilan.js --}}
    <div class="toast-wadah" role="status" aria-live="polite">
        @if (session('status'))
            <div class="toast ok">
                <span class="toast-ikon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                </span>
                <p>{{ session('status') }}</p>
                <button type="button" class="toast-tutup" aria-label="Tutup pesan">&times;</button>
                <span class="toast-bar" aria-hidden="true"></span>
            </div>
        @endif
        @if (session('peringatan'))
            <div class="toast peringatan">
                <span class="toast-ikon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l9 16H3L12 4z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/></svg>
                </span>
                <p>{{ session('peringatan') }}</p>
                <button type="button" class="toast-tutup" aria-label="Tutup pesan">&times;</button>
                <span class="toast-bar" aria-hidden="true"></span>
            </div>
        @endif
    </div>

    @auth
        <header class="bar">
            <div class="kiri">
                {{-- Merek: logo RSUD (public/images/logo-rsud.png) + nama aplikasi, klik kembali ke beranda --}}
                <a href="{{ route('beranda') }}" class="merek">
                    @if (file_exists(public_path('images/logo-rsud-jombang.png')))
                        <img src="{{ asset('images/logo-rsud-jombang.png') }}" alt="" class="merek-logo">
                    @else
                        <span class="merek-tanda" aria-hidden="true"></span>
                    @endif
                    <span>DASHI Finance</span>
                </a>
                <nav class="menu" aria-label="Menu utama">
                    <a href="{{ route('beranda') }}" @class(['aktif' => request()->routeIs('beranda')]) @if (request()->routeIs('beranda')) aria-current="page" @endif>Beranda</a>
                    <a href="{{ route('unggah.form') }}" @class(['aktif' => request()->routeIs('unggah.*')]) @if (request()->routeIs('unggah.*')) aria-current="page" @endif>Unggah mutasi</a>
                    <a href="{{ route('pindah.index') }}" @class(['aktif' => request()->routeIs('pindah.*')]) @if (request()->routeIs('pindah.*')) aria-current="page" @endif>Pindah dana</a>
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('pengguna.index') }}" @class(['aktif' => request()->routeIs('pengguna.*')]) @if (request()->routeIs('pengguna.*')) aria-current="page" @endif>Kelola akun</a>
                        <a href="{{ route('audit.index') }}" @class(['aktif' => request()->routeIs('audit.*')]) @if (request()->routeIs('audit.*')) aria-current="page" @endif>Audit log</a>
                    @endif
                </nav>
            </div>
            <div class="kanan">
                <span>{{ auth()->user()->name }} · {{ \App\Models\User::LABEL_ROLE[auth()->user()->role] ?? auth()->user()->role }}</span>
                <a href="{{ route('akun.password') }}">Ganti password</a>
                <button type="button" class="tombol kecil" id="ganti-tema"><span id="label-tema">Mode gelap</span></button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="tombol kecil">Keluar</button>
                </form>
            </div>
        </header>
    @endauth

    <main id="isi" class="@guest tengah @endguest">
        @yield('isi')
    </main>
</body>
</html>