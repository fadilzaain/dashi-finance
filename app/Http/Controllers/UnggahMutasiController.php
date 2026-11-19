<?php

namespace App\Http\Controllers;

use App\Models\KeuImporBaris;
use App\Models\KeuImporBatch;
use App\Models\KeuRekeningBank;
use App\Services\AuditLog;
use App\Services\Keuangan\ImporMutasi;
use App\Services\Keuangan\SimpanBatch;
use App\Services\Keuangan\SimpanDitolak;
use App\Services\Keuangan\Uang;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnggahMutasiController extends Controller
{
    public function __construct(
        private readonly ImporMutasi $impor,
        private readonly SimpanBatch $simpanBatch,
    ) {
    }

    public function index()
    {
        return view('unggah.form', [
            'rekening' => KeuRekeningBank::where('aktif', true)->orderBy('bank')->orderBy('kode')->get(),
            'riwayat' => KeuImporBatch::with('rekening')->latest('id')->limit(15)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rekening_id' => ['required', Rule::exists('dashi_keu.keu_rekening_bank', 'id')->where('aktif', true)],
            'berkas'      => ['required', 'file', 'max:10240', 'extensions:xls,xlsx,csv'],
        ], [
            'rekening_id.required' => 'Pilih rekening terlebih dahulu.',
            'rekening_id.exists'   => 'Rekening tidak dikenal atau sudah tidak aktif.',
            'berkas.required'      => 'Pilih file mutasi yang akan diunggah.',
            'berkas.file'          => 'Berkas tidak valid.',
            'berkas.uploaded'      => 'File gagal diunggah. Ukurannya kemungkinan melebihi batas server.',
            'berkas.max'           => 'Ukuran file maksimal 10 MB.',
            'berkas.extensions'    => 'Format file harus .xls, .xlsx, atau .csv.',
        ]);

        $rekening = KeuRekeningBank::findOrFail($data['rekening_id']);
        $berkas = $request->file('berkas');

        // File yang sama persis (isinya) untuk rekening yang sama tidak diproses dua kali
        $hash = $this->impor->hash($berkas);
        $sama = $this->impor->cariUnggahanSama($rekening, $hash);

        if ($sama !== null) {
            AuditLog::catatAtauLapor(AuditLog::UNGGAH_DITOLAK_SAMA, $sama, null, ['hash_sha256' => $hash], $this->namaBersih($berkas->getClientOriginalName()));

            return back()
                ->withInput($request->only('rekening_id'))
                ->withErrors(['berkas' => 'File yang sama persis sudah pernah diunggah untuk rekening ini ('
                    .$sama->created_at->format('d/m/Y H:i').').'])
                ->with('batch_sama', $sama->kode_batch);
        }

        $batch = $this->impor->proses($rekening, $berkas, $hash, (int) $request->user()->id);

        // Hanya angka ringkasan; isi transaksi (bisa memuat nama pasien) tidak ikut dicatat
        AuditLog::catatAtauLapor(AuditLog::UNGGAH, $batch, null, [
            'kode_batch' => $batch->kode_batch,
            'rekening' => $rekening->kode,
            'format' => $batch->format_file,
            'ukuran_byte' => $batch->ukuran_byte,
            'hash_sha256' => $batch->hash_sha256,
            'status' => $batch->status,
            'jumlah_baris' => $batch->jumlah_baris,
            'jumlah_tidak_valid' => $batch->jumlah_tidak_valid,
            'jumlah_duplikat' => $batch->jumlah_duplikat,
            'hasil_rekonsiliasi' => $batch->hasil_rekonsiliasi ?? 'tidak_bisa_diperiksa',
        ], $batch->nama_file_asli);

        return redirect()->route('unggah.tampil', $batch);
    }

    /**
     * Tombol Simpan: memindahkan batch yang sudah diperiksa ke data final.
     * Semua aturan (rekonsiliasi cocok, tidak ada baris tidak valid, konfirmasi) ditegakkan di SimpanBatch,
     * bukan di sini, supaya tidak bisa dilewati dengan mengirim form buatan sendiri.
     */
    public function simpanKeFinal(Request $request, KeuImporBatch $batch)
    {
        try {
            $jumlah = $this->simpanBatch->simpan($batch, $request->user(), $request->boolean('konfirmasi'));
        } catch (SimpanDitolak $e) {
            return back()->withErrors(['simpan' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            // Transaksi yang sama baru saja disimpan lewat batch lain; transaksi dibatalkan, tidak ada yang tersimpan
            report($e);

            return back()->withErrors(['simpan' => 'Sebagian transaksi baru saja tersimpan lewat unggahan lain. Tidak ada yang disimpan; muat ulang halaman lalu periksa lagi.']);
        }

        return redirect()->route('unggah.tampil', $batch)
            ->with('status', "{$jumlah} transaksi berhasil disimpan ke data final.");
    }

    public function batalkan(Request $request, KeuImporBatch $batch)
    {
        try {
            $this->simpanBatch->batalkan($batch, $request->user());
        } catch (SimpanDitolak $e) {
            return back()->withErrors(['simpan' => $e->getMessage()]);
        }

        return redirect()->route('unggah.tampil', $batch)->with('status', 'Batch dibatalkan. File yang sama boleh diunggah ulang.');
    }

    public function tampil(KeuImporBatch $batch)
    {
        // Yang ditampilkan hanya baris yang perlu dilihat manusia; baris valid cukup lewat angka ringkasan
        $bermasalah = $batch->baris()
            ->where('status', '!=', KeuImporBaris::STATUS_VALID)
            ->orderBy('nomor_baris')
            ->simplePaginate(25);

        return view('unggah.hasil', [
            'batch' => $batch->load('rekening'),
            'bermasalah' => $bermasalah,
            'kontrol' => $this->tabelKontrol($batch),
            'kelayakan' => $batch->status === KeuImporBatch::STATUS_MENUNGGU_VERIFIKASI
                ? $this->simpanBatch->periksa($batch)
                : null,
        ]);
    }

    // Nama file dari pengguna: hanya untuk keterangan audit
    private function namaBersih(string $nama): string
    {
        return mb_substr(mb_scrub(basename(str_replace('\\', '/', $nama))), 0, 255);
    }

    /**
     * Baris tabel rekonsiliasi untuk ditampilkan: angka di file, hasil hitung, dan selisihnya (semua dalam sen).
     * Hanya untuk tampilan; penentu resmi tetap kolom hasil_rekonsiliasi yang diisi Rekonsiliator saat unggah.
     *
     * @return list<array{nama: string, file: ?int, hitung: ?int, selisih: ?int}>
     */
    private function tabelKontrol(KeuImporBatch $batch): array
    {
        // Kolom DECIMAL terbaca sebagai teks ("636774.00"); diubah ke sen supaya tidak ada pembulatan float
        $sen = fn ($nilai) => $nilai === null ? null : Uang::dariTeks((string) $nilai);
        $beda = fn (?int $a, ?int $b) => ($a === null || $b === null) ? null : $a - $b;

        $saldoAwal = $sen($batch->saldo_awal_file);
        $debitFile = $sen($batch->total_debit_file);
        $kreditFile = $sen($batch->total_kredit_file);
        $saldoAkhirFile = $sen($batch->saldo_akhir_file);
        $debitHitung = $sen($batch->total_debit_hitung) ?? 0;
        $kreditHitung = $sen($batch->total_kredit_hitung) ?? 0;
        $saldoAkhirHitung = $saldoAwal !== null ? $saldoAwal + $kreditHitung - $debitHitung : null;

        return [
            ['nama' => 'Saldo awal', 'file' => $saldoAwal, 'hitung' => null, 'selisih' => null],
            ['nama' => 'Total debit', 'file' => $debitFile, 'hitung' => $debitHitung, 'selisih' => $beda($debitHitung, $debitFile)],
            ['nama' => 'Total kredit', 'file' => $kreditFile, 'hitung' => $kreditHitung, 'selisih' => $beda($kreditHitung, $kreditFile)],
            ['nama' => 'Saldo akhir', 'file' => $saldoAkhirFile, 'hitung' => $saldoAkhirHitung, 'selisih' => $beda($saldoAkhirHitung, $saldoAkhirFile)],
        ];
    }
}