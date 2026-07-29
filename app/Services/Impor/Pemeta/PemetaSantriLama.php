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

    /**
     * Jenis tunggakan yang bisa dibawa santri lama, mengikuti daftar Tipe Biaya.
     * Kunci menentukan nama kolom (`tunggakan_{kunci}`, `ket_tunggakan_{kunci}`)
     * dan nama parameternya (`jenis_tunggakan_{kunci}`) — ditulis sekali di sini
     * supaya menambah jenis berikutnya tak perlu menyunting tiga tempat.
     *
     * Registrasi sengaja TIDAK ada: biaya pendaftaran dibayar di awal, tak
     * mungkin menjadi tunggakan santri yang sudah bersekolah.
     */
    private const TUNGGAKAN = [
        'spp' => [
            'label' => 'SPP',
            'contoh' => '1500000',
            'contoh_ket' => 'Tunggakan SPP Jan-Jun 2026',
            'bawaan_ket' => 'Tunggakan SPP',
        ],
        'uang_pangkal' => [
            'label' => 'Uang Pangkal',
            'contoh' => '5000000',
            'contoh_ket' => 'Sisa uang pangkal angkatan 2023',
            'bawaan_ket' => 'Sisa uang pangkal',
        ],
        'daftar_ulang' => [
            'label' => 'Daftar Ulang',
            'contoh' => '750000',
            'contoh_ket' => 'Daftar ulang T.A 2026/2027',
            'bawaan_ket' => 'Tunggakan daftar ulang',
        ],
        'lain' => [
            'label' => 'Tagihan Lain',
            'contoh' => '300000',
            'contoh_ket' => 'Seragam & buku',
            'bawaan_ket' => 'Tunggakan lain-lain',
        ],
    ];

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
            .'Tunggakannya ikut dibuat bila kolomnya diisi: SPP, uang pangkal, daftar ulang, '
            .'dan tagihan lain — satu tagihan per jenis, jadi pelunasannya bisa dipisah.';
    }

    public function kolom(): array
    {
        $kolom = [
            'nis' => ['wajib' => true, 'contoh' => '230015', 'ket' => 'NIS asli santri. Harus unik.'],
            'nama' => ['wajib' => true, 'contoh' => 'Ahmad Fauzi', 'ket' => 'Nama lengkap.'],
            'jenis_kelamin' => ['wajib' => true, 'contoh' => 'L', 'ket' => 'L atau P.'],
            'kode_jenjang' => ['wajib' => true, 'contoh' => 'SMP', 'ket' => 'Harus ada di master Jenjang.'],
            // Tingkat WAJIB: tanpa itu proses kenaikan tahun depan tak tahu harus
            // menaikkan dari mana, dan ratusan santri harus diisi satu per satu.
            'tingkat' => ['wajib' => true, 'contoh' => '2', 'ket' => 'Tingkat/kelas yang sedang dijalani. Harus dalam jangkauan jenjangnya (lihat Jumlah Tingkat di master Jenjang).'],
            'tahun_ajaran' => ['wajib' => true, 'contoh' => '2026/2027', 'ket' => 'T.A yang sedang dijalani.'],
            'jalur' => ['wajib' => true, 'contoh' => 'LAMA', 'ket' => 'Kode jalur dari master. Disarankan jalur khusus "Santri Lama".'],
            'wali_nama' => ['wajib' => true, 'contoh' => 'Bapak Fauzi', 'ket' => 'Dibuat otomatis bila belum ada.'],
            'wali_telepon' => ['wajib' => true, 'contoh' => '08123456789', 'ket' => 'Kunci pengait: telepon sama = wali yang sama, jadi kakak-beradik menempel ke satu wali.'],
            'angkatan' => ['wajib' => false, 'contoh' => '2023', 'ket' => 'Tahun masuk.'],
            'tempat_lahir' => ['wajib' => false, 'contoh' => 'Bogor', 'ket' => ''],
            'tanggal_lahir' => ['wajib' => false, 'contoh' => '2011-05-17', 'ket' => 'Format YYYY-MM-DD.'],
            'nisn' => ['wajib' => false, 'contoh' => '0071234567', 'ket' => ''],
        ];

        // Empat pasang kolom tunggakan — semuanya opsional; yang kosong/0 tidak
        // menerbitkan tagihan apa pun.
        foreach (self::TUNGGAKAN as $k => $t) {
            $kolom["tunggakan_{$k}"] = [
                'wajib' => false, 'contoh' => $t['contoh'],
                'ket' => "Sisa tunggakan {$t['label']} yang belum dibayar. Kosong atau 0 = tidak ada.",
            ];
            $kolom["ket_tunggakan_{$k}"] = [
                'wajib' => false, 'contoh' => $t['contoh_ket'],
                'ket' => 'Keterangan yang tampil di tagihan.',
            ];
        }

        return $kolom;
    }

    public function parameter(): array
    {
        // Syarat mutlak: jenis biayanya WAJIB punya akun piutang, karena itulah
        // yang dikredit saat wali membayar tunggakan. Perilakunya sendiri tidak
        // dibatasi — daftar Tipe Biaya tiap pesantren berbeda, dan memaksa
        // "harus berperilaku lain" membuat tunggakan SPP/uang pangkal tak bisa
        // ditunjuk ke jenis biayanya yang sebenarnya. Konsekuensi memilih jenis
        // yang MASIH BERJALAN disebutkan di keterangan tiap isian.
        $opsi = JenisBiaya::whereNotNull('kode_coa_piutang')
            ->where('status', 'aktif')->orderBy('kode')
            ->get()->mapWithKeys(fn ($j) => [
                $j->kode => "{$j->kode} — {$j->nama}".($j->tahun_ajaran ? " (T.A {$j->tahun_ajaran})" : ''),
            ])->all();

        $catatan = [
            'spp' => 'Bila ditunjuk ke jenis SPP yang berjalan, tagihan ini tetap TIDAK mengganggu penerbitan SPP bulanan (penjaganya per periode, dan tagihan tunggakan tak berperiode) — hanya saja di laporan keduanya menyatu.',
            'uang_pangkal' => 'Bila ditunjuk ke jenis uang pangkal yang berjalan, tagihan ini ikut muncul di modul Angsuran Uang Pangkal (bisa dibuatkan termin) dan di kartu penerimaan uang pangkal Dashboard PPSB.',
            'daftar_ulang' => '',
            'lain' => '',
        ];

        $hasil = [];
        foreach (self::TUNGGAKAN as $k => $t) {
            $hasil["jenis_tunggakan_{$k}"] = [
                'label' => "Jenis biaya untuk tunggakan {$t['label']}",
                'tipe' => 'pilih',
                'opsi' => $opsi,
                'ket' => trim("Wajib dipilih bila kolom tunggakan_{$k} ada isinya. ".($catatan[$k] ?? '')),
            ];
        }

        return $hasil;
    }

    public function periksaParameter(array $param): ?string
    {
        // Jenis biaya tunggakan boleh kosong (berkas tanpa tunggakan), tetapi
        // kalau diisi harus benar — memeriksanya di sini menghindari pesan yang
        // sama berulang di ratusan baris.
        foreach (array_keys(self::TUNGGAKAN) as $k) {
            if ($salah = $this->periksaJenis(trim($param["jenis_tunggakan_{$k}"] ?? ''))) {
                return $salah;
            }
        }

        return null;
    }

    /** Jenis biaya tunggakan sah? Kosong dianggap sah (berkas boleh tanpa tunggakan). */
    private function periksaJenis(string $kode): ?string
    {
        if ($kode === '') {
            return null;
        }
        $jenis = JenisBiaya::whereKey($kode)->first();
        if (! $jenis) {
            return "Jenis biaya \"{$kode}\" tidak ditemukan.";
        }
        if (! $jenis->kode_coa_piutang) {
            return "Jenis biaya \"{$kode}\" belum punya akun piutang — lengkapi dulu di master Jenis Biaya.";
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

        $kodeJenjang = trim($baris['kode_jenjang'] ?? '');
        $jenjang = Jenjang::find($kodeJenjang);
        if (! $jenjang) {
            return $this->masalah("Jenjang \"{$kodeJenjang}\" tidak ada di master Jenjang.");
        }

        // Tingkat dibatasi jenjangnya — aturan yang sama dengan form pendaftaran.
        $tingkat = trim($baris['tingkat'] ?? '');
        if ($tingkat === '' || ! ctype_digit($tingkat)) {
            return $this->masalah('Tingkat kosong atau bukan angka bulat.');
        }
        if (! $jenjang->jumlah_tingkat) {
            return $this->masalah("Jumlah tingkat jenjang \"{$jenjang->nama}\" belum diisi di master Jenjang, jadi tingkat santri tak bisa diperiksa.");
        }
        if ((int) $tingkat < 1 || (int) $tingkat > $jenjang->jumlah_tingkat) {
            return $this->masalah("Tingkat {$tingkat} tidak ada di jenjang \"{$jenjang->nama}\" (hanya tingkat 1–{$jenjang->jumlah_tingkat}).");
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

        foreach (array_keys(self::TUNGGAKAN) as $k) {
            $kolom = "tunggakan_{$k}";
            $nilai = $this->angka($baris[$kolom] ?? '');
            if ($nilai === null) {
                return $this->masalah("Kolom {$kolom} bukan angka yang sah.");
            }
            if (Money::gtZero($nilai)) {
                $kodeJenis = trim($param["jenis_tunggakan_{$k}"] ?? '');
                if ($kodeJenis === '') {
                    return $this->masalah("Ada nilai di {$kolom}, tetapi jenis biayanya belum dipilih di form impor.");
                }
                if ($salah = $this->periksaJenis($kodeJenis)) {
                    return $this->masalah($salah);
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
                'tingkat' => (int) trim($b['tingkat']),
                'angkatan' => ($a = trim($b['angkatan'] ?? '')) !== '' ? (int) $a : null,
                'tahun_ajaran' => trim($b['tahun_ajaran']),
                'jalur' => trim($b['jalur']),
                // Tanpa gelombang: santri lama tak boleh kena hitungan potongan gelombang.
                'gelombang' => null,
                'status' => 'aktif',
                'id_wali' => $wali->id,
            ]);
            $dibuat['santri']++;

            foreach (self::TUNGGAKAN as $k => $t) {
                $nilai = $this->angka($b["tunggakan_{$k}"] ?? '');
                if ($nilai === null || ! Money::gtZero($nilai)) {
                    continue;
                }
                TagihanSantri::create([
                    'id_santri' => $santri->id,
                    'kode_jenis' => trim($param["jenis_tunggakan_{$k}"]),
                    'nominal' => $nilai,
                    'sisa' => $nilai,
                    'status' => 'belum_bayar',
                    // Nilainya sudah diakui sebagai pendapatan di catatan lama →
                    // pembayaran nanti mengkredit PIUTANG, bukan Pendapatan.
                    'sudah_akrual' => true,
                    'keterangan' => $this->kosongJadiNull($b["ket_tunggakan_{$k}"] ?? '') ?? $t['bawaan_ket'],
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
