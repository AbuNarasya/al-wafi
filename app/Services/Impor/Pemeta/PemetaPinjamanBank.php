<?php

namespace App\Services\Impor\Pemeta;

use App\Models\BankAccount;
use App\Models\BankLoan;
use App\Models\CoaDetail;
use App\Services\Impor\Pemeta;
use App\Services\Modules\BankLoanService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PEMBIAYAAN BANK yang masih berjalan saat pindah ke sistem ini.
 *
 * Tidak perlu akal-akalan seperti invoice vendor: modul pembiayaan sudah punya
 * saklarnya sendiri. Jurnal pencairan hanya terbit bila diminta, jadi di sini
 * saklar itu SENGAJA dibiarkan mati — uangnya cair bertahun lalu, bukan hari
 * ini, dan mencatatnya sebagai pencairan baru akan menambah kas yang tak ada.
 *
 * KONSEKUENSI: karena tanpa jurnal, saldo hutangnya BELUM ada di buku besar —
 * masukkan totalnya lewat menu Saldo Awal. Angsuran berikutnya lewat Kas Keluar
 * akan mendebit akun hutang itu, jadi salingnya tetap benar.
 *
 * `pokok_awal` diisi SISA POKOK, bukan nilai pinjaman asli: yang dicatat adalah
 * kewajiban yang masih menggantung per tanggal saldo awal.
 */
class PemetaPinjamanBank implements Pemeta
{
    use \App\Services\Impor\BantuanPemeta;

    public static function kunci(): string
    {
        return 'pinjaman-bank';
    }

    public static function judul(): string
    {
        return 'Pembiayaan Bank';
    }

    public static function penjelasan(): string
    {
        return 'Pembiayaan bank yang masih berjalan. Dicatat TANPA jurnal pencairan — '
            .'saldo hutangnya dimasukkan lewat menu Saldo Awal. Isi pokok dengan SISA POKOK, '
            .'bukan nilai pinjaman asli.';
    }

    public function kolom(): array
    {
        return [
            'nama_bank' => ['wajib' => true, 'contoh' => 'Bank Syariah Indonesia', 'ket' => ''],
            'nomor_kontrak' => ['wajib' => true, 'contoh' => 'AKAD/2024/0012', 'ket' => 'Wajib untuk impor — dipakai mengenali baris yang sudah pernah masuk.'],
            'sisa_pokok' => ['wajib' => true, 'contoh' => '250000000', 'ket' => 'Sisa pokok yang belum dibayar per tanggal saldo awal.'],
            'tanggal_mulai' => ['wajib' => true, 'contoh' => '2024-03-01', 'ket' => 'Tanggal akad asli.'],
            'tanggal_jatuh_tempo' => ['wajib' => false, 'contoh' => '2029-03-01', 'ket' => ''],
            'jenis_akad' => ['wajib' => false, 'contoh' => 'murabahah', 'ket' => 'Kosong = murabahah.'],
            'margin' => ['wajib' => false, 'contoh' => '35000000', 'ket' => 'Sisa margin bila ada.'],
            'tenor_bulan' => ['wajib' => false, 'contoh' => '60', 'ket' => ''],
            'keterangan' => ['wajib' => false, 'contoh' => 'Pembiayaan pembangunan asrama', 'ket' => ''],
        ];
    }

    public function parameter(): array
    {
        $akun = CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return [
            'kode_coa_hutang' => [
                'label' => 'Akun hutang bank',
                'tipe' => 'pilih',
                'opsi' => $akun,
                'ket' => 'Akun liabilitas tempat sisa pokok dicatat. Angsuran lewat Kas Keluar nanti mendebit akun ini.',
            ],
            'kode_rekening' => [
                'label' => 'Kas/Rekening terkait',
                'tipe' => 'pilih',
                // bank_accounts berkunci kode_coa; kolom bank_loans.kode_rekening
                // memang menyimpan kode COA rekening itu.
                'opsi' => BankAccount::where('status', 'aktif')->orderBy('kode_coa')
                    ->get(['kode_coa', 'nama_rekening'])
                    ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->kode_coa} — {$r->nama_rekening}"])->all(),
                'ket' => 'Rekening yang dulu menerima pencairan. Hanya disimpan sebagai rujukan — tidak ada uang yang dicatat masuk.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        if (trim($param['kode_coa_hutang'] ?? '') === '') {
            return 'Akun hutang bank belum dipilih.';
        }
        if (! CoaDetail::find($param['kode_coa_hutang'])) {
            return 'Akun hutang bank tidak ditemukan.';
        }
        if (trim($param['kode_rekening'] ?? '') === '') {
            return 'Kas/Rekening terkait belum dipilih.';
        }
        if (! BankAccount::find($param['kode_rekening'])) {
            return 'Kas/Rekening tidak ditemukan.';
        }

        return null;
    }

    public function periksa(array $baris, array $param): array
    {
        $kontrak = trim($baris['nomor_kontrak'] ?? '');
        if ($kontrak === '') {
            return $this->masalah('Nomor kontrak kosong — kolom ini wajib supaya baris yang sudah masuk bisa dikenali.');
        }
        if (BankLoan::where('nomor_kontrak', $kontrak)->exists()) {
            return ['status' => 'lewati', 'alasan' => null];
        }
        if (trim($baris['nama_bank'] ?? '') === '') {
            return $this->masalah('Nama bank kosong.');
        }

        $pokok = $this->angka($baris['sisa_pokok'] ?? '');
        if ($pokok === null) {
            return $this->masalah('Sisa pokok bukan angka yang sah.');
        }
        if (! Money::gtZero($pokok)) {
            return $this->masalah('Sisa pokok harus lebih dari nol — pembiayaan yang sudah lunas tak perlu diimpor.');
        }

        if (! $this->tanggalSah(trim($baris['tanggal_mulai'] ?? ''))) {
            return $this->masalah('Tanggal mulai kosong atau tidak terbaca (format YYYY-MM-DD).');
        }
        if (($jt = trim($baris['tanggal_jatuh_tempo'] ?? '')) !== '' && ! $this->tanggalSah($jt)) {
            return $this->masalah("Jatuh tempo \"{$jt}\" tidak terbaca.");
        }
        if (($m = trim($baris['margin'] ?? '')) !== '' && $this->angka($m) === null) {
            return $this->masalah('Margin bukan angka yang sah.');
        }
        if (($t = trim($baris['tenor_bulan'] ?? '')) !== '' && ! ctype_digit($t)) {
            return $this->masalah('Tenor bulan harus berupa angka bulat.');
        }

        return ['status' => 'siap', 'alasan' => null];
    }

    public function simpan(array $baris, array $param): array
    {
        $svc = new BankLoanService;
        $idPengguna = Auth::user()?->id_pengguna;
        $jumlah = 0;

        foreach ($baris as $b) {
            $svc->create([
                'nama_bank' => trim($b['nama_bank']),
                'nomor_kontrak' => trim($b['nomor_kontrak']),
                'jenis_akad' => trim($b['jenis_akad'] ?? '') ?: 'murabahah',
                'pokok_awal' => $this->angka($b['sisa_pokok']),
                'margin' => ($m = trim($b['margin'] ?? '')) !== '' ? $this->angka($m) : null,
                'tenor_bulan' => ($t = trim($b['tenor_bulan'] ?? '')) !== '' ? (int) $t : null,
                'tanggal_mulai' => Carbon::parse(trim($b['tanggal_mulai']))->toDateString(),
                'tanggal_jatuh_tempo' => ($jt = trim($b['tanggal_jatuh_tempo'] ?? '')) !== ''
                    ? Carbon::parse($jt)->toDateString() : null,
                'kode_coa_hutang' => $param['kode_coa_hutang'],
                'kode_rekening' => $param['kode_rekening'],
                'keterangan' => trim($b['keterangan'] ?? '') ?: 'Saldo awal pembiayaan',
                // SENGAJA tidak dikirim: uangnya cair bertahun lalu, bukan hari ini.
                // 'posting_pencairan' => false,
            ], $idPengguna);
            $jumlah++;
        }

        return ['pembiayaan' => $jumlah];
    }

}
