<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JalurPendaftaran;
use App\Models\Santri;

/**
 * MASTER Jalur Pendaftaran. Nilai `kode` dipakai santri.jalur. Jalur yang sudah
 * dipakai TIDAK boleh dihapus (nonaktifkan) agar tak ada santri yang jalurnya
 * menggantung tanpa master. Port dari branch dev app asli.
 */
class JalurPendaftaranService
{
    public function list()
    {
        return JalurPendaftaran::orderBy('nama')->get();
    }

    public function create(array $data): JalurPendaftaran
    {
        if (JalurPendaftaran::find($data['kode'])) {
            throw new AppException(409, "Jalur berkode \"{$data['kode']}\" sudah ada. Sunting baris itu.");
        }

        // Tanpa tahun_ajaran: jalur berlaku lintas T.A (lihat migration
        // jalur_pendaftaran_lintas_tahun_ajaran).
        return JalurPendaftaran::create([
            'kode' => $data['kode'],
            'nama' => $data['nama'],
            'keterangan' => $data['keterangan'] ?? null,
            'status' => $data['status'] ?? 'aktif',
        ]);
    }

    public function update(string $kode, array $data): JalurPendaftaran
    {
        $lama = JalurPendaftaran::find($kode);
        if (! $lama) {
            throw new AppException(404, 'Jalur tidak ditemukan.');
        }
        $lama->update([
            'nama' => $data['nama'] ?? $lama->nama,
            'keterangan' => array_key_exists('keterangan', $data) ? $data['keterangan'] : $lama->keterangan,
            'status' => $data['status'] ?? $lama->status,
        ]);

        return $lama;
    }

    public function remove(string $kode): void
    {
        $dipakai = Santri::where('jalur', $kode)->count();
        if ($dipakai > 0) {
            throw new AppException(409, "Jalur \"{$kode}\" masih dipakai {$dipakai} santri — nonaktifkan saja, jangan dihapus.");
        }
        $jalur = JalurPendaftaran::find($kode);
        if (! $jalur) {
            throw new AppException(404, 'Jalur tidak ditemukan.');
        }
        $jalur->delete();
    }
}
