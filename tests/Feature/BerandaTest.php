<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\KeuRekeningBankSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BerandaTest extends TestCase
{
    private User $pengguna;

    protected function setUp(): void
    {
        parent::setUp();

        // Sama dengan tes lain: koneksi dashi_keu dialihkan ke SQLite memori
        config(['database.connections.dashi_keu' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('dashi_keu');

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(KeuRekeningBankSeeder::class);
        $this->withoutVite();

        $this->pengguna = new User();
        $this->pengguna->forceFill([
            'name' => 'Petugas Uji',
            'email' => 'uji@example.test',
            'password' => 'Rahasia12345',
            'role' => User::ROLE_KEUANGAN,
            'status' => User::STATUS_AKTIF,
            'wajib_ganti_password' => false,
        ])->save();
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('beranda'))->assertRedirect(route('login'));
    }

    public function test_beranda_menampilkan_status_kerja_tanpa_data(): void
    {
        $this->actingAs($this->pengguna)
            ->get(route('beranda'))
            ->assertOk()
            ->assertSee('Perlu tindakan')
            ->assertSee('Tidak ada yang menunggu tindakan.')
            ->assertSee('Data final per rekening')
            ->assertSee('BNI-001')
            ->assertSee('Belum ada data');
    }
}