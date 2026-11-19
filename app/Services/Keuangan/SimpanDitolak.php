<?php

namespace App\Services\Keuangan;

use RuntimeException;

/**
 * Penyimpanan batch ditolak karena aturan (bukan galat sistem). Pesannya aman ditampilkan ke pengguna.
 */
final class SimpanDitolak extends RuntimeException
{
}