<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\JenisBiaya;
use App\Models\PotonganGelombang;
use App\Models\Santri;
use App\Models\TargetSantri;

/**
 * Master jenjang pendidikan. `kode` dirujuk sebagai string oleh tabel lain →
 * tak bisa diubah, dan jenjang yang masih dipakai tak boleh dihapus
 * (nonaktifkan saja agar riwayat data lama tetap utuh).
 */
class JenjangService
{
    public function list()
    {
        return Jenjang::orderBy('urutan')->orderBy('kode')->get();
    }

    public function get(string $kode): Jenjang
    {
        $row = Jenjang::find($kode);
        if (! $row) {
            throw new AppException(404, 'Jenjang tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): Jenjang
    {
        if (Jenjang::find($data['kode'])) {
            throw new AppException(409, "Jenjang berkode \"{$data['kode']}\" sudah ada. Sunting baris itu.");
        }

        // CATATAN: daftar kolom di sini ditulis eksplisit, jadi setiap kolom BARU
        // wajib ditambahkan di create() DAN update() — kalau terlewat, formnya
        // tampak menyimpan padahal nilainya dibuang diam-diam.
        return Jenjang::create([
            'kode' => $data['kode'],
            'nama' => $data['nama'],
            'jumlah_tingkat' => $data['jumlah_tingkat'] ?? null,
            'tingkat_mulai' => $data['tingkat_mulai'] ?? null,
            'kode_jenjang_lanjutan' => $data['kode_jenjang_lanjutan'] ?? null,
            'urutan' => $data['urutan'] ?? UrutanTampilService::berikutnya(Jenjang::class),
            'status' => $data['status'] ?? 'aktif',
            'keterangan' => $data['keterangan'] ?? null,
        ]);
    }

    public function update(string $kode, array $data): Jenjang
    {
        $row = $this->get($kode);
        $row->update([
            'nama' => $data['nama'] ?? $row->nama,
            'jumlah_tingkat' => array_key_exists('jumlah_tingkat', $data) ? $data['jumlah_tingkat'] : $row->jumlah_tingkat,
            'tingkat_mulai' => array_key_exists('tingkat_mulai', $data) ? $data['tingkat_mulai'] : $row->tingkat_mulai,
            'kode_jenjang_lanjutan' => array_key_exists('kode_jenjang_lanjutan', $data)
                ? ($data['kode_jenjang_lanjutan'] ?: null) : $row->kode_jenjang_lanjutan,
            'urutan' => $data['urutan'] ?? $row->urutan,
            'status' => $data['status'] ?? $row->status,
            'keterangan' => array_key_exists('keterangan', $data) ? $data['keterangan'] : $row->keterangan,
        ]);

        return $row;
    }

    public function remove(string $kode): void
    {
        $row = $this->get($kode);
        $dipakai = array_filter([
            'santri' => Santri::where('kode_jenjang', $kode)->count(),
            'jenis biaya' => JenisBiaya::where('kode_jenjang', $kode)->count(),
            'potongan gelombang' => PotonganGelombang::where('kode_jenjang', $kode)->count(),
            'target santri' => TargetSantri::where('kode_jenjang', $kode)->count(),
        ]);
        if ($dipakai !== []) {
            $rincian = implode(', ', array_map(fn ($n, $t) => "{$n} {$t}", $dipakai, array_keys($dipakai)));
            throw new AppException(409, "Jenjang {$kode} masih dipakai ({$rincian}). Nonaktifkan saja bila sudah tidak dipakai lagi.");
        }
        $row->delete();
    }
}
