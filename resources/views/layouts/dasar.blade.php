<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('judul', 'DASHI Finance')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
    {{-- Pasang tema sebelum halaman digambar supaya tidak berkedip --}}
    <script>document.documentElement.dataset.theme = localStorage.getItem('tema') || 'dark';</script>
    @vite('resources/css/app.css')
</head>
<body>
    @auth
        <header class="bar">
            <span class="merek">DASHI Finance</span>
            <div class="kanan">
                <span>{{ auth()->user()->name }} · {{ \App\Models\User::LABEL_ROLE[auth()->user()->role] ?? auth()->user()->role }}</span>
                <a href="{{ route('akun.password') }}">Ganti password</a>
                <button type="button" class="tombol kecil" id="ganti-tema">Tema</button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="tombol kecil">Keluar</button>
                </form>
            </div>
        </header>
    @endauth

    <main class="@guest tengah @endguest">
        @yield('isi')
    </main>

    <script>
        document.getElementById('ganti-tema')?.addEventListener('click', () => {
            const baru = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = baru;
            localStorage.setItem('tema', baru);
        });
    </script>
</body>
</html>