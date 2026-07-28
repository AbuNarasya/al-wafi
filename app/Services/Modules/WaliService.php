<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\Wali;

/**
 * Master Wali (satu wali = satu keluarga). `nama` & `telepon` adalah SALINAN
 * kontak utama (bukan isian tersendiri) — telepon = identitas login portal, unik.
 */
class WaliService
{
    /** Salin nama & telepon dari kontak utama terpilih. */
    private function salinKontakUtama(array $data): array
    {
        $peran = $data['kontak_utama'] ?? 'ayah';
        $nama = $data["nama_{$peran}"] ?? null;
        $telepon = $data["telepon_{$peran}"] ?? null;
        if (! $nama || ! $telepon) {
            throw new AppException(422, 'Kontak utama belum lengkap (nama & telepon wajib diisi).');
        }

        return ['nama' => $nama, 'telepon' => $telepon];
    }

    private function assertTeleponBelumDipakai(string $telepon, ?int $kecualiId): void
    {
        $bentrok = Wali::where('telepon', $telepon)->first();
        if ($bentrok && $bentrok->id !== $kecualiId) {
            throw new AppException(409, "Nomor {$telepon} sudah dipakai wali \"{$bentrok->nama}\". Bila ini keluarga yang sama (kakak-adik), pakai data wali itu — jangan buat wali baru.");
        }
    }

    public function list(?string $q = null)
    {
        return Wali::query()
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                foreach (['nama', 'telepon', 'nama_ayah', 'nama_ibu', 'nama_wali'] as $col) {
                    $w->orWhere($col, 'ilike', "%{$q}%");
                }
            }))
            ->withCount('santri')
            ->orderBy('nama')
            ->get();
    }

    public function get(int $id): Wali
    {
        $row = Wali::with(['santri' => fn ($q) => $q->orderBy('nama')])->find($id);
        if (! $row) {
            throw new AppException(404, 'Wali tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): Wali
    {
        $salinan = $this->salinKontakUtama($data);
        $this->assertTeleponBelumDipakai($salinan['telepon'], null);

        return Wali::create(array_merge($data, $salinan));
    }

    public function update(int $id, array $data): Wali
    {
        $lama = Wali::find($id);
        if (! $lama) {
            throw new AppException(404, 'Wali tidak ditemukan.');
        }
        // Aturan kontak utama diuji atas hasil GABUNGAN (update parsial).
        $gabungan = array_merge($lama->toArray(), $data);
        $salinan = $this->salinKontakUtama($gabungan);
        $this->assertTeleponBelumDipakai($salinan['telepon'], $id);

        $lama->update(array_merge($data, $salinan));

        return $lama;
    }

    public function remove(int $id): void
    {
        $jumlah = Santri::where('id_wali', $id)->count();
        if ($jumlah > 0) {
            throw new AppException(422, "Wali ini masih menaungi {$jumlah} santri/calon santri. Hapus atau pindahkan santrinya lebih dulu, atau nonaktifkan walinya.");
        }
        Wali::destroy($id);
    }
}
