<?php

namespace App\Services\Impor\Pemeta;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\OperationalAdvance;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Services\Modules\OperationalAdvanceService;
use Illuminate\Support\Facades\Auth;

/**
 * UANG MUKA BELANJA OPERASIONAL yang masih menggantung saat pindah sistem.
 *
 * Modulnya sudah punya jalur tanpa jurnal: `registerOutstanding()` — dipakai
 * pengajuan uang muka yang pencairannya dicatat di tempat lain. Jalur itulah
 * yang dipakai di sini, karena uangnya keluar bertahun/berbulan lalu; mencatat
 * pencairan hari ini akan mengurangi kas yang sudah benar.
 *
 * Saldo uang mukanya masuk lewat jurnal pembuka (menu Saldo Awal).
 */
class PemetaUangMukaOperasional implements Pemeta
{
    use BantuanPemeta;

    public static function kunci(): string
    {
        return 'uang-muka-operasional';
    }

    public static function judul(): string
    {
        return 'Uang Muka Operasional';
    }

    public static function penjelasan(): string
    {
        return 'Uang muka belanja yang belum dipertanggungjawabkan saat pindah sistem. '
            .'Dicatat tanpa jurnal pencairan; isi dengan SISA yang belum diselesaikan.';
    }

    public function kolom(): array
    {
        return [
            'nomor_bukti' => ['wajib' => true, 'contoh' => 'UM/2026/007', 'ket' => 'Nomor bukti asli. Disimpan di awal keterangan dan dipakai mengenali baris yang sudah masuk.'],
            'tanggal' => ['wajib' => true, 'contoh' => '2026-07-15', 'ket' => 'Tanggal uang muka diberikan.'],
            'penerima' => ['wajib' => true, 'contoh' => 'Ust. Ahmad', 'ket' => 'Orang yang memegang uang mukanya.'],
            'sisa_uang_muka' => ['wajib' => true, 'contoh' => '2500000', 'ket' => 'Sisa yang belum dipertanggungjawabkan, bukan nilai awal.'],
            'keterangan' => ['wajib' => false, 'contoh' => 'Uang muka perjalanan dinas', 'ket' => ''],
        ];
    }

    public function parameter(): array
    {
        $akun = CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return [
            'kode_coa_uang_muka' => [
                'label' => 'Akun Uang Muka',
                'tipe' => 'pilih',
                'opsi' => $akun,
                'ket' => 'Akun aset tempat uang muka dicatat.',
            ],
            'kode_rekening' => [
                'label' => 'Kas/Rekening sumber',
                'tipe' => 'pilih',
                'opsi' => $akun,
                'ket' => 'Rekening yang dulu mengeluarkan uangnya. Hanya rujukan — tidak ada kas yang dicatat keluar.',
            ],
            'kode_unit' => [
                'label' => 'Unit Bisnis',
                'tipe' => 'pilih',
                'opsi' => BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->pluck('nama_unit', 'kode_unit')->all(),
                'ket' => 'Boleh dikosongkan bila uang mukanya tidak melekat pada satu unit.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        if (trim($param['kode_coa_uang_muka'] ?? '') === '' || ! CoaDetail::find($param['kode_coa_uang_muka'])) {
            return 'Akun Uang Muka belum dipilih atau tidak ditemukan.';
        }
        if (trim($param['kode_rekening'] ?? '') === '' || ! CoaDetail::find($param['kode_rekening'])) {
            return 'Kas/Rekening sumber belum dipilih atau tidak ditemukan.';
        }
        if (trim($param['kode_unit'] ?? '') !== '' && ! BusinessUnit::find($param['kode_unit'])) {
            return 'Unit bisnis tidak ditemukan.';
        }

        return null;
    }

    public function periksa(array $baris, array $param): array
    {
        $bukti = trim($baris['nomor_bukti'] ?? '');
        if ($bukti === '') {
            return $this->masalah('Nomor bukti kosong — kolom ini wajib supaya baris yang sudah masuk bisa dikenali.');
        }
        if (OperationalAdvance::where('keterangan', 'like', $bukti.'%')->exists()) {
            return $this->lewati();
        }
        if (trim($baris['penerima'] ?? '') === '') {
            return $this->masalah('Penerima kosong.');
        }
        if (! $this->tanggalSah($baris['tanggal'] ?? null)) {
            return $this->masalah('Tanggal kosong atau tidak terbaca (format YYYY-MM-DD).');
        }
        if ($this->angkaPositif($baris['sisa_uang_muka'] ?? null) === null) {
            return $this->masalah('Sisa uang muka harus angka lebih dari nol — yang sudah selesai tak perlu diimpor.');
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $svc = new OperationalAdvanceService;
        $akun = CoaDetail::find($param['kode_coa_uang_muka']);
        $idPengguna = Auth::user()?->id_pengguna;
        $jumlah = 0;

        foreach ($baris as $b) {
            $bukti = trim($b['nomor_bukti']);
            $ket = trim($b['keterangan'] ?? '');

            $svc->registerOutstanding([
                'tanggal' => $this->tanggal($b['tanggal']),
                'kode_unit' => $this->kosongJadiNull($param['kode_unit'] ?? ''),
                'kode_rekening' => $param['kode_rekening'],
                'kode_coa_uang_muka' => $akun->kode_coa,
                'nama_coa_uang_muka' => $akun->nama_coa,
                'penerima' => trim($b['penerima']),
                // Nomor bukti diletakkan di DEPAN keterangan: itulah penanda yang
                // membuat berkas boleh diunggah ulang tanpa menggandakan baris.
                'keterangan' => $bukti.($ket !== '' ? " — {$ket}" : ' — saldo awal uang muka'),
                'nominal' => $this->angka($b['sisa_uang_muka']),
                'id_pengguna' => $idPengguna,
            ]);
            $jumlah++;
        }

        return ['uang_muka' => $jumlah];
    }
}
