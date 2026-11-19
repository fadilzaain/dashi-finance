// Halaman unggah mutasi: tampilkan nama dan ukuran file yang dipilih,
// dan cegah klik ganda saat file sedang diunggah.
const form = document.getElementById('form-unggah');

if (form) {
    const BATAS_BYTE = 10 * 1024 * 1024;

    const berkas = form.querySelector('input[type="file"]');
    const info = document.getElementById('info-berkas');
    const tombol = document.getElementById('tombol-unggah');
    const labelAwal = tombol.textContent;

    const formatUkuran = (byte) => (byte >= 1024 * 1024
        ? `${(byte / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(byte / 1024))} KB`);

    berkas.addEventListener('change', () => {
        const file = berkas.files[0];

        if (!file) {
            info.textContent = '';
            tombol.disabled = false;
            return;
        }

        if (file.size > BATAS_BYTE) {
            info.textContent = `${file.name} · ${formatUkuran(file.size)}: melebihi batas 10 MB.`;
            tombol.disabled = true;
            return;
        }

        info.textContent = `${file.name} · ${formatUkuran(file.size)}`;
        tombol.disabled = false;
    });

    form.addEventListener('submit', () => {
        tombol.disabled = true;
        tombol.textContent = 'Memproses…';
    });

    // Tombol Kembali di browser memulihkan halaman dari cache; kembalikan tombol seperti semula
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            tombol.disabled = false;
            tombol.textContent = labelAwal;
        }
    });
    
}

// Halaman hasil: Simpan tidak boleh terkirim dua kali, dan Batalkan minta konfirmasi dulu
document.querySelectorAll('form[data-konfirmasi]').forEach((formBatal) => {
    formBatal.addEventListener('submit', (event) => {
        if (!window.confirm(formBatal.dataset.konfirmasi)) {
            event.preventDefault();
        }
    });
});

const formSimpan = document.getElementById('form-simpan');

if (formSimpan) {
    const tombolSimpan = formSimpan.querySelector('button[type="submit"]');
    const labelSimpan = tombolSimpan.textContent;

    formSimpan.addEventListener('submit', () => {
        tombolSimpan.disabled = true;
        tombolSimpan.textContent = 'Menyimpan…';
    });

    // Tombol Kembali di browser memulihkan halaman dari cache; kembalikan tombolnya
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            tombolSimpan.disabled = false;
            tombolSimpan.textContent = labelSimpan;
        }
    });
}