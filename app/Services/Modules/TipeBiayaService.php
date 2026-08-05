<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\TipeBiaya;

/**
 * Master Tipe Biaya.
 *
 * Baris BAWAAN (registrasi, uang pangkal, SPP, lain-lain) dilindungi: kode &
 * perilakunya tak boleh berubah dan barisnya tak boleh dihapus, karena seluruh
 * alur PPSB/Kesantrian bersandar pada keempat perilaku itu. Namanya, urutannya,
 * dan keterangannya tetap bebas disunting.
 */
class TipeBiayaService
{
    public function list()
    {
        return TipeBiaya::orderBy('urutan')->orderBy('kode')->get();
    }

    public function create(array $data): TipeBiaya
    {
        if (TipeBiaya::find($data['kode'])) {
            throw new AppException(409, "Kode tipe \"{$data['kode']}\" sudah dipakai.");
        }

        return TipeBiaya::create($this->bersih($data) + [
            'bawaan' => false,
            'urutan' => UrutanTampilService::berikutnya(TipeBiaya::class),
        ]);
    }

    public function update(string $kode, array $data): TipeBiaya
    {
        $lama = $this->cari($kode);
        $bersih = $this->bersih($data);

        if ($lama->bawaan && $bersih['perilaku'] !== $lama->perilaku) {
            throw new AppException(422, "Perilaku tipe bawaan \"{$lama->nama}\" tak bisa diubah — alur registrasi/uang pangkal/SPP/lain-lain bersandar padanya.");
        }
        if ($lama->bawaan && ($bersih['status'] ?? 'aktif') !== 'aktif') {
            throw new AppException(422, "Tipe bawaan \"{$lama->nama}\" tak bisa dinonaktifkan; ia dipakai alur bawaan aplikasi.");
        }
        $lama->update($bersih);

        return $lama;
    }

    public function remove(string $kode): void
    {
        $tipe = $this->cari($kode);
        if ($tipe->bawaan) {
            throw new AppException(422, "Tipe bawaan \"{$tipe->nama}\" tak bisa dihapus.");
        }
        $dipakai = JenisBiaya::where('tipe', $kode)->count();
        if ($dipakai > 0) {
            throw new AppException(422, "Tipe ini dipakai {$dipakai} jenis biaya. Ubah jenis biaya itu dulu, atau nonaktifkan tipenya.");
        }

        TipeBiaya::destroy($kode);
        TipeBiaya::lupakan();
    }

    private function cari(string $kode): TipeBiaya
    {
        $tipe = TipeBiaya::find($kode);
        if (! $tipe) {
            throw new AppException(404, 'Tipe biaya tidak ditemukan.');
        }

        return $tipe;
    }

    /** @return array<string,mixed> */
    private function bersih(array $data): array
    {
        if (! array_key_exists($data['perilaku'] ?? '', TipeBiaya::PERILAKU)) {
            throw new AppException(422, 'Perilaku tipe biaya tidak dikenal.');
        }

        // `urutan` tidak ada di sini: ia hanya berubah lewat seret/naik-turun di
        // halaman daftar, bukan dari form sunting.
        return [
            'kode' => $data['kode'],
            'nama' => $data['nama'],
            'perilaku' => $data['perilaku'],
            'keterangan' => $data['keterangan'] ?? null,
            'status' => $data['status'] ?? 'aktif',
        ];
    }
}
