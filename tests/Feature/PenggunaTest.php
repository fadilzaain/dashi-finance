<?php

namespace Tests\Feature;

use App\Models\KeuAuditLog;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PenggunaTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Sama dengan tes lain: koneksi dashi_keu (tempat audit log) dialihkan ke SQLite memori
        config(['database.connections.dashi_keu' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('dashi_keu');

        $this->artisan('migrate', ['--force' => true]);
        $this->withoutVite();

        $this->admin = $this->buatUser('admin@example.test', User::ROLE_ADMIN, 'Admin Uji');
    }

    // ------------------------------------------------------------------ bahan uji

    private function buatUser(string $email, string $role, string $nama = 'Petugas Uji', string $status = User::STATUS_AKTIF): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $nama,
            'email' => $email,
            'password' => 'Rahasia12345',
            'role' => $role,
            'status' => $status,
            'wajib_ganti_password' => false,
        ])->save();

        return $user;
    }

    private function auditAda(string $aktivitas, User $objek): bool
    {
        return KeuAuditLog::where('aktivitas', $aktivitas)
            ->where('objek_tipe', 'pengguna')
            ->where('objek_id', $objek->id)
            ->exists();
    }

    // ------------------------------------------------------------------ akses

    public function test_petugas_keuangan_tidak_boleh_membuka_kelola_akun(): void
    {
        $petugas = $this->buatUser('keu@example.test', User::ROLE_KEUANGAN);

        $this->actingAs($petugas)->get(route('pengguna.index'))->assertForbidden();
        $this->actingAs($petugas)->post(route('pengguna.simpan'), [
            'name' => 'X', 'email' => 'x@example.test', 'role' => User::ROLE_ADMIN,
        ])->assertForbidden();

        $this->assertFalse(User::where('email', 'x@example.test')->exists());
    }

    public function test_admin_bisa_membuka_daftar_akun(): void
    {
        $this->actingAs($this->admin)->get(route('pengguna.index'))
            ->assertOk()
            ->assertSee('Kelola akun')
            ->assertSee('admin@example.test');
    }

    public function test_password_sementara_tampil_di_halaman_dari_kiriman_flash(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['sandi_sementara' => ['email' => 'baru@example.test', 'sandi' => Crypt::encryptString('Abcdefg23456')]])
            ->get(route('pengguna.index'))
            ->assertOk()
            ->assertSee('Abcdefg23456')
            ->assertSee('baru@example.test');
    }

    // ------------------------------------------------------------------ tambah akun

    public function test_admin_membuat_akun_dengan_password_sementara(): void
    {
        $respons = $this->actingAs($this->admin)->post(route('pengguna.simpan'), [
            'name' => 'Budi Santoso',
            'email' => 'Budi@Example.test',
            'role' => User::ROLE_KEUANGAN,
        ]);

        $respons->assertRedirect(route('pengguna.index'));
        $respons->assertSessionHas('sandi_sementara.email', 'budi@example.test');

        $baru = User::where('email', 'budi@example.test')->firstOrFail();
        $this->assertSame('Budi Santoso', $baru->name);
        $this->assertSame(User::ROLE_KEUANGAN, $baru->role);
        $this->assertSame(User::STATUS_AKTIF, $baru->status);
        $this->assertTrue($baru->wajib_ganti_password);

        // Password yang tampil di layar cocok dengan yang tersimpan (ter-hash) dan memenuhi aturan
        $sandi = Crypt::decryptString(session('sandi_sementara.sandi'));
        $this->assertSame(12, strlen($sandi));
        $this->assertTrue(Hash::check($sandi, $baru->password));
        $this->assertMatchesRegularExpression('/[A-Z]/', $sandi);
        $this->assertMatchesRegularExpression('/[a-z]/', $sandi);
        $this->assertMatchesRegularExpression('/[0-9]/', $sandi);

        $this->assertTrue($this->auditAda(AuditLog::PENGGUNA_DIBUAT, $baru));
    }

    public function test_email_yang_sudah_terdaftar_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('pengguna.simpan'), [
            'name' => 'Ganda',
            'email' => 'ADMIN@example.test',
            'role' => User::ROLE_KEUANGAN,
        ])->assertSessionHasErrorsIn('tambah', 'email');

        $this->assertSame(1, User::where('email', 'admin@example.test')->count());
    }

    public function test_peran_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('pengguna.simpan'), [
            'name' => 'Peran Aneh',
            'email' => 'aneh@example.test',
            'role' => 'superadmin',
        ])->assertSessionHasErrorsIn('tambah', 'role');

        $this->assertFalse(User::where('email', 'aneh@example.test')->exists());
    }

    // ------------------------------------------------------------------ ubah peran

    public function test_admin_mengubah_peran_akun_lain(): void
    {
        $petugas = $this->buatUser('keu@example.test', User::ROLE_KEUANGAN);

        $this->actingAs($this->admin)
            ->put(route('pengguna.peran', $petugas->id), ['role' => User::ROLE_ADMIN])
            ->assertRedirect(route('pengguna.index'));

        $this->assertSame(User::ROLE_ADMIN, $petugas->fresh()->role);
        $this->assertTrue($this->auditAda(AuditLog::PENGGUNA_DIUBAH_PERAN, $petugas));
    }

    public function test_admin_tidak_bisa_mengubah_peran_sendiri(): void
    {
        $this->actingAs($this->admin)
            ->put(route('pengguna.peran', $this->admin->id), ['role' => User::ROLE_KEUANGAN])
            ->assertSessionHasErrors('aksi');

        $this->assertSame(User::ROLE_ADMIN, $this->admin->fresh()->role);
    }

    // ------------------------------------------------------------------ aktif / nonaktif

    public function test_admin_menonaktifkan_lalu_mengaktifkan_akun_lain(): void
    {
        $petugas = $this->buatUser('keu@example.test', User::ROLE_KEUANGAN);

        $this->actingAs($this->admin)
            ->post(route('pengguna.status', $petugas->id), ['aksi' => 'nonaktifkan'])
            ->assertRedirect(route('pengguna.index'));
        $this->assertSame(User::STATUS_NONAKTIF, $petugas->fresh()->status);
        $this->assertTrue($this->auditAda(AuditLog::PENGGUNA_DINONAKTIFKAN, $petugas));

        $this->actingAs($this->admin)
            ->post(route('pengguna.status', $petugas->id), ['aksi' => 'aktifkan']);
        $this->assertSame(User::STATUS_AKTIF, $petugas->fresh()->status);
        $this->assertTrue($this->auditAda(AuditLog::PENGGUNA_DIAKTIFKAN, $petugas));
    }

    public function test_admin_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pengguna.status', $this->admin->id), ['aksi' => 'nonaktifkan'])
            ->assertSessionHasErrors('aksi');

        $this->assertSame(User::STATUS_AKTIF, $this->admin->fresh()->status);
    }

    public function test_aksi_status_yang_tidak_dikenal_ditolak(): void
    {
        $petugas = $this->buatUser('keu@example.test', User::ROLE_KEUANGAN);

        $this->actingAs($this->admin)
            ->post(route('pengguna.status', $petugas->id), ['aksi' => 'hapus'])
            ->assertSessionHasErrors('aksi');

        $this->assertSame(User::STATUS_AKTIF, $petugas->fresh()->status);
    }

    // ------------------------------------------------------------------ reset password

    public function test_admin_mereset_password_akun_lain(): void
    {
        $petugas = $this->buatUser('keu@example.test', User::ROLE_KEUANGAN);
        $hashLama = $petugas->password;

        $respons = $this->actingAs($this->admin)->post(route('pengguna.reset', $petugas->id));
        $respons->assertRedirect(route('pengguna.index'));
        $respons->assertSessionHas('sandi_sementara.email', 'keu@example.test');

        $petugas->refresh();
        $this->assertNotSame($hashLama, $petugas->password);
        $this->assertTrue($petugas->wajib_ganti_password);

        $sandi = Crypt::decryptString(session('sandi_sementara.sandi'));
        $this->assertTrue(Hash::check($sandi, $petugas->password));
        $this->assertTrue($this->auditAda(AuditLog::PASSWORD_DIRESET, $petugas));
    }

    public function test_admin_tidak_bisa_mereset_password_sendiri_lewat_halaman_ini(): void
    {
        $hashLama = $this->admin->password;

        $this->actingAs($this->admin)
            ->post(route('pengguna.reset', $this->admin->id))
            ->assertSessionHasErrors('aksi');

        $this->assertSame($hashLama, $this->admin->fresh()->password);
    }
}