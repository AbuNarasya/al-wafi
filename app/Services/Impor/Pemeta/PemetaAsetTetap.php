<?php

namespace App\Services\Impor\Pemeta;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CoaDetail;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Support\Money;

/**
 * ASET TETAP yang sudah berjalan — termasuk yang penyusutannya sudah separuh
 * jalan.
 *
 * Aset yang dibuat manual memang TIDAK berjurnal (jurnal perolehan hanya lahir
 * dari Invoice / Kas Keluar), jadi tak ada saklar yang perlu dimatikan di sini.
 * Nilai perolehan dan akumulasi penyusutannya masuk lewat jurnal pembuka.
 *
 * KUNCI KEBENARAN ANGKA: kolom `akumulasi_depresiasi` WAJIB diisi. Penyusutan
 * bulanan dihitung dari NILAI BUKU (perolehan − akumulasi), bukan dihitung ulang
 * dari tanggal perolehan — jadi aset separuh umur akan melanjutkan dengan benar
 * asal akumulasinya ikut. Bila dikosongkan, sistem menganggapnya aset baru dan
 * menyusutkannya dari nol.
 */
class PemetaAsetTetap implements Pemeta
{
    use BantuanPemeta;

    private const METODE = ['garis_lurus', 'saldo_menurun'];

    public static function kunci(): string
    {
        return 'aset-tetap';
    }

    public static function judul(): string
    {
        return 'Aset Tetap';
    }

    public static function penjelasan(): string
    {
        return 'Aset tetap yang sudah dimiliki, beserta akumulasi penyusutannya sampai tanggal '
            .'saldo awal. Tidak menerbitkan jurnal — nilai perolehan & akumulasinya masuk lewat jurnal pembuka.';
    }

    public function kolom(): array
    {
        return [
            'kode_aset' => ['wajib' => true, 'contoh' => 'AST-0001', 'ket' => 'Kode unik aset. Dipakai mengenali baris yang sudah masuk.'],
            'nama_aset' => ['wajib' => true, 'contoh' => 'Mobil Operasional', 'ket' => ''],
            'harga_perolehan' => ['wajib' => true, 'contoh' => '250000000', 'ket' => 'Nilai perolehan ASLI, bukan nilai buku.'],
            'tanggal_perolehan' => ['wajib' => true, 'contoh' => '2023-05-01', 'ket' => 'Tanggal pembelian asli.'],
            'umur_manfaat' => ['wajib' => true, 'contoh' => '60', 'ket' => 'Dalam BULAN.'],
            'akumulasi_depresiasi' => ['wajib' => true, 'contoh' => '95000000', 'ket' => 'Akumulasi sampai tanggal saldo awal. Isi 0 hanya bila aset benar-benar baru.'],
            'kategori_aset' => ['wajib' => false, 'contoh' => 'KENDARAAN', 'ket' => 'Harus ada di master Kategori Aset bila diisi.'],
            'metode_depresiasi' => ['wajib' => false, 'contoh' => 'garis_lurus', 'ket' => 'garis_lurus atau saldo_menurun. Kosong = garis_lurus.'],
            'nilai_residu' => ['wajib' => false, 'contoh' => '0', 'ket' => ''],
            'kuantiti' => ['wajib' => false, 'contoh' => '1', 'ket' => 'Kosong = 1.'],
            'kode_coa' => ['wajib' => false, 'contoh' => '1.2.01.001', 'ket' => 'Akun aset tetapnya, bila ingin dicatat.'],
        ];
    }

    public function parameter(): array
    {
        return [];
    }

    public function periksaParameter(array $param): ?string
    {
        return null;
    }

    public function periksa(array $baris, array $param): array
    {
        $kode = trim($baris['kode_aset'] ?? '');
        if ($kode === '') {
            return $this->masalah('Kode aset kosong.');
        }
        if (Asset::whereKey($kode)->exists()) {
            return $this->lewati();
        }
        if (trim($baris['nama_aset'] ?? '') === '') {
            return $this->masalah('Nama aset kosong.');
        }

        $perolehan = $this->angkaPositif($baris['harga_perolehan'] ?? null);
        if ($perolehan === null) {
            return $this->masalah('Harga perolehan harus angka lebih dari nol.');
        }
        if (! $this->tanggalSah($baris['tanggal_perolehan'] ?? null)) {
            return $this->masalah('Tanggal perolehan kosong atau tidak terbaca (format YYYY-MM-DD).');
        }

        $umur = trim($baris['umur_manfaat'] ?? '');
        if (! ctype_digit($umur) || (int) $umur <= 0) {
            return $this->masalah('Umur manfaat harus angka bulat bulan lebih dari nol.');
        }

        // Sengaja diperiksa keberadaannya, bukan sekadar boleh kosong: aset lama
        // yang lupa diisi akumulasinya akan disusutkan ulang dari nol dan
        // bebannya melonjak tanpa sebab yang kelihatan.
        if (trim($baris['akumulasi_depresiasi'] ?? '') === '') {
            return $this->masalah('Akumulasi penyusutan wajib diisi (0 bila aset benar-benar baru) — bila dikosongkan, aset akan disusutkan ulang dari nol.');
        }
        $akumulasi = $this->angka($baris['akumulasi_depresiasi']);
        if ($akumulasi === null) {
            return $this->masalah('Akumulasi penyusutan bukan angka yang sah.');
        }
        if (Money::gt($akumulasi, $perolehan)) {
            return $this->masalah('Akumulasi penyusutan melebihi harga perolehan.');
        }

        $residu = $this->angka($baris['nilai_residu'] ?? null);
        if ($residu === null) {
            return $this->masalah('Nilai residu bukan angka yang sah.');
        }
        if (Money::gt($residu, $perolehan)) {
            return $this->masalah('Nilai residu melebihi harga perolehan.');
        }

        $metode = trim($baris['metode_depresiasi'] ?? '');
        if ($metode !== '' && ! in_array($metode, self::METODE, true)) {
            return $this->masalah("Metode \"{$metode}\" tidak dikenal. Pakai garis_lurus atau saldo_menurun.");
        }

        $kategori = trim($baris['kategori_aset'] ?? '');
        if ($kategori !== '' && ! AssetCategory::whereKey($kategori)->exists()) {
            return $this->masalah("Kategori \"{$kategori}\" tidak ada di master Kategori Aset.");
        }

        $coa = trim($baris['kode_coa'] ?? '');
        if ($coa !== '' && ! CoaDetail::find($coa)) {
            return $this->masalah("Akun \"{$coa}\" tidak ada di Chart of Account.");
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $jumlah = 0;

        foreach ($baris as $b) {
            Asset::create([
                'kode_aset' => trim($b['kode_aset']),
                'nama_aset' => trim($b['nama_aset']),
                'kategori_aset' => $this->kosongJadiNull($b['kategori_aset'] ?? ''),
                'kuantiti' => $this->angka($b['kuantiti'] ?? '') !== '0' ? $this->angka($b['kuantiti']) : '1',
                'harga_perolehan' => $this->angka($b['harga_perolehan']),
                'tanggal_perolehan' => $this->tanggal($b['tanggal_perolehan']),
                'umur_manfaat' => (int) trim($b['umur_manfaat']),
                'metode_depresiasi' => trim($b['metode_depresiasi'] ?? '') ?: 'garis_lurus',
                'nilai_residu' => $this->angka($b['nilai_residu'] ?? ''),
                'akumulasi_depresiasi' => $this->angka($b['akumulasi_depresiasi']),
                'kode_coa' => $this->kosongJadiNull($b['kode_coa'] ?? ''),
                'status' => 'aktif',
                'sumber_ref' => 'impor-saldo-awal',
            ]);
            $jumlah++;
        }

        return ['aset' => $jumlah];
    }
}
