@props(['status'])

@php
    // Label dan warna untuk status batch, status baris, dan hasil rekonsiliasi
    $peta = [
        'diproses'            => ['Diproses', 'peringatan'],
        'menunggu_verifikasi' => ['Menunggu verifikasi', 'peringatan'],
        'disimpan'            => ['Disimpan', 'ok'],
        'ditolak'             => ['Ditolak', 'bahaya'],
        'gagal'               => ['Gagal', 'bahaya'],
        'valid'               => ['Valid', 'ok'],
        'peringatan'          => ['Peringatan', 'peringatan'],
        'tidak_valid'         => ['Tidak valid', 'bahaya'],
        'duplikat'            => ['Duplikat', 'netral'],
        'cocok'               => ['Cocok', 'ok'],
        'selisih'             => ['Selisih', 'bahaya'],
        'needs_review'        => ['Menunggu konfirmasi', 'peringatan'],
        'matched'             => ['Terkonfirmasi', 'ok'],
        'rejected'            => ['Bukan pindah dana', 'netral'],
    ];
    [$label, $nada] = $peta[$status] ?? [$status, 'netral'];
@endphp

<span class="lencana {{ $nada }}">{{ $label }}</span>