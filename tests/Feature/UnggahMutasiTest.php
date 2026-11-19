<?php

namespace Tests\Feature;

use App\Models\KeuImporBatch;
use App\Models\KeuMutasiBank;
use App\Models\KeuRekeningBank;
use App\Models\User;
use Database\Seeders\KeuRekeningBankSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UnggahMutasiTest extends TestCase
{
    private User $pengguna;

    protected function setUp(): void
    {
        parent::setUp();

        // Koneksi dashi_keu di produksi adalah MySQL. Di test dialihkan ke SQLite memori
        // supaya test bisa jalan tanpa MySQL dan tidak menyentuh data asli.
        config(['database.connections.dashi_keu' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('dashi_keu');

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(KeuRekeningBankSeeder::class);

        Storage::fake('local');
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

    private function csvBpd(string $totalDebit = '50.00', string $akhir = '1150.00', string $akhiran = ''): string
    {
        return <<<CSV
Account : 0111009133 / RSUD JOMBANG ( IDR )
Period : 01/09/2026 - 30/09/2026
Starting Balance : IDR 1000.00
Ending Balance : IDR {$akhir}


No,Posting Date,Effective Date,Account,Name,Description,Crrency,Debit,Credit,Balance,Reference No
1,01/09/2026 08:00:00,01/09/2026,null,X,Setoran,IDR,0.00,200.00,1200.00,REF0001
2,02/09/2026 09:00:00,02/09/2026,null,Y,"Bayar, listrik",IDR,50.00,0.00,1150.00,REF0002
,,,,,,,{$totalDebit},200.00,{$akhir},
{$akhiran}
CSV;
    }

    private function xlsxBni(): string
    {
        $baris = [
            ['Account', ':', '400986417 RSUD JOMBANG PEMERINTAH'],
            ['Period', ':', 46266, 46295],
            ['Beginning Balance', ':', 1000],
            ['Total Debit', ':', 50],
            ['Total Credit', ':', 200],
            ['No.', 'Post Date', 'Branch', 'Journal No.', 'Description', 'Amount', 'Db/Cr', 'Balance'],
            [1, 46266.5, 'KCP', 'J0001', 'Setoran', 200, 'C', 1200],
            [2, 46267.5, 'KCP', 'J0002', 'Bayar', 50, 'D', 1150],
        ];

        $lembar = new Spreadsheet();
        $lembar->getActiveSheet()->fromArray($baris, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'bni').'.xlsx';
        (new Xlsx($lembar))->save($path);
        $isi = file_get_contents($path);
        unlink($path);

        return $isi;
    }

    private function rekening(string $kode): KeuRekeningBank
    {
        return KeuRekeningBank::where('kode', $kode)->firstOrFail();
    }

    private function unggah(string $kode, string $namaFile, string $isi)
    {
        return $this->actingAs($this->pengguna)->post(route('unggah.simpan'), [
            'rekening_id' => $this->rekening($kode)->id,
            'berkas' => UploadedFile::fake()->createWithContent($namaFile, $isi),
        ]);
    }

    // ------------------------------------------------------------------ akses

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('unggah.form'))->assertRedirect(route('login'));
        $this->post(route('unggah.simpan'))->assertRedirect(route('login'));
    }

    public function test_halaman_form_tampil(): void
    {
        $this->actingAs($this->pengguna)->get(route('unggah.form'))
            ->assertOk()
            ->assertSee('BNI-001')
            ->assertSee('0111009133')
            ->assertSee('Belum ada unggahan.');
    }

    public function test_kode_batch_bukan_uuid_ditolak_404(): void
    {
        $this->actingAs($this->pengguna)->get('/unggah/1')->assertNotFound();
    }

    // ------------------------------------------------------------------ validasi

    public function test_format_file_selain_xls_xlsx_csv_ditolak(): void
    {
        $this->unggah('BPD-001', 'mutasi.txt', 'apa saja')
            ->assertSessionHasErrors('berkas');

        $this->assertSame(0, KeuImporBatch::count());
    }

    public function test_rekening_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->pengguna)->post(route('unggah.simpan'), [
            'rekening_id' => 9999,
            'berkas' => UploadedFile::fake()->createWithContent('a.csv', 'x'),
        ])->assertSessionHasErrors('rekening_id');
    }

    public function test_rekening_nonaktif_ditolak(): void
    {
        $bpd = $this->rekening('BPD-001');
        $bpd->update(['aktif' => false]);

        $this->unggah('BPD-001', 'a.csv', $this->csvBpd())->assertSessionHasErrors('rekening_id');
    }

    // ------------------------------------------------------------------ alur utama

    public function test_csv_bpd_yang_benar_masuk_staging_dan_cocok(): void
    {
        $respons = $this->unggah('BPD-001', 'mutasi-sep.csv', $this->csvBpd());

        $batch = KeuImporBatch::firstOrFail();
        $respons->assertRedirect(route('unggah.tampil', $batch));

        $this->assertSame(KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI, $batch->status);
        $this->assertSame('cocok', $batch->hasil_rekonsiliasi);
        $this->assertSame(2, $batch->jumlah_baris);
        $this->assertSame(2, $batch->jumlah_valid);
        $this->assertSame('50.00', $batch->total_debit_hitung);
        $this->assertSame('200.00', $batch->total_kredit_hitung);
        $this->assertSame('1000.00', $batch->saldo_awal_file);
        $this->assertSame($this->pengguna->id, (int) $batch->diunggah_oleh);
        $this->assertSame(2, $batch->baris()->count());

        // Deskripsi berkoma tanpa kutip tidak boleh pecah; JSON mentah tersimpan
        $baris2 = $batch->baris()->where('nomor_baris', 2)->firstOrFail();
        $this->assertSame('Bayar, listrik', $baris2->deskripsi);
        $this->assertIsArray($baris2->data_mentah);

        // File tersimpan di storage privat dengan nama acak, bukan nama asli
        Storage::disk('local')->assertExists($batch->path_file);
        $this->assertStringNotContainsString('mutasi-sep', $batch->path_file);

        // Data final tidak boleh tersentuh di M5
        $this->assertSame(0, KeuMutasiBank::count());

        $this->actingAs($this->pengguna)->get(route('unggah.tampil', $batch))
            ->assertOk()
            ->assertSee('mutasi-sep.csv')
            ->assertSee('Menunggu verifikasi')
            ->assertSee('Rekonsiliasi')
            ->assertSee('Semua baris valid');
    }

    public function test_file_sama_persis_tidak_diproses_dua_kali(): void
    {
        $this->unggah('BPD-001', 'a.csv', $this->csvBpd());
        $this->unggah('BPD-001', 'b.csv', $this->csvBpd())
            ->assertSessionHasErrors('berkas')
            ->assertSessionHas('batch_sama');

        $this->assertSame(1, KeuImporBatch::count());
    }

    public function test_selisih_rekonsiliasi_tercatat_dan_ditampilkan(): void
    {
        $this->unggah('BPD-001', 'selisih.csv', $this->csvBpd(totalDebit: '60.00'));

        $batch = KeuImporBatch::firstOrFail();
        $this->assertSame(KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI, $batch->status);
        $this->assertSame('selisih', $batch->hasil_rekonsiliasi);

        $this->actingAs($this->pengguna)->get(route('unggah.tampil', $batch))
            ->assertOk()
            ->assertSee('Selisih')
            ->assertSee('Total debit hasil hitung berbeda');
    }

    public function test_file_bank_salah_ditolak_dengan_alasan(): void
    {
        // CSV BPD diunggah ke rekening BNI
        $this->unggah('BNI-001', 'salah-bank.csv', $this->csvBpd());

        $batch = KeuImporBatch::firstOrFail();
        $this->assertSame(KeuImporBatch::STATUS_DITOLAK, $batch->status);
        $this->assertSame(0, $batch->baris()->count());
        $this->assertStringContainsString('Judul kolom CSV tidak dikenal', $batch->catatan);

        $this->actingAs($this->pengguna)->get(route('unggah.tampil', $batch))
            ->assertOk()
            ->assertSee('File ini tidak diproses')
            ->assertSee('Judul kolom CSV tidak dikenal');
    }

    public function test_file_ditolak_boleh_diunggah_ulang(): void
    {
        $this->unggah('BNI-001', 'salah.csv', $this->csvBpd());
        $this->unggah('BNI-001', 'salah.csv', $this->csvBpd())->assertSessionHasNoErrors();

        $this->assertSame(2, KeuImporBatch::count());
    }

    public function test_xlsx_bni_terbaca_dan_cocok(): void
    {
        $this->unggah('BNI-001', 'bni.xlsx', $this->xlsxBni());

        $batch = KeuImporBatch::firstOrFail();
        $this->assertSame(KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI, $batch->status, (string) $batch->catatan);
        $this->assertSame('cocok', $batch->hasil_rekonsiliasi);
        $this->assertSame(2, $batch->jumlah_baris);
        $this->assertSame('2026-09-01', $batch->periode_awal->format('Y-m-d'));
    }

    public function test_transaksi_yang_sudah_ada_di_data_final_ditandai_duplikat(): void
    {
        $this->unggah('BPD-001', 'a.csv', $this->csvBpd());
        $pertama = KeuImporBatch::firstOrFail();

        // Meniru tombol Simpan (milestone berikutnya): pindahkan baris valid ke data final
        foreach ($pertama->baris as $b) {
            KeuMutasiBank::create([
                'rekening_id' => $pertama->rekening_id,
                'batch_id' => $pertama->id,
                'baris_id' => $b->id,
                'kunci_unik' => $b->kunci_unik,
                'tanggal_transaksi' => $b->tanggal_transaksi,
                'arah' => (float) $b->debit > 0 ? 'debit' : 'kredit',
                'nominal' => (float) $b->debit > 0 ? $b->debit : $b->kredit,
            ]);
        }
        $pertama->update(['status' => KeuImporBatch::STATUS_DISIMPAN]);

        // Isi transaksi sama, tetapi byte file berbeda (baris kosong di akhir), jadi lolos cek hash
        $this->unggah('BPD-001', 'b.csv', $this->csvBpd(akhiran: ' '));

        $kedua = KeuImporBatch::where('id', '!=', $pertama->id)->firstOrFail();
        $this->assertSame(2, $kedua->jumlah_duplikat);
        $this->assertSame(0, $kedua->jumlah_valid);
        $this->assertStringContainsString('sudah tersimpan', $kedua->baris()->first()->pesan_validasi);
    }

    public function test_halaman_hasil_menampilkan_baris_bermasalah(): void
    {
        // Baris 2 sengaja diberi nominal rusak
        $csv = str_replace('50.00,0.00,1150.00', 'abc,0.00,1150.00', $this->csvBpd());
        $this->unggah('BPD-001', 'rusak.csv', $csv);

        $batch = KeuImporBatch::firstOrFail();
        $this->assertSame(1, $batch->jumlah_tidak_valid);

        $this->actingAs($this->pengguna)->get(route('unggah.tampil', $batch))
            ->assertOk()
            ->assertSee('Tidak valid')
            ->assertSee('Nominal debit/kredit tidak valid');
    }

    public function test_nama_file_dengan_html_tidak_menjadi_xss(): void
    {
        $this->unggah('BPD-001', '<script>alert(1)</script>.csv', $this->csvBpd());

        $batch = KeuImporBatch::firstOrFail();

        $this->actingAs($this->pengguna)->get(route('unggah.tampil', $batch))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_nama_file_berbyte_rusak_dibersihkan_agar_tidak_gagal_di_mysql(): void
    {
        $this->unggah('BPD-001', "mutasi\xFF\xFE.csv", $this->csvBpd());

        $batch = KeuImporBatch::firstOrFail();
        $this->assertSame(KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI, $batch->status);
        $this->assertTrue(mb_check_encoding($batch->nama_file_asli, 'UTF-8'));
    }

    public function test_tabel_rekonsiliasi_memakai_angka_dari_database(): void
    {
        $this->unggah('BPD-001', 'a.csv', $this->csvBpd());
        $batch = KeuImporBatch::firstOrFail();

        $this->actingAs($this->pengguna)->get(route('unggah.tampil', $batch))
            ->assertOk()
            ->assertSeeInOrder(['Saldo awal', 'Rp1.000', 'Total debit', 'Rp50', 'Total kredit', 'Rp200', 'Saldo akhir', 'Rp1.150']);
    }
}