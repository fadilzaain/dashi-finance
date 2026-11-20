// Halaman masuk: cahaya yang mengikuti kursor, tampil/sembunyi password,
// peringatan Caps Lock, dan keadaan memuat di tombol Masuk.
const kartu = document.querySelector('.masuk-kartu');

if (kartu) {
    // Titik cahaya di kartu mengikuti kursor (posisinya dikirim ke CSS lewat variabel --mx dan --my)
    kartu.addEventListener('pointermove', (event) => {
        const kotak = kartu.getBoundingClientRect();
        kartu.style.setProperty('--mx', `${event.clientX - kotak.left}px`);
        kartu.style.setProperty('--my', `${event.clientY - kotak.top}px`);
    });

    // Tombol mata: tampilkan atau sembunyikan password
    document.querySelectorAll('[data-lihat-sandi]').forEach((tombol) => {
        const kolom = document.querySelector(tombol.dataset.lihatSandi);
        if (!kolom) return;

        tombol.addEventListener('click', () => {
            const tampil = kolom.type === 'password';

            kolom.type = tampil ? 'text' : 'password';
            tombol.classList.toggle('tampil', tampil);
            tombol.setAttribute('aria-pressed', String(tampil));
            tombol.setAttribute('aria-label', tampil ? 'Sembunyikan password' : 'Tampilkan password');
            kolom.focus();
        });
    });

    // Peringatan Caps Lock saat mengetik password
    const kolomSandi = document.getElementById('password');
    const penanda = document.querySelector('.fl-caps');

    if (kolomSandi && penanda) {
        const periksa = (event) => {
            if (typeof event.getModifierState === 'function') {
                penanda.hidden = !event.getModifierState('CapsLock');
            }
        };

        kolomSandi.addEventListener('keydown', periksa);
        kolomSandi.addEventListener('keyup', periksa);
        kolomSandi.addEventListener('blur', () => { penanda.hidden = true; });
    }

    // Tombol Masuk menampilkan spinner saat form dikirim, dan klik/Enter kedua diabaikan
    const form = document.getElementById('form-masuk');
    const tombolMasuk = form?.querySelector('.tombol-masuk');
    let terkirim = false;

    form?.addEventListener('submit', (event) => {
        if (terkirim) {
            event.preventDefault();
            return;
        }

        terkirim = true;
        tombolMasuk?.classList.add('memuat-tombol');
        tombolMasuk?.setAttribute('aria-busy', 'true');
    });

    // Tombol Kembali di browser memulihkan halaman dari cache; kembalikan tombolnya
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            terkirim = false;
            tombolMasuk?.classList.remove('memuat-tombol');
            tombolMasuk?.removeAttribute('aria-busy');
        }
    });
}