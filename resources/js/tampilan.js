// Perilaku tampilan yang dipakai semua halaman: pindah tema dan garis progres saat form dikirim.
const html = document.documentElement;
const tombolTema = document.getElementById('ganti-tema');
const labelTema = document.getElementById('label-tema');

const perbaruiLabel = () => {
    if (labelTema) {
        labelTema.textContent = html.dataset.theme === 'dark' ? 'Mode terang' : 'Mode gelap';
    }
};
perbaruiLabel();

const kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)');

const terapkanTema = (baru) => {
    html.dataset.theme = baru;
    try {
        localStorage.setItem('tema', baru);
    } catch {
        // penyimpanan diblokir: tema tetap berganti, hanya tidak diingat
    }
    perbaruiLabel();
};

tombolTema?.addEventListener('click', () => {
    const baru = html.dataset.theme === 'dark' ? 'light' : 'dark';

    // Browser yang mendukung View Transitions: tema baru melebar sebagai lingkaran dari tombol
    if (document.startViewTransition && !kurangiGerak.matches) {
        const kotak = tombolTema.getBoundingClientRect();
        const x = kotak.left + kotak.width / 2;
        const y = kotak.top + kotak.height / 2;
        const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));

        html.classList.add('tema-lingkaran');
        const transisi = document.startViewTransition(() => terapkanTema(baru));

        transisi.ready.then(() => {
            html.animate(
                { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
                { duration: 700, easing: 'cubic-bezier(.2, .7, .2, 1)', pseudoElement: '::view-transition-new(root)' },
            );
        }).catch(() => {});
        transisi.finished.catch(() => {}).finally(() => html.classList.remove('tema-lingkaran'));

        return;
    }

    // Cadangan untuk browser lain: warna berubah halus lewat transisi CSS biasa
    html.classList.add('ganti-tema');
    terapkanTema(baru);
    window.setTimeout(() => html.classList.remove('ganti-tema'), 400);
});

// Garis progres: dinyalakan saat form dikirim. Form yang dibatalkan kotak konfirmasi
// sudah ditandai defaultPrevented oleh form itu sendiri sebelum sampai ke sini.
document.addEventListener('submit', (event) => {
    if (!event.defaultPrevented) {
        document.body.classList.add('memuat');
    }
});

// Tombol Kembali di browser memulihkan halaman dari cache; matikan garis progresnya
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        document.body.classList.remove('memuat');
    }
});

// Jam berjalan di kartu selamat datang (beranda). Selalu memakai WIB, apa pun zona waktu perangkat.
const elJam = document.querySelector('[data-jam]');

if (elJam) {
    const elTanggal = document.querySelector('[data-tanggal]');
    const elSalam = document.querySelector('[data-salam]');

    const formatJam = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    });
    const formatTanggal = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    });
    const formatJamSaja = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Jakarta', hour: 'numeric', hourCycle: 'h23',
    });

    const salamUntuk = (jam) => {
        if (jam >= 4 && jam < 11) return 'Selamat pagi';
        if (jam >= 11 && jam < 15) return 'Selamat siang';
        if (jam >= 15 && jam < 18) return 'Selamat sore';
        return 'Selamat malam';
    };

    const perbaruiJam = () => {
        const sekarang = new Date();
        // Locale id-ID memakai titik sebagai pemisah jam; diganti titik dua supaya sama dengan tampilan awal
        elJam.textContent = formatJam.format(sekarang).replace(/\./g, ':');
        if (elTanggal) elTanggal.textContent = formatTanggal.format(sekarang);
        if (elSalam) elSalam.textContent = salamUntuk(parseInt(formatJamSaja.format(sekarang), 10));
    };

    perbaruiJam();
    window.setInterval(perbaruiJam, 1000);
}

// Tombol "Salin" (mis. password sementara di Kelola akun): salin teks elemen yang ditunjuk data-salin
document.querySelectorAll('[data-salin]').forEach((tombol) => {
    const labelAwal = tombol.textContent;

    tombol.addEventListener('click', async () => {
        const target = document.querySelector(tombol.dataset.salin);
        if (!target) return;

        try {
            await navigator.clipboard.writeText(target.textContent.trim());
            tombol.textContent = 'Tersalin';
        } catch {
            // Clipboard diblokir (mis. bukan https): pilih teksnya supaya bisa disalin manual dengan Ctrl+C
            const rentang = document.createRange();
            rentang.selectNodeContents(target);
            const pilihan = window.getSelection();
            pilihan.removeAllRanges();
            pilihan.addRange(rentang);
            tombol.textContent = 'Tekan Ctrl+C';
        }

        window.setTimeout(() => { tombol.textContent = labelAwal; }, 2000);
    });
});

// Header menjadi lebih pekat saat halaman digulir
const pita = document.querySelector('.bar');

if (pita) {
    const perbaruiPita = () => pita.classList.toggle('menggulung', window.scrollY > 8);

    perbaruiPita();
    window.addEventListener('scroll', perbaruiPita, { passive: true });
}

// Toast: hilang sendiri setelah garis waktunya habis (garis berhenti bila kursor di atas toast),
// atau saat tombol tutup diklik
document.querySelectorAll('.toast').forEach((toast) => {
    const lama = toast.classList.contains('peringatan') ? 8000 : 5000;
    let selesai = false;

    toast.style.setProperty('--lama', `${lama}ms`);

    const tutup = () => {
        if (selesai) return;
        selesai = true;

        toast.classList.add('keluar');
        toast.addEventListener('animationend', (event) => {
            // Abaikan animationend dari anak (garis waktu) yang ikut naik ke toast
            if (event.target === toast) toast.remove();
        });
    };

    toast.querySelector('.toast-tutup')?.addEventListener('click', tutup);

    if (kurangiGerak.matches) {
        // Tanpa animasi: garis waktu tidak bisa dipakai sebagai penanda, pakai timer biasa
        window.setTimeout(tutup, lama);
    } else {
        toast.querySelector('.toast-bar')?.addEventListener('animationend', tutup);
    }
});