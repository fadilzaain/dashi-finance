<?php

namespace App\Services;

use App\Models\KeuAuditLog;
use App\Models\KeuImporBatch;
use App\Models\KeuTransferInternal;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat audit log. Satu pintu untuk semua aktivitas penting.
 *
 * Dua cara pakai:
 *  - catat()          : strict, galat dilempar. Dipakai di dalam transaksi (mis. Simpan batch),
 *                       supaya data final tidak pernah berubah tanpa jejak.
 *  - catatAtauLapor() : galat hanya dilaporkan ke log. Dipakai untuk aktivitas biasa (login, unggah),
 *                       supaya gangguan audit tidak ikut mengunci pengguna.
 *
 * Yang dicatat: siapa, apa, kapan, objek apa, nilai sebelum/sesudah. Isi transaksi (deskripsi bisa
 * memuat nama pasien) dan password TIDAK pernah dimasukkan.
 */
final class AuditLog
{
    public const LOGIN = 'login';
    public const LOGIN_GAGAL = 'login_gagal';
    public const LOGIN_TERKUNCI = 'login_terkunci';
    public const LOGOUT = 'logout';
    public const GANTI_PASSWORD = 'ganti_password';
    public const UNGGAH = 'unggah';
    public const UNGGAH_DITOLAK_SAMA = 'unggah_ditolak_sama';
    public const SIMPAN_BATCH = 'simpan_batch';
    public const BATALKAN_BATCH = 'batalkan_batch';
    public const PENGGUNA_DIBUAT = 'pengguna_dibuat';
    public const PASSWORD_DIRESET = 'password_direset';
    public const CARI_TRANSFER = 'cari_transfer_internal';
    public const KONFIRMASI_TRANSFER = 'konfirmasi_transfer_internal';
    public const TOLAK_TRANSFER = 'tolak_transfer_internal';
    public const KEMBALIKAN_TRANSFER = 'kembalikan_transfer_internal';

    // Label untuk tampilan dan filter halaman audit
    public const LABEL = [
        self::LOGIN => 'Login',
        self::LOGIN_GAGAL => 'Login gagal',
        self::LOGIN_TERKUNCI => 'Login terkunci',
        self::LOGOUT => 'Logout',
        self::GANTI_PASSWORD => 'Ganti password',
        self::UNGGAH => 'Unggah file',
        self::UNGGAH_DITOLAK_SAMA => 'Unggah file sama persis',
        self::SIMPAN_BATCH => 'Simpan batch',
        self::BATALKAN_BATCH => 'Batalkan batch',
        self::PENGGUNA_DIBUAT => 'Pengguna dibuat',
        self::PASSWORD_DIRESET => 'Password di-reset',
        self::CARI_TRANSFER => 'Cari usulan pindah dana',
        self::KONFIRMASI_TRANSFER => 'Konfirmasi pindah dana internal',
        self::TOLAK_TRANSFER => 'Tolak: bukan pindah dana',
        self::KEMBALIKAN_TRANSFER => 'Kembalikan ke menunggu konfirmasi',
    ];

    /**
     * @param  Authenticatable|null  $pengguna  pelaku; kosong = pengguna yang sedang login (bila ada)
     */
    public static function catat(
        string $aktivitas,
        ?Model $objek = null,
        ?array $sebelum = null,
        ?array $sesudah = null,
        ?string $keterangan = null,
        ?Authenticatable $pengguna = null,
    ): KeuAuditLog {
        $pengguna ??= Auth::user();
        $request = request();

        return KeuAuditLog::create([
            'user_id' => $pengguna?->getAuthIdentifier(),
            'user_nama' => self::namaPelaku($pengguna),
            'aktivitas' => $aktivitas,
            'objek_tipe' => $objek !== null ? self::tipeObjek($objek) : null,
            'objek_id' => $objek?->getKey(),
            'keterangan' => self::bersihkan($keterangan, 500),
            'data_sebelum' => $sebelum,
            'data_sesudah' => $sesudah,
            'ip' => $request->ip(),
            'user_agent' => self::bersihkan($request->userAgent(), 255),
        ]);
    }

    /** Sama seperti catat(), tetapi galatnya hanya dilaporkan. Argumen sama (boleh bernama). */
    public static function catatAtauLapor(string $aktivitas, mixed ...$argumen): ?KeuAuditLog
    {
        try {
            return self::catat($aktivitas, ...$argumen);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function namaPelaku(?Authenticatable $pengguna): ?string
    {
        if ($pengguna !== null) {
            return self::bersihkan((string) data_get($pengguna, 'name'), 100);
        }

        // Perintah artisan di server: catat akun sistem operasi yang menjalankannya
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::bersihkan('Server: '.(getenv('USERNAME') ?: getenv('USER') ?: get_current_user()), 100);
        }

        return null;
    }

    private static function tipeObjek(Model $objek): string
    {
        return match (true) {
            $objek instanceof KeuImporBatch => 'batch',
            $objek instanceof User => 'pengguna',
            $objek instanceof KeuTransferInternal => 'transfer_internal',
            default => Str::snake(class_basename($objek)),
        };
    }

    // Teks dari luar (email yang diketik, user agent) dipotong dan dibuang karakter kontrolnya,
    // supaya tidak bisa memalsukan baris baru di log atau merusak penyimpanan UTF-8.
    private static function bersihkan(?string $teks, int $maks): ?string
    {
        if ($teks === null || $teks === '') {
            return null;
        }

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($teks)) ?? '', 0, $maks);
    }
}