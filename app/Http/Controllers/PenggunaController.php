<?php

namespace App\Http\Controllers;

use App\Models\KeuAuditLog;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Kelola akun (khusus admin): tambah akun, ubah peran, aktif/nonaktif, reset password.
 * Setiap tindakan dicatat ke audit log. Admin tidak bisa mengubah akunnya sendiri lewat halaman ini
 * (supaya tidak mengunci dirinya sendiri); ganti password sendiri lewat menu Ganti password.
 */
class PenggunaController extends Controller
{
    public function index()
    {
        $daftar = User::orderBy('name')->get();

        // Login terakhir diambil dari audit log (satu query untuk semua akun). Kalau audit log
        // sedang bermasalah, halaman tetap tampil tanpa kolom itu.
        try {
            $loginTerakhir = KeuAuditLog::query()
                ->where('aktivitas', AuditLog::LOGIN)
                ->whereIn('user_id', $daftar->pluck('id'))
                ->selectRaw('user_id, MAX(created_at) as terakhir')
                ->groupBy('user_id')
                ->pluck('terakhir', 'user_id');
        } catch (Throwable $e) {
            report($e);
            $loginTerakhir = collect();
        }

        // Password sementara dari tindakan sebelumnya: tampil sekali saja (flash), disimpan terenkripsi
        $sandi = null;
        $kiriman = session('sandi_sementara');

        if (is_array($kiriman) && isset($kiriman['email'], $kiriman['sandi'])) {
            try {
                $sandi = ['email' => $kiriman['email'], 'password' => Crypt::decryptString($kiriman['sandi'])];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return view('pengguna.index', [
            'daftar' => $daftar,
            'loginTerakhir' => $loginTerakhir,
            'sandi' => $sandi,
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        // Email dirapikan dulu supaya "Budi@X.com" dan "budi@x.com" dianggap sama
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validateWithBag('tambah', [
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role'  => ['required', Rule::in(array_keys(User::LABEL_ROLE))],
        ], [
            'name.required'  => 'Nama wajib diisi.',
            'name.max'       => 'Nama maksimal 100 karakter.',
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
            'email.unique'   => 'Email itu sudah terdaftar.',
            'role.required'  => 'Peran wajib dipilih.',
            'role.in'        => 'Peran tidak dikenal.',
        ]);

        $sandi = $this->buatPasswordSementara();

        // Cast "hashed" di model yang meng-hash password-nya
        $baru = User::create([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'password' => $sandi,
            'role' => $data['role'],
            'status' => User::STATUS_AKTIF,
            'wajib_ganti_password' => true,
        ]);

        AuditLog::catatAtauLapor(AuditLog::PENGGUNA_DIBUAT, $baru, null, ['role' => $baru->role], 'Dibuat lewat halaman Kelola akun');

        return redirect()->route('pengguna.index')
            ->with('status', "Akun {$baru->email} dibuat. Berikan password sementara di bawah ke yang bersangkutan.")
            ->with('sandi_sementara', ['email' => $baru->email, 'sandi' => Crypt::encryptString($sandi)]);
    }

    public function ubahPeran(Request $request, User $pengguna): RedirectResponse
    {
        if ($pengguna->is($request->user())) {
            return $this->tolak('Kamu tidak bisa mengubah peranmu sendiri.');
        }

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(User::LABEL_ROLE))],
        ], [
            'role.required' => 'Peran wajib dipilih.',
            'role.in'       => 'Peran tidak dikenal.',
        ]);

        if ($pengguna->role === $data['role']) {
            return redirect()->route('pengguna.index')->with('status', 'Peran tidak berubah.');
        }

        $lama = $pengguna->role;
        $pengguna->forceFill(['role' => $data['role']])->save();

        AuditLog::catatAtauLapor(
            AuditLog::PENGGUNA_DIUBAH_PERAN, $pengguna, ['role' => $lama], ['role' => $data['role']], 'Diubah lewat halaman Kelola akun'
        );

        return redirect()->route('pengguna.index')
            ->with('status', "Peran {$pengguna->name} diubah menjadi ".(User::LABEL_ROLE[$data['role']] ?? $data['role']).'.');
    }

    public function ubahStatus(Request $request, User $pengguna): RedirectResponse
    {
        if ($pengguna->is($request->user())) {
            return $this->tolak('Kamu tidak bisa menonaktifkan akunmu sendiri.');
        }

        $data = $request->validate([
            'aksi' => ['required', Rule::in(['aktifkan', 'nonaktifkan'])],
        ]);

        $aktifkan = $data['aksi'] === 'aktifkan';
        $statusBaru = $aktifkan ? User::STATUS_AKTIF : User::STATUS_NONAKTIF;

        if ($pengguna->status === $statusBaru) {
            return redirect()->route('pengguna.index')->with('status', 'Status akun tidak berubah.');
        }

        $statusLama = $pengguna->status;
        $pengguna->forceFill(['status' => $statusBaru])->save();

        // Akun yang baru dinonaktifkan langsung dikeluarkan dari semua sesinya
        if (! $aktifkan) {
            $this->akhiriSesi($pengguna);
        }

        AuditLog::catatAtauLapor(
            $aktifkan ? AuditLog::PENGGUNA_DIAKTIFKAN : AuditLog::PENGGUNA_DINONAKTIFKAN,
            $pengguna, ['status' => $statusLama], ['status' => $statusBaru], 'Diubah lewat halaman Kelola akun'
        );

        return redirect()->route('pengguna.index')
            ->with('status', $aktifkan ? "Akun {$pengguna->name} diaktifkan." : "Akun {$pengguna->name} dinonaktifkan.");
    }

    public function resetPassword(Request $request, User $pengguna): RedirectResponse
    {
        if ($pengguna->is($request->user())) {
            return $this->tolak('Untuk akunmu sendiri, pakai menu Ganti password.');
        }

        $sandi = $this->buatPasswordSementara();

        // Cast "hashed" di model yang meng-hash password-nya
        $pengguna->forceFill([
            'password' => $sandi,
            'wajib_ganti_password' => true,
        ])->save();

        // Password lama sudah tidak berlaku, jadi sesi yang masih berjalan ikut diakhiri
        $this->akhiriSesi($pengguna);

        AuditLog::catatAtauLapor(AuditLog::PASSWORD_DIRESET, $pengguna, null, null, 'Di-reset lewat halaman Kelola akun');

        return redirect()->route('pengguna.index')
            ->with('status', "Password {$pengguna->email} di-reset. Berikan password sementara di bawah ke yang bersangkutan.")
            ->with('sandi_sementara', ['email' => $pengguna->email, 'sandi' => Crypt::encryptString($sandi)]);
    }

    // ------------------------------------------------------------------ bantuan

    private function tolak(string $pesan): RedirectResponse
    {
        return redirect()->route('pengguna.index')->withErrors(['aksi' => $pesan]);
    }

    /**
     * Password sementara 12 karakter: pasti memuat huruf besar, huruf kecil, dan angka (sesuai
     * Password::defaults()). Karakter yang mudah tertukar (I, l, O, 0, 1) sengaja dibuang
     * supaya mudah dibaca dan diketik.
     */
    private function buatPasswordSementara(): string
    {
        $besar = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $kecil = 'abcdefghijkmnopqrstuvwxyz';
        $angka = '23456789';
        $semua = $besar.$kecil.$angka;

        $acak = fn (string $himpunan): string => $himpunan[random_int(0, strlen($himpunan) - 1)];

        $hasil = [$acak($besar), $acak($kecil), $acak($angka)];

        for ($i = 0; $i < 9; $i++) {
            $hasil[] = $acak($semua);
        }

        // Kocok urutan supaya tiga karakter wajib tadi tidak selalu di depan
        for ($i = count($hasil) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$hasil[$i], $hasil[$j]] = [$hasil[$j], $hasil[$i]];
        }

        return implode('', $hasil);
    }

    /** Hapus semua sesi login milik akun ini (hanya bila sesi disimpan di database). */
    private function akhiriSesi(User $pengguna): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $pengguna->id)
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}