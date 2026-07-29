<?php

namespace App\Services\Impor\Pemeta;

use App\Models\CoaDetail;
use App\Models\Karyawan;
use App\Models\PinjamanKaryawan;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Services\Modules\PinjamanKaryawanService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PINJAMAN KARYAWAN yang masih menggantung saat pindah ke sistem ini.
 *
 * Sama seperti pembiayaan bank, modulnya sudah punya saklarnya sendiri:
 * `posting_pencairan` bersifat opt-in, jadi di sini SENGAJA tidak dikirim —
 * uangnya diserahkan berbulan/bertahun lalu, dan mencatat pencairan hari ini
 * akan mengurangi kas yang sudah benar.
 *
 * KONSEKUENSI: saldo piutangnya BELUM ada di buku besar — masukkan totalnya
 * lewat menu Saldo Awal. Cicilan berikutnya (tunai maupun potong gaji) akan
 * mengkredit akun piutang itu, jadi salingnya tetap benar.
 *
 * `sisa_pokok` — bukan nilai pinjaman asli — karena yang dicatat adalah
 * kewajiban yang masih menggantung per tanggal saldo awal. Dari situ pula
 * `terbayar` tetap nol: cicilan yang sudah lewat bukan urusan sistem ini.
 *
 * KUNCI IDEMPOTEN: tabelnya tak punya kolom nomor rujukan (kolom `nomor` diisi
 * penomoran PKJ- sistem sendiri), jadi nomor bukti lama disimpan di AWAL
 * keterangan — pola yang sama dengan uang muka operasional & accrue.
 *
 * CATATAN: cicilan lewat POTONG GAJI mendebit akun beban, dan baris beban wajib
 * berbagian. Karyawan yang kolom Bagian-nya kosong tetap boleh diimpor di sini,
 * tetapi potong gajinya nanti ditolak sampai bagiannya diisi di master Karyawan.
 */
class PemetaPinjamanKaryawan implements Pemeta
{
    use BantuanPemeta;

    public static function kunci(): string
    {
        return 'pinjaman-karyawan';
    }

    public static function judul(): string
    {
        return 'Pinjaman Karyawan';
    }

    public static function penjelasan(): string
    {
        return 'Pinjaman karyawan yang belum lunas saat pindah sistem. Dicatat TANPA jurnal '
            .'pencairan — saldo piutangnya dimasukkan lewat menu Saldo Awal. Isi pokok dengan '
            .'SISA POKOK, bukan nilai pinjaman asli. Karyawannya harus sudah ada di master '
            .'Karyawan dan berstatus aktif.';
    }

    public function kolom(): array
    {
        return [
            'nomor_bukti' => ['wajib' => true, 'contoh' => 'PJK/2025/014', 'ket' => 'Nomor rujukan dari catatan lama. Disimpan di awal keterangan dan dipakai mengenali baris yang sudah masuk.'],
            'kode_karyawan' => ['wajib' => true, 'contoh' => 'KRY-001', 'ket' => 'Harus ada di master Karyawan dan berstatus aktif.'],
            'tanggal' => ['wajib' => true, 'contoh' => '2025-06-10', 'ket' => 'Tanggal pinjaman aslinya diberikan.'],
            'sisa_pokok' => ['wajib' => true, 'contoh' => '3000000', 'ket' => 'Sisa yang belum dicicil per tanggal saldo awal.'],
            'sisa_termin' => ['wajib' => false, 'contoh' => '10', 'ket' => 'Banyaknya cicilan yang masih tersisa. Kosong = tanpa jadwal, terminnya bisa disusun belakangan dari halaman pinjaman.'],
            'jatuh_tempo_pertama' => ['wajib' => false, 'contoh' => '2026-09-25', 'ket' => 'Jatuh tempo cicilan sisa yang pertama. Wajib bila sisa_termin diisi; berikutnya dihitung bulanan.'],
            'keterangan' => ['wajib' => false, 'contoh' => 'Pinjaman renovasi rumah', 'ket' => ''],
        ];
    }

    public function parameter(): array
    {
        return [
            'kode_coa_piutang' => [
                'label' => 'Akun Piutang Karyawan',
                'tipe' => 'pilih',
                'opsi' => CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
                    ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
                'ket' => 'Akun aset tempat piutangnya dicatat. Cicilan nanti mengkredit akun ini.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        $kode = trim($param['kode_coa_piutang'] ?? '');
        if ($kode === '') {
            return 'Akun Piutang Karyawan belum dipilih.';
        }
        $akun = CoaDetail::find($kode);
        if (! $akun) {
            return 'Akun Piutang Karyawan tidak ditemukan.';
        }
        // Piutang adalah akun neraca bersaldo debet. Akun 4/5 akan membuat
        // cicilan nanti mengkredit laba rugi — pendapatan yang tak pernah ada.
        if (str_starts_with($akun->kode_coa, '4') || str_starts_with($akun->kode_coa, '5')) {
            return "Akun \"{$akun->kode_coa} — {$akun->nama_coa}\" adalah akun laba rugi. Piutang karyawan harus akun neraca; cicilannya akan mengkredit akun ini.";
        }
        if ($akun->jenis_saldo === 'kredit') {
            return "Akun \"{$akun->kode_coa} — {$akun->nama_coa}\" bersaldo normal kredit, bukan akun piutang.";
        }

        return null;
    }

    public function periksa(array $baris, array $param): array
    {
        $bukti = trim($baris['nomor_bukti'] ?? '');
        if ($bukti === '') {
            return $this->masalah('Nomor bukti kosong — kolom ini wajib supaya baris yang sudah masuk bisa dikenali.');
        }
        if (PinjamanKaryawan::where('keterangan', 'like', $bukti.'%')->exists()) {
            return $this->lewati();
        }

        $kode = trim($baris['kode_karyawan'] ?? '');
        $karyawan = $kode !== '' ? Karyawan::find($kode) : null;
        if (! $karyawan) {
            return $this->masalah("Karyawan \"{$kode}\" tidak ada di master Karyawan.");
        }
        if ($karyawan->status !== 'aktif') {
            return $this->masalah("Karyawan \"{$karyawan->nama}\" berstatus nonaktif — aktifkan dulu di master Karyawan.");
        }

        if (! $this->tanggalSah($baris['tanggal'] ?? null)) {
            return $this->masalah('Tanggal kosong atau tidak terbaca (format YYYY-MM-DD).');
        }

        $pokok = $this->angkaPositif($baris['sisa_pokok'] ?? null);
        if ($pokok === null) {
            return $this->masalah('Sisa pokok harus angka lebih dari nol — pinjaman yang sudah lunas tak perlu diimpor.');
        }

        return $this->periksaJadwal($baris, $pokok);
    }

    public function simpan(array $baris, array $param): array
    {
        $svc = new PinjamanKaryawanService;
        $idPengguna = Auth::user()?->id_pengguna;
        $jumlah = 0;

        foreach ($baris as $b) {
            $bukti = trim($b['nomor_bukti']);
            $ket = trim($b['keterangan'] ?? '');
            $pokok = $this->angka($b['sisa_pokok']);

            $svc->create([
                'kode_karyawan' => trim($b['kode_karyawan']),
                'tanggal' => $this->tanggal($b['tanggal']),
                'pokok' => $pokok,
                'kode_coa_piutang' => $param['kode_coa_piutang'],
                // Nomor bukti diletakkan di DEPAN keterangan: itulah penanda yang
                // membuat berkas boleh diunggah ulang tanpa menggandakan baris.
                'keterangan' => $bukti.($ket !== '' ? " — {$ket}" : ' — saldo awal pinjaman karyawan'),
                'termin' => $this->susunTermin($b, $pokok),
                // SENGAJA tidak dikirim: uangnya diserahkan sebelum pindah sistem.
                // 'posting_pencairan' => false,
            ], $idPengguna);
            $jumlah++;
        }

        return ['pinjaman' => $jumlah];
    }

    /**
     * Jadwal sisa cicilan bersifat pilihan; bila diisi, keduanya harus lengkap.
     * Σ termin wajib sama dengan pokok (dijaga servicenya), jadi pembagiannya
     * dibuat rata dan pembulatannya ditumpuk di termin terakhir.
     *
     * @return array{status:string,alasan:?string}
     */
    private function periksaJadwal(array $baris, string $pokok): array
    {
        $tenor = trim($baris['sisa_termin'] ?? '');
        $mulai = trim($baris['jatuh_tempo_pertama'] ?? '');

        if ($tenor === '' && $mulai === '') {
            return $this->siap();
        }
        if ($tenor === '') {
            return $this->masalah('Jatuh tempo pertama diisi tetapi sisa termin kosong — jadwalnya tak bisa disusun.');
        }
        if (! ctype_digit($tenor) || (int) $tenor < 1) {
            return $this->masalah('Sisa termin harus berupa angka bulat lebih dari nol.');
        }
        if (! $this->tanggalSah($mulai)) {
            return $this->masalah('Sisa termin diisi, jadi jatuh tempo pertama wajib diisi (format YYYY-MM-DD).');
        }
        if (! Money::gtZero(Money::div($pokok, $tenor))) {
            return $this->masalah("Sisa pokok {$pokok} dibagi {$tenor} termin menghasilkan cicilan nol.");
        }

        return $this->siap();
    }

    /**
     * Termin bulanan sejak jatuh tempo pertama. Pembagian memakai pemotongan
     * (bukan pembulatan), sehingga termin terakhir menyerap sisanya dan Σ termin
     * persis sama dengan pokok.
     *
     * @return list<array{nominal:string,jatuh_tempo:string}>
     */
    private function susunTermin(array $baris, string $pokok): array
    {
        $tenor = (int) trim($baris['sisa_termin'] ?? '');
        if ($tenor < 1) {
            return [];
        }

        $mulai = Carbon::parse(trim($baris['jatuh_tempo_pertama']));
        $per = Money::div($pokok, $tenor);

        $hasil = [];
        $terpakai = '0';
        for ($i = 0; $i < $tenor - 1; $i++) {
            $hasil[] = ['nominal' => $per, 'jatuh_tempo' => $mulai->copy()->addMonthsNoOverflow($i)->toDateString()];
            $terpakai = Money::add($terpakai, $per);
        }
        $hasil[] = [
            'nominal' => Money::sub($pokok, $terpakai),
            'jatuh_tempo' => $mulai->copy()->addMonthsNoOverflow($tenor - 1)->toDateString(),
        ];

        return $hasil;
    }
}
