<?php

namespace App\Services\Keuangan;

use RuntimeException;

/**
 * Keputusan pindah dana ditolak karena aturan (bukan galat sistem). Pesannya aman ditampilkan ke pengguna.
 */
final class KeputusanDitolak extends RuntimeException
{
}