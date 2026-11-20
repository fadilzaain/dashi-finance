<?php

namespace Tests\Feature;

use App\Models\KeuAuditLog;
use App\Models\KeuImporBaris;
use App\Models\KeuImporBatch;
use App\Models\KeuMutasiBank;
use App\Models\KeuRekeningBank;
use App\Models\KeuTransferInternal;
use App\Models\User;
use App\Services\AuditLog;
use Database\Seeders\KeuRekeningBankSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PindahDanaTest extends TestCase
{
    private User $pengguna;
    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Sama dengan UnggahMutasiTest: koneksi dashi_keu dialihkan ke SQLite memori
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

    // ------------------------------------------------------------------ bahan uji

    /** Satu mutasi final lengkap dengan batch dan baris sumbernya (syarat foreign key). */
    private function mutasi(string $kodeRekening, string $arah, string $nominal, string $waktu): KeuMutasiBank
    {
        $this->urut++;
        $rekening = KeuRekeningBank::where('kode', $kodeRekening)->firstOrFail();

        $batch = KeuImporBatch::create([
            'rekening_id' => $rekening->id,
            'nama_file_asli' => "uji{$this->urut}.csv",
            'path_file' => "uji{$this->urut}.csv",
            'format_file' => 'csv',
            'ukuran_byte' => 1,
            'hash_sha256' => hash('sha256', "uji{$this->urut}"),
            'diunggah_oleh' => $this->pengguna->id,
        ]);
        $baris = KeuImporBaris::create([
            'batch_id' => $batch->id,
            'nomor_baris' => 1,
            'data_mentah' => [],
            'status' => KeuImporBaris::STATUS_VALID,
        ]);

        return KeuMutasiBank::create([
            'rekening_id' => $rekening->id,
            'batch_id' => $batch->id,
            'baris_id' => $baris->id,
            'kunci_unik' => hash('sha256', "kunci{$this->urut}"),
            'tanggal_transaksi' => $waktu,
            'arah' => $arah,
            'nominal' => $nominal,
        ]);
    }

    /** Debit di BNI lalu 20 menit kemudian kredit di BPD, nominal sama. */
    private function pindahDana(string $nominal, string $tanggal): void
    {
        $this->mutasi('BNI-001', 'debit', $nominal, "{$tanggal} 10:00:00");
        $this->mutasi('BPD-001', 'kredit', $nominal, "{$tanggal} 10:20:00");
    }

    // ------------------------------------------------------------------ tes

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('pindah.index'))->assertRedirect(route('login'));
        $this->post(route('pindah.cari'))->assertRedirect(route('login'));
    }

    public function test_cari_membuat_usulan_menunggu_dan_aman_diulang(): void
    {
        $this->pindahDana('100000.00', '2026-09-17');
        $this->mutasi('BNI-001', 'debit', '5000.00', '2026-09-17 11:00:00'); // tanpa lawan

        $this->actingAs($this->pengguna);
        $this->post(route('pindah.cari'))->assertRedirect(route('pindah.index'));

        $this->assertSame(1, KeuTransferInternal::count());
        $usulan = KeuTransferInternal::firstOrFail();
        $this->assertSame(KeuTransferInternal::STATUS_MENUNGGU, $usulan->status);
        $this->assertSame('pasti', $usulan->jenis);
        $this->assertSame(1200, $usulan->selisih_detik);

        $this->post(route('pindah.cari'));
        $this->assertSame(1, KeuTransferInternal::count());
        $this->assertSame(2, KeuAuditLog::where('aktivitas', AuditLog::CARI_TRANSFER)->count());
    }

    public function test_konfirmasi_klik_kedua_ditolak_dan_bisa_dikembalikan(): void
    {
        $this->pindahDana('100000.00', '2026-09-17');
        $this->actingAs($this->pengguna);
        $this->post(route('pindah.cari'));
        $usulan = KeuTransferInternal::firstOrFail();

        $this->post(route('pindah.setujui', $usulan->id))->assertSessionHasNoErrors();
        $usulan->refresh();
        $this->assertSame(KeuTransferInternal::STATUS_TERKONFIRMASI, $usulan->status);
        $this->assertSame($this->pengguna->id, (int) $usulan->diputuskan_oleh);
        $this->assertNotNull($usulan->diputuskan_pada);

        // Sudah diputuskan: klik kedua ditolak
        $this->post(route('pindah.setujui', $usulan->id))->assertSessionHasErrors('aksi');

        $this->post(route('pindah.kembalikan', $usulan->id))->assertSessionHasNoErrors();
        $usulan->refresh();
        $this->assertSame(KeuTransferInternal::STATUS_MENUNGGU, $usulan->status);
        $this->assertNull($usulan->diputuskan_oleh);

        $this->assertSame(1, KeuAuditLog::where('aktivitas', AuditLog::KONFIRMASI_TRANSFER)->count());
        $this->assertSame(1, KeuAuditLog::where('aktivitas', AuditLog::KEMBALIKAN_TRANSFER)->count());
    }

    public function test_yang_ditolak_tidak_diusulkan_ulang(): void
    {
        $this->pindahDana('100000.00', '2026-09-17');
        $this->actingAs($this->pengguna);
        $this->post(route('pindah.cari'));
        $usulan = KeuTransferInternal::firstOrFail();

        $this->post(route('pindah.tolak', $usulan->id))->assertSessionHasNoErrors();
        $this->assertSame(KeuTransferInternal::STATUS_DITOLAK, $usulan->fresh()->status);

        $this->post(route('pindah.cari'));
        $this->assertSame(1, KeuTransferInternal::count());
        $this->assertSame(KeuTransferInternal::STATUS_DITOLAK, KeuTransferInternal::firstOrFail()->status);
    }

    public function test_pasangan_nominal_beda_tidak_bisa_dikonfirmasi(): void
    {
        $debit = $this->mutasi('BNI-001', 'debit', '100000.00', '2026-09-17 10:00:00');
        $kredit = $this->mutasi('BPD-001', 'kredit', '90000.00', '2026-09-17 10:20:00');
        $usulan = KeuTransferInternal::create([
            'debit_mutasi_id' => $debit->id,
            'kredit_mutasi_id' => $kredit->id,
            'jenis' => 'pasti',
            'selisih_detik' => 1200,
            'dibuat_oleh' => $this->pengguna->id,
        ]);

        $this->actingAs($this->pengguna)
            ->post(route('pindah.setujui', $usulan->id))
            ->assertSessionHasErrors('aksi');

        $this->assertSame(KeuTransferInternal::STATUS_MENUNGGU, $usulan->fresh()->status);
    }

    public function test_konfirmasi_massal_menolak_bila_daftar_berubah_dan_jalan_bila_sama(): void
    {
        $this->pindahDana('100000.00', '2026-09-17');   // 10.000.000 sen
        $this->pindahDana('250000.00', '2026-09-18');   // 25.000.000 sen
        $this->actingAs($this->pengguna);
        $this->post(route('pindah.cari'));
        $this->assertSame(2, KeuTransferInternal::count());

        // Angka yang dilihat petugas tidak cocok dengan keadaan sekarang: tidak ada yang dikonfirmasi
        $this->post(route('pindah.massal'), ['jumlah' => 2, 'total_sen' => 1])->assertSessionHasErrors('aksi');
        $this->assertSame(0, KeuTransferInternal::where('status', KeuTransferInternal::STATUS_TERKONFIRMASI)->count());

        $this->post(route('pindah.massal'), ['jumlah' => 2, 'total_sen' => 35000000])->assertSessionHasNoErrors();
        $this->assertSame(2, KeuTransferInternal::where('status', KeuTransferInternal::STATUS_TERKONFIRMASI)->count());
        $this->assertSame(2, KeuAuditLog::where('aktivitas', AuditLog::KONFIRMASI_TRANSFER)->count());
    }

    public function test_halaman_tampil(): void
    {
        $this->pindahDana('100000.00', '2026-09-17');
        $this->actingAs($this->pengguna);
        $this->post(route('pindah.cari'));

        $this->get(route('pindah.index'))
            ->assertOk()
            ->assertSee('Ya, pindah dana internal')
            ->assertSee('Konfirmasi semua yang cocok persis (1)');
    }
}