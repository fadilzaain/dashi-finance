{{-- Tombol tema berupa ikon: matahari di mode terang, bulan di mode gelap. Perilakunya diurus tampilan.js lewat id ganti-tema. --}}
<button type="button" id="ganti-tema" {{ $attributes->class(['ikon-tombol']) }} aria-label="Ganti ke mode gelap" title="Ganti ke mode gelap">
    <svg class="ikon-matahari" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="4"/>
        <path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>
    </svg>
    <svg class="ikon-bulan" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
</button>