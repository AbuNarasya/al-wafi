<?php

namespace App\Services\Impor\Pemeta;

use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use Illuminate\Support\Facades\Auth;

/**
 * PENGAJUAN PEMBAYARAN yang sudah disetujui & diposting tetapi BELUM dibayar
 * saat pindah sistem — hutang kepada pemohon/rekanan yang menunggu Kas Keluar.
 *
 * Dokumennya ditulis LANGSUNG, tidak lewat PengajuanPembayaranService, karena
 * jalur normalnya menuntut pengaju berperingkat Staff, melewati rantai
 * persetujuan, lalu diverifikasi keuangan — dan langkah verifikasi itulah yang
 * menerbitkan jurnal. Semua itu sudah terjadi di sistem lama; mengulanginya
 * berarti mengarang beban dan hutang baru.
 *
 * Karena itu di sini: status langsung `diposting`, tanpa instance approval,
 * tanpa jurnal. Saldo hutangnya masuk lewat jurnal pembuka (menu Saldo Awal).
 *
 * Statusnya sengaja `diposting` — itu satu-satunya status yang diterima
 * `applyPayment()`, sehingga Kas Keluar bisa melunasinya seperti biasa.
 * Pelunasannya WAJIB PENUH, sama seperti pengajuan biasa.
 */
class PemetaPengajuanBelumDibayar implements Pemeta
{
    use BantuanPemeta;

    public static function kunci(): string
    {
        return 'pengajuan-belum-dibayar';
    }

    public static function judul(): string
    {
        return 'Pengajuan Belum Dibayar';
    }

    public static function penjelasan(): string
    {
        return 'Pengajuan pembayaran yang sudah disetujui tetapi belum dicairkan. Dicatat '
            .'berstatus diposting tanpa rantai persetujuan dan tanpa jurnal, agar bisa langsung '
            .'dilunasi lewat Kas Keluar. Saldo hutangnya masuk lewat jurnal pembuka.';
    }

    public function kolom(): array
    {
        return [
            'nomor' => ['wajib' => true, 'contoh' => 'PB/2026/0088', 'ket' => 'Nomor pengajuan asli. Harus unik; dipakai mengenali baris yang sudah masuk.'],
            'tanggal' => ['wajib' => true, 'contoh' => '2026-08-20', 'ket' => 'Tanggal pengajuan aslinya.'],
            'kode_bagian' => ['wajib' => true, 'contoh' => 'KEU', 'ket' => 'Harus ada di master Bagian.'],
            'sisa_hutang' => ['wajib' => true, 'contoh' => '7500000', 'ket' => 'Sisa yang belum dibayar. Pelunasan nanti harus PENUH sebesar ini.'],
            'kode_coa' => ['wajib' => true, 'contoh' => '5.1.02.001', 'ket' => 'Akun beban/aset yang diajukan. Hanya keterangan — tidak menerbitkan jurnal.'],
            'kode_unit' => ['wajib' => true, 'contoh' => 'U001', 'ket' => 'Unit penanggung; menentukan laba rugi unit mana yang memikulnya.'],
            'keterangan' => ['wajib' => true, 'contoh' => 'Pembelian ATK Agustus', 'ket' => ''],
            'username_pemohon' => ['wajib' => false, 'contoh' => 'staff1', 'ket' => 'Bila diisi & dikenal, dicatat sebagai pemohon. Kosong = pengguna yang mengimpor.'],
        ];
    }

    public function parameter(): array
    {
        return [
            'kode_coa_hutang' => [
                'label' => 'Akun hutang pengajuan',
                'tipe' => 'pilih',
                'opsi' => CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
                    ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
                'ket' => 'Akun liabilitas yang akan didebit saat Kas Keluar melunasinya.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        $kode = trim($param['kode_coa_hutang'] ?? '');
        if ($kode === '') {
            return 'Akun hutang pengajuan belum dipilih.';
        }
        if (! CoaDetail::find($kode)) {
            return 'Akun hutang pengajuan tidak ditemukan.';
        }

        return null;
    }

    public function periksa(array $baris, array $param): array
    {
        $nomor = trim($baris['nomor'] ?? '');
        if ($nomor === '') {
            return $this->masalah('Nomor pengajuan kosong.');
        }
        if (PengajuanPembayaran::where('nomor', $nomor)->exists()) {
            return $this->lewati();
        }
        if (! $this->tanggalSah($baris['tanggal'] ?? null)) {
            return $this->masalah('Tanggal kosong atau tidak terbaca (format YYYY-MM-DD).');
        }

        $bagian = trim($baris['kode_bagian'] ?? '');
        if (! Bagian::whereKey($bagian)->exists()) {
            return $this->masalah("Bagian \"{$bagian}\" tidak ada di master Bagian.");
        }

        $coa = trim($baris['kode_coa'] ?? '');
        if (! CoaDetail::find($coa)) {
            return $this->masalah("Akun \"{$coa}\" tidak ada di Chart of Account.");
        }

        $unit = trim($baris['kode_unit'] ?? '');
        if (! BusinessUnit::find($unit)) {
            return $this->masalah("Unit \"{$unit}\" tidak ada di master Unit Bisnis.");
        }

        if (trim($baris['keterangan'] ?? '') === '') {
            return $this->masalah('Keterangan kosong.');
        }
        if ($this->angkaPositif($baris['sisa_hutang'] ?? null) === null) {
            return $this->masalah('Sisa hutang harus angka lebih dari nol — yang sudah lunas tak perlu diimpor.');
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $idPengimpor = Auth::user()?->id_pengguna;
        $jumlah = 0;

        foreach ($baris as $b) {
            $nominal = $this->angka($b['sisa_hutang']);
            $coa = CoaDetail::find(trim($b['kode_coa']));

            $pemohon = trim($b['username_pemohon'] ?? '') !== ''
                ? User::where('username', trim($b['username_pemohon']))->value('id_pengguna')
                : null;

            $rec = PengajuanPembayaran::create([
                'nomor' => trim($b['nomor']),
                'tanggal' => $this->tanggal($b['tanggal']),
                'jenis' => 'pembayaran',
                'kode_bagian' => trim($b['kode_bagian']),
                'kode_coa_hutang' => $param['kode_coa_hutang'],
                'nominal' => $nominal,
                'sisa_hutang' => $nominal,
                'keterangan' => trim($b['keterangan']),
                // Satu-satunya status yang diterima applyPayment(); tanpa ini
                // Kas Keluar akan menolak melunasinya.
                'status' => 'diposting',
                'id_pengguna' => $pemohon ?? $idPengimpor,
            ]);

            $rec->details()->create([
                'kode_coa' => $coa->kode_coa,
                'nama_coa' => $coa->nama_coa,
                'nominal' => $nominal,
                'kode_unit' => trim($b['kode_unit']),
                'keterangan' => trim($b['keterangan']),
            ]);
            $jumlah++;
        }

        return ['pengajuan' => $jumlah];
    }
}
