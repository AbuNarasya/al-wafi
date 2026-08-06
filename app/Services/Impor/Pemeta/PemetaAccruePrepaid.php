<?php

namespace App\Services\Impor\Pemeta;

use App\Models\Accrue;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Services\Modules\AccrueService;
use Illuminate\Support\Facades\Auth;

/**
 * ACCRUE & PREPAID yang masih berjalan saat pindah sistem — beban yang sudah
 * diakui tapi belum dibayar, atau biaya dibayar dimuka yang belum habis.
 *
 * Dokumennya dibuat dengan PASANGAN AKUN ASLINYA (bukan lewat akun perantara),
 * supaya saat dibalik/dikonsumsi nanti jurnalnya benar. Yang dimatikan hanya
 * penerbitan jurnalnya (`tanpa_jurnal`), karena nilainya sudah masuk lewat
 * jurnal pembuka — kalau dibiarkan terbit, angkanya dihitung dua kali.
 *
 * Perbandingan: hutang vendor memakai akal-akalan akun perantara karena modul
 * Invoice tak punya saklar jurnal; modul Accrue diberi saklar itu.
 */
class PemetaAccruePrepaid implements Pemeta
{
    use BantuanPemeta;

    public static function kunci(): string
    {
        return 'accrue-prepaid';
    }

    public static function judul(): string
    {
        return 'Accrue & Prepaid';
    }

    public static function penjelasan(): string
    {
        return 'Beban akrual dan biaya dibayar dimuka yang masih menggantung. Dicatat dengan '
            .'pasangan akun aslinya TANPA jurnal — nilainya masuk lewat jurnal pembuka.';
    }

    public function kolom(): array
    {
        return [
            'nomor_bukti' => ['wajib' => true, 'contoh' => 'ACR/2026/003', 'ket' => 'Nomor bukti asli. Disimpan di awal keterangan dan dipakai mengenali baris yang sudah masuk.'],
            'tanggal' => ['wajib' => true, 'contoh' => '2026-06-30', 'ket' => 'Tanggal pengakuan aslinya.'],
            'kode_coa_debet' => ['wajib' => true, 'contoh' => '1.1.05.001', 'ket' => 'Prepaid: akun Biaya Dibayar Dimuka. Accrue: akun Beban.'],
            'kode_coa_kredit' => ['wajib' => true, 'contoh' => '2.1.02.001', 'ket' => 'Prepaid: lawan asetnya. Accrue: akun Beban Yang Masih Harus Dibayar.'],
            'nominal' => ['wajib' => true, 'contoh' => '6000000', 'ket' => 'SISA yang belum habis/belum dibayar, bukan nilai awal.'],
            'periode' => ['wajib' => false, 'contoh' => '2026-06', 'ket' => 'Penanda periode, bebas.'],
            'keterangan' => ['wajib' => false, 'contoh' => 'Sewa dibayar dimuka 6 bulan', 'ket' => ''],
        ];
    }

    public function parameter(): array
    {
        return [
            'kode_unit' => [
                'label' => 'Unit Bisnis',
                'tipe' => 'pilih',
                'opsi' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
                'ket' => 'Boleh dikosongkan bila tidak melekat pada satu unit.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        if (trim($param['kode_unit'] ?? '') !== '' && ! BusinessUnit::find($param['kode_unit'])) {
            return 'Unit bisnis tidak ditemukan.';
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
        $bukti = trim($baris['nomor_bukti'] ?? '');
        if ($bukti === '') {
            return $this->masalah('Nomor bukti kosong — kolom ini wajib supaya baris yang sudah masuk bisa dikenali.');
        }
        if (Accrue::where('keterangan', 'like', $bukti.'%')->exists()) {
            return $this->lewati();
        }
        if (! $this->tanggalSah($baris['tanggal'] ?? null)) {
            return $this->masalah('Tanggal kosong atau tidak terbaca (format YYYY-MM-DD).');
        }

        foreach (['kode_coa_debet', 'kode_coa_kredit'] as $k) {
            $kode = trim($baris[$k] ?? '');
            if ($kode === '') {
                return $this->masalah("Kolom {$k} kosong.");
            }
            if (! CoaDetail::find($kode)) {
                return $this->masalah("Akun \"{$kode}\" pada {$k} tidak ada di Chart of Account.");
            }
        }
        if (trim($baris['kode_coa_debet']) === trim($baris['kode_coa_kredit'])) {
            return $this->masalah('Akun debet dan kredit tidak boleh sama.');
        }
        if ($this->angkaPositif($baris['nominal'] ?? null) === null) {
            return $this->masalah('Nominal harus angka lebih dari nol.');
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $svc = new AccrueService;
        $idPengguna = Auth::user()?->id_pengguna;
        $jumlah = 0;

        foreach ($baris as $b) {
            $bukti = trim($b['nomor_bukti']);
            $ket = trim($b['keterangan'] ?? '');

            $svc->create([
                'tanggal' => $this->tanggal($b['tanggal']),
                'periode' => $this->kosongJadiNull($b['periode'] ?? ''),
                'kode_coa_debet' => trim($b['kode_coa_debet']),
                'kode_coa_kredit' => trim($b['kode_coa_kredit']),
                'nominal' => $this->angka($b['nominal']),
                'kode_unit' => $this->kosongJadiNull($param['kode_unit'] ?? ''),
                'keterangan' => $bukti.($ket !== '' ? " — {$ket}" : ' — saldo awal'),
                'tanpa_jurnal' => true,
            ], $idPengguna);
            $jumlah++;
        }

        return ['accrue' => $jumlah];
    }
}
