<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\SumberInformasi;

/**
 * Master Sumber Informasi PPSB. Tak ada alur program yang bercabang karenanya,
 * jadi barisnya bebas ditambah — yang dijaga hanya: baris yang sudah dipakai
 * santri tidak boleh dihapus (riwayat pendaftarannya ikut hilang artinya).
 */
class SumberInformasiService
{
    public function list()
    {
        return SumberInformasi::orderBy('urutan')->orderBy('kode')->get();
    }

    public function create(array $data): SumberInformasi
    {
        if (SumberInformasi::find($data['kode'])) {
            throw new AppException(409, "Kode sumber \"{$data['kode']}\" sudah dipakai.");
        }

        return SumberInformasi::create($this->bersih($data) + [
            'bawaan' => false,
            'urutan' => UrutanTampilService::berikutnya(SumberInformasi::class),
        ]);
    }

    public function update(string $kode, array $data): SumberInformasi
    {
        $lama = $this->cari($kode);
        // `urutan` sengaja TIDAK ikut: ia hanya berubah lewat seret/naik-turun di
        // halaman daftar. Kalau ikut disimpan dari form, menyunting nama saja
        // akan mengembalikan barisnya ke nomor 0.
        $lama->update($this->bersih($data));

        return $lama;
    }

    public function remove(string $kode): void
    {
        $sumber = $this->cari($kode);
        $dipakai = Santri::where('sumber_informasi', $kode)->count();
        if ($dipakai > 0) {
            throw new AppException(422, "Sumber \"{$sumber->nama}\" sudah dipilih {$dipakai} santri. Nonaktifkan saja agar tak lagi muncul di formulir, tanpa menghapus riwayatnya.");
        }
        SumberInformasi::destroy($kode);
    }

    private function cari(string $kode): SumberInformasi
    {
        $sumber = SumberInformasi::find($kode);
        if (! $sumber) {
            throw new AppException(404, 'Sumber informasi tidak ditemukan.');
        }

        return $sumber;
    }

    /** @return array<string,mixed> */
    private function bersih(array $data): array
    {
        return [
            'kode' => $data['kode'],
            'nama' => $data['nama'],
            'butuh_keterangan' => (bool) ($data['butuh_keterangan'] ?? false),
            'status' => $data['status'] ?? 'aktif',
        ];
    }
}
