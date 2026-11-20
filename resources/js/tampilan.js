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

tombolTema?.addEventListener('click', () => {
    const baru = html.dataset.theme === 'dark' ? 'light' : 'dark';

    // Kelas ini hanya menyalakan transisi warna selama pergantian tema
    html.classList.add('ganti-tema');
    html.dataset.theme = baru;
    try {
        localStorage.setItem('tema', baru);
    } catch {
        // penyimpanan diblokir: tema tetap berganti, hanya tidak diingat
    }
    perbaruiLabel();

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