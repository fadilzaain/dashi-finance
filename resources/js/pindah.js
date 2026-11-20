// Halaman pindah dana: tombol tidak boleh terkirim dua kali (klik ganda
// akan membuat klik kedua ditolak server dengan pesan "sudah diputuskan").
document.querySelectorAll('form[data-sekali]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        // Dibatalkan oleh kotak konfirmasi (di unggah.js): jangan kunci tombolnya
        if (event.defaultPrevented) {
            return;
        }

        form.querySelectorAll('button[type="submit"]').forEach((tombol) => {
            tombol.disabled = true;
        });
    });
});

// Tombol Kembali di browser memulihkan halaman dari cache; buka kunci tombolnya
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        document.querySelectorAll('form[data-sekali] button[type="submit"]').forEach((tombol) => {
            tombol.disabled = false;
        });
    }
});