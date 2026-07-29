<?php

namespace App\Services\Impor\Pemeta;

use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\Wali;
use App\Services\Impor\Pemeta;
use App\Support\Money;

/**
 * SANTRI LAMA — santri yang sudah bersekolah sebelum aplikasi ini dipakai.
 *
 * SENGAJA TIDAK lewat jalur PPSB. Pendaftaran biasa menerbitkan tagihan
 * registrasi otomatis, dan memajukan status hanya bisa dengan memverifikasi
 * pembayaran registrasi — yang MENERBITKAN JURNAL. Memaksakan jalur itu untuk
 * santri lama berarti mengarang uang masuk dan pendapatan yang tak pernah ada.
 * Karena itu di sini santri ditulis langsung berstatus "aktif", memakai NIS
 * aslinya, tanpa baris pendaftaran dan tanpa tagihan registrasi.
 *
 * Tunggakannya dibuat sebagai tagihan ber-`sudah_akrual = true` dan TANPA
 * jurnal: nilainya sudah diakui sebagai pendapatan di catatan lama. Penanda itu
 * membuat pembayaran nanti mengkredit PIUTANG, bukan Pendapatan — kalau tidak,
 * pendapatannya tercatat dua kali. Total piutangnya masuk sekali lewat jurnal
 * pembuka (menu Saldo Awal), bukan dari sini.
 */
class PemetaSantriLama implements Pemeta
{
    use \App\Services\Impor\BantuanPemeta;

    public static function kunci(): string
    {
        return 'santri-lama';
    }

    public static function judul(): string
    {
        return 'Santri Lama';
    }

    public static function penjelasan(): string
    {
        return 'Santri yang sudah bersekolah sebelum aplikasi ini dipakai. Ditulis langsung '
            .'berstatus aktif dengan NIS aslinya — tanpa tagihan registrasi dan tanpa jurnal. '
            .'Tunggakannya ikut dibuat bila kolomnya diisi.';
    }

    public function kolom(): array
    {
        return [
            'nis' => ['wajib' => true, 'contoh' => '230015', 'ket' => 'NIS asli santri. Harus unik.'],
            'nama' => ['wajib' => true, 'contoh' => 'Ahmad Fauzi', 'ket' => 'Nama lengkap.'],
            'jenis_kelamin' => ['wajib' => true, 'contoh' => 'L', 'ket' => 'L atau P.'],
            'kode_jenjang' => ['wajib' => true, 'contoh' => 'SMP', 'ket' => 'Harus ada di master Jenjang.'],
            'tahun_ajaran' => ['wajib' => true, 'contoh' => '2026/2027', 'ket' => 'T.A yang sedang dijalani.'],
            'jalur' => ['wajib' => true, 'contoh' => 'LAMA', 'ket' => 'Kode jalur dari master. Disarankan jalur khusus "Santri Lama".'],
            'wali_nama' => ['wajib' => true, 'contoh' => 'Bapak Fauzi', 'ket' => 'Dibuat otomatis bila belum ada.'],
            'wali_telepon' => ['wajib' => true, 'contoh' => '08123456789', 'ket' => 'Kunci pengait: telepon sama = wali yang sama, jadi kakak-beradik menempel ke satu wali.'],
            'angkatan' => ['wajib' => false, 'contoh' => '2023', 'ket' => 'Tahun masuk.'],
            'tempat_lahir' => ['wajib' => false, 'contoh' => 'Bogor', 'ket' => ''],
            'tanggal_lahir' => ['wajib' => false, 'contoh' => '2011-05-17', 'ket' => 'Format YYYY-MM-DD.'],
            'nisn' => ['wajib' => false, 'contoh' => '0071234567', 'ket' => ''],
            'tunggakan_spp' => ['wajib' => false, 'contoh' => '1500000', 'ket' => 'Sisa tunggakan SPP. Kosong atau 0 = tidak ada.'],
            'ket_tunggakan_spp' => ['wajib' => false, 'contoh' => 'Tunggakan SPP Jan-Jun 2026', 'ket' => 'Keterangan yang tampil di tagihan.'],
            'tunggakan_uang_pangkal' => ['wajib' => false, 'contoh' => '5000000', 'ket' => 'Sisa uang pangkal yang belum dibayar.'],
            'ket_tunggakan_uang_pangkal' => ['wajib' => false, 'contoh' => 'Sisa uang pangkal angkatan 2023', 'ket' => ''],
        ];
    }

    public function parameter(): array
    {
        // Tunggakan lama JANGAN memakai jenis biaya SPP yang berjalan — nanti
        // tercampur dengan SPP bulanan. Pilih jenis berperilaku "lain" khusus
        // saldo awal, dan jenis itu WAJIB punya akun piutang karena itulah yang
        // dikredit saat wali membayar.
        $opsi = JenisBiaya::whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku('lain'))
            ->where('status', 'aktif')->orderBy('kode')
            ->get()->mapWithKeys(fn ($j) => [$j->kode => "{$j->kode} — {$j->nama}"])->all();

        return [
            'jenis_tunggakan_spp' => [
                'label' => 'Jenis biaya untuk tunggakan SPP',
                'tipe' => 'pilih',
                'opsi' => $opsi,
                'ket' => 'Wajib diisi bila ada kolom tunggakan_spp. Pakai jenis khusus saldo awal, bukan SPP berjalan.',
            ],
            'jenis_tunggakan_uang_pangkal' => [
                'label' => 'Jenis biaya untuk sisa uang pangkal',
                'tipe' => 'pilih',
                'opsi' => $opsi,
                'ket' => 'Wajib diisi bila ada kolom tunggakan_uang_pangkal.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        // Jenis biaya tunggakan boleh kosong (berkas tanpa tunggakan), tetapi
        // kalau diisi harus benar — memeriksanya di sini menghindari pesan yang
        // sama berulang di ratusan baris.
        foreach (['jenis_tunggakan_spp', 'jenis_tunggakan_uang_pangkal'] as $k) {
            $kode = trim($param[$k] ?? '');
            if ($kode === '') {
                continue;
            }
            $jenis = JenisBiaya::whereKey($kode)->first();
            if (! $jenis) {
                return "Jenis biaya \"{$kode}\" tidak ditemukan.";
            }
            if (! $jenis->kode_coa_piutang) {
                return "Jenis biaya \"{$kode}\" belum punya akun piutang — lengkapi dulu di master Jenis Biaya.";
            }
        }

        return null;
    }

    public function periksa(array $baris, array $param): array
    {
        $nis = trim($baris['nis'] ?? '');
        if ($nis === '') {
            return $this->masalah('NIS kosong.');
        }
        if (Santri::where('nis', $nis)->exists()) {
            return $this->lewati(); // sudah pernah diimpor
        }
        if (trim($baris['nama'] ?? '') === '') {
            return $this->masalah('Nama kosong.');
        }
        if (! in_array(strtoupper(trim($baris['jenis_kelamin'] ?? '')), ['L', 'P'], true)) {
            return $this->masalah('Jenis kelamin harus L atau P.');
        }

        $jenjang = trim($baris['kode_jenjang'] ?? '');
        if (! Jenjang::whereKey($jenjang)->exists()) {
            return $this->masalah("Jenjang \"{$jenjang}\" tidak ada di master Jenjang.");
        }

        $ta = trim($baris['tahun_ajaran'] ?? '');
        if (! TahunAjaran::where('kode', $ta)->exists()) {
            return $this->masalah("Tahun ajaran \"{$ta}\" tidak ada di master Tahun Ajaran.");
        }

        // Jalur berlaku lintas tahun ajaran, jadi cukup diperiksa keberadaannya.
        $jalur = trim($baris['jalur'] ?? '');
        if (! JalurPendaftaran::whereKey($jalur)->exists()) {
            return $this->masalah("Jalur \"{$jalur}\" tidak ada di master Jalur Pendaftaran.");
        }

        $telepon = trim($baris['wali_telepon'] ?? '');
        $namaWali = trim($baris['wali_nama'] ?? '');
        if ($telepon === '' || $namaWali === '') {
            return $this->masalah('Nama atau telepon wali kosong.');
        }
        $waliAda = Wali::where('telepon', $telepon)->first();
        if ($waliAda && mb_strtolower($waliAda->nama) !== mb_strtolower($namaWali)) {
            return $this->masalah("Telepon {$telepon} sudah dipakai wali bernama \"{$waliAda->nama}\" — periksa apakah datanya keliru.");
        }

        if (($t = trim($baris['tanggal_lahir'] ?? '')) !== '' && ! $this->tanggalSah($t)) {
            return $this->masalah("Tanggal lahir \"{$t}\" tidak terbaca. Pakai format YYYY-MM-DD.");
        }

        foreach ([
            'tunggakan_spp' => 'jenis_tunggakan_spp',
            'tunggakan_uang_pangkal' => 'jenis_tunggakan_uang_pangkal',
        ] as $kolom => $kunciParam) {
            $nilai = $this->angka($baris[$kolom] ?? '');
            if ($nilai === null) {
                return $this->masalah("Kolom {$kolom} bukan angka yang sah.");
            }
            if (Money::gtZero($nilai)) {
                $kodeJenis = trim($param[$kunciParam] ?? '');
                if ($kodeJenis === '') {
                    return $this->masalah("Ada nilai di {$kolom}, tetapi jenis biayanya belum dipilih di form impor.");
                }
                $jenis = JenisBiaya::whereKey($kodeJenis)->first();
                if (! $jenis) {
                    return $this->masalah("Jenis biaya \"{$kodeJenis}\" tidak ditemukan.");
                }
                if (! $jenis->kode_coa_piutang) {
                    return $this->masalah("Jenis biaya \"{$kodeJenis}\" belum punya akun piutang — lengkapi dulu di master Jenis Biaya.");
                }
            }
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $dibuat = ['santri' => 0, 'wali' => 0, 'tagihan' => 0];
        $urut = $this->urutTerakhirNoPendaftaran();

        foreach ($baris as $b) {
            $telepon = trim($b['wali_telepon']);
            $wali = Wali::where('telepon', $telepon)->first();
            if (! $wali) {
                $wali = Wali::create([
                    'nama' => trim($b['wali_nama']),
                    'telepon' => $telepon,
                    'status' => 'aktif',
                ]);
                $dibuat['wali']++;
            }

            $santri = Santri::create([
                // Awalan sendiri supaya santri lama langsung terbedakan dari
                // pendaftar PPSB dan tak mengacaukan penomoran PSB.
                'no_pendaftaran' => 'LAMA-'.str_pad((string) (++$urut), 4, '0', STR_PAD_LEFT),
                'nis' => trim($b['nis']),
                'nama' => trim($b['nama']),
                'jenis_kelamin' => strtoupper(trim($b['jenis_kelamin'])),
                'tempat_lahir' => $this->kosongJadiNull($b['tempat_lahir'] ?? ''),
                'tanggal_lahir' => $this->kosongJadiNull($b['tanggal_lahir'] ?? ''),
                'nisn' => $this->kosongJadiNull($b['nisn'] ?? ''),
                'kode_jenjang' => trim($b['kode_jenjang']),
                'angkatan' => ($a = trim($b['angkatan'] ?? '')) !== '' ? (int) $a : null,
                'tahun_ajaran' => trim($b['tahun_ajaran']),
                'jalur' => trim($b['jalur']),
                // Tanpa gelombang: santri lama tak boleh kena hitungan potongan gelombang.
                'gelombang' => null,
                'status' => 'aktif',
                'id_wali' => $wali->id,
            ]);
            $dibuat['santri']++;

            foreach ([
                ['tunggakan_spp', 'ket_tunggakan_spp', 'jenis_tunggakan_spp', 'Tunggakan SPP'],
                ['tunggakan_uang_pangkal', 'ket_tunggakan_uang_pangkal', 'jenis_tunggakan_uang_pangkal', 'Sisa uang pangkal'],
            ] as [$kolom, $kolomKet, $kunciParam, $bawaanKet]) {
                $nilai = $this->angka($b[$kolom] ?? '');
                if ($nilai === null || ! Money::gtZero($nilai)) {
                    continue;
                }
                TagihanSantri::create([
                    'id_santri' => $santri->id,
                    'kode_jenis' => trim($param[$kunciParam]),
                    'nominal' => $nilai,
                    'sisa' => $nilai,
                    'status' => 'belum_bayar',
                    // Nilainya sudah diakui sebagai pendapatan di catatan lama →
                    // pembayaran nanti mengkredit PIUTANG, bukan Pendapatan.
                    'sudah_akrual' => true,
                    'keterangan' => $this->kosongJadiNull($b[$kolomKet] ?? '') ?? $bawaanKet,
                ]);
                $dibuat['tagihan']++;
            }
        }

        return $dibuat;
    }

    private function urutTerakhirNoPendaftaran(): int
    {
        $last = Santri::where('no_pendaftaran', 'like', 'LAMA-%')
            ->orderByDesc('no_pendaftaran')->value('no_pendaftaran');

        return $last ? (int) substr($last, 5) : 0;
    }

}
