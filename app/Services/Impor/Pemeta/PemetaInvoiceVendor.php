<?php

namespace App\Services\Impor\Pemeta;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\Invoice;
use App\Models\Vendor;
use App\Services\Impor\Pemeta;
use App\Services\Modules\InvoiceService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * HUTANG VENDOR yang masih menggantung saat pindah ke sistem ini — satu baris
 * CSV = satu invoice, supaya hutangnya tetap bisa ditagih dan di-aging per
 * invoice, bukan cuma jadi satu angka di neraca.
 *
 * CARANYA: invoice dibuat lewat InvoiceService seperti biasa (jadi dokumennya
 * sah dan bisa dilekati pembayaran Kas Keluar), TETAPI baris rinciannya
 * diarahkan ke AKUN PERANTARA saldo awal — bukan akun beban. Jurnal yang
 * terbit menjadi:
 *
 *     Debit  Saldo Awal (ekuitas)   xxx
 *     Kredit Hutang Vendor          xxx
 *
 * yaitu jurnal saldo awal yang benar. Kalau barisnya diarahkan ke akun beban
 * seperti invoice biasa, bebannya akan jatuh ke laba rugi tahun berjalan
 * padahal belanjanya terjadi tahun-tahun lalu.
 *
 * KONSEKUENSI: hutang vendor yang masuk lewat sini JANGAN dimasukkan lagi ke
 * menu Saldo Awal — nilainya sudah tercatat oleh jurnal di atas.
 */
class PemetaInvoiceVendor implements Pemeta
{
    use \App\Services\Impor\BantuanPemeta;

    public static function kunci(): string
    {
        return 'invoice-vendor';
    }

    public static function judul(): string
    {
        return 'Hutang Vendor';
    }

    public static function penjelasan(): string
    {
        return 'Invoice vendor yang belum lunas saat pindah sistem. Nominalnya diisi SISA '
            .'yang belum dibayar, bukan nilai asli invoice. Jangan masukkan lagi hutang ini '
            .'ke menu Saldo Awal — jurnalnya sudah terbit dari sini.';
    }

    public function kolom(): array
    {
        return [
            'kode_vendor' => ['wajib' => true, 'contoh' => 'V001', 'ket' => 'Harus ada di master Vendor.'],
            'nomor_invoice' => ['wajib' => true, 'contoh' => 'INV/2026/0451', 'ket' => 'Nomor asli dari vendor. Harus unik.'],
            'tanggal_jatuh_tempo' => ['wajib' => true, 'contoh' => '2026-09-30', 'ket' => 'Jatuh tempo ASLI — inilah yang dipakai laporan umur hutang.'],
            'sisa_hutang' => ['wajib' => true, 'contoh' => '12500000', 'ket' => 'Sisa yang belum dibayar, bukan nilai asli invoice.'],
            'keterangan' => ['wajib' => false, 'contoh' => 'Pengadaan ATK Juni 2026', 'ket' => ''],
        ];
    }

    public function parameter(): array
    {
        return [
            'akun_perantara' => [
                'label' => 'Akun perantara saldo awal',
                'tipe' => 'pilih',
                'opsi' => $this->opsiAkun(),
                'ket' => 'Akun ekuitas penampung saldo pembukaan. JANGAN akun beban — bebannya akan masuk laba rugi tahun berjalan.',
            ],
            'kode_coa_hutang' => [
                'label' => 'Akun hutang vendor',
                'tipe' => 'pilih',
                'opsi' => $this->opsiAkun(),
                'ket' => 'Akun liabilitas yang dikredit, biasanya Hutang Usaha.',
            ],
            'kode_unit' => [
                'label' => 'Unit bisnis',
                'tipe' => 'pilih',
                'opsi' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
                'ket' => '',
            ],
            'tanggal_cutoff' => [
                'label' => 'Tanggal saldo awal',
                'tipe' => 'tanggal',
                'opsi' => [],
                'ket' => 'Dipakai sebagai tanggal invoice agar jurnal saldo awalnya rapi di satu tanggal. Aging tetap memakai jatuh tempo asli.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        foreach (['akun_perantara' => 'Akun perantara', 'kode_coa_hutang' => 'Akun hutang vendor', 'kode_unit' => 'Unit bisnis'] as $k => $label) {
            if (trim($param[$k] ?? '') === '') {
                return "{$label} belum dipilih.";
            }
        }
        if (! CoaDetail::find($param['akun_perantara'])) {
            return 'Akun perantara tidak ditemukan.';
        }
        if (! CoaDetail::find($param['kode_coa_hutang'])) {
            return 'Akun hutang vendor tidak ditemukan.';
        }
        // Penjaga pokok: akun beban (kelompok 5) akan melempar belanja lama ke
        // laba rugi tahun berjalan.
        if (str_starts_with((string) $param['akun_perantara'], '5')) {
            return 'Akun perantara tidak boleh akun Beban (kelompok 5) — belanja tahun lalu akan terhitung sebagai beban tahun ini. Pilih akun ekuitas penampung saldo awal.';
        }
        if (! BusinessUnit::find($param['kode_unit'])) {
            return 'Unit bisnis tidak ditemukan.';
        }
        if (! $this->tanggalSah($param['tanggal_cutoff'] ?? '')) {
            return 'Tanggal saldo awal belum diisi atau tidak terbaca (format YYYY-MM-DD).';
        }

        return null;
    }

    /** Tak ada kolom yang harus tunggal dalam satu berkas. */
    public function kolomUnik(): array
    {
        return [];
    }

    public function periksa(array $baris, array $param): array
    {
        $nomor = trim($baris['nomor_invoice'] ?? '');
        if ($nomor === '') {
            return $this->masalah('Nomor invoice kosong.');
        }
        if (Invoice::where('nomor_invoice', $nomor)->exists()) {
            return ['status' => 'lewati', 'alasan' => null];
        }

        $vendor = trim($baris['kode_vendor'] ?? '');
        if (! Vendor::whereKey($vendor)->exists()) {
            return $this->masalah("Vendor \"{$vendor}\" tidak ada di master Vendor.");
        }

        $jt = trim($baris['tanggal_jatuh_tempo'] ?? '');
        if (! $this->tanggalSah($jt)) {
            return $this->masalah("Jatuh tempo \"{$jt}\" tidak terbaca. Pakai format YYYY-MM-DD.");
        }

        $sisa = $this->angka($baris['sisa_hutang'] ?? '');
        if ($sisa === null) {
            return $this->masalah('Sisa hutang bukan angka yang sah.');
        }
        if (! Money::gtZero($sisa)) {
            return $this->masalah('Sisa hutang harus lebih dari nol — invoice yang sudah lunas tak perlu diimpor.');
        }

        return ['status' => 'siap', 'alasan' => null];
    }

    public function simpan(array $baris, array $param): array
    {
        $svc = new InvoiceService;
        $idPengguna = Auth::user()?->id_pengguna;
        $jumlah = 0;

        foreach ($baris as $b) {
            $sisa = $this->angka($b['sisa_hutang']);
            $ket = trim($b['keterangan'] ?? '') ?: "Saldo awal hutang — {$b['nomor_invoice']}";

            $svc->create([
                'nomor_invoice' => trim($b['nomor_invoice']),
                // Tanggal invoice = tanggal cut-off supaya jurnal saldo awal tak
                // berserakan ke bulan-bulan lalu; aging memakai jatuh tempo.
                'tanggal_invoice' => Carbon::parse($param['tanggal_cutoff'])->toDateString(),
                'tanggal_jatuh_tempo' => Carbon::parse(trim($b['tanggal_jatuh_tempo']))->toDateString(),
                'kode_vendor' => trim($b['kode_vendor']),
                'kode_unit' => $param['kode_unit'],
                'kode_coa_hutang' => $param['kode_coa_hutang'],
                'keterangan' => $ket,
                'details' => [[
                    'kode_coa' => $param['akun_perantara'],
                    'kuantiti' => '1',
                    'harga_satuan' => $sisa,
                    'keterangan' => $ket,
                ]],
            ], $idPengguna);
            $jumlah++;
        }

        return ['invoice' => $jumlah];
    }

    /** @return array<string,string> */
    private function opsiAkun(): array
    {
        return CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();
    }

}
