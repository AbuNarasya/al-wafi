<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\TargetSantri;

/** Master target jumlah santri per Tahun Ajaran per jenjang. */
class TargetSantriService
{
    public function list()
    {
        return TargetSantri::with('jenjang')->orderByDesc('tahun_ajaran')->orderBy('kode_jenjang')->get();
    }

    public function create(array $data): TargetSantri
    {
        $bentrok = TargetSantri::where('tahun_ajaran', $data['tahun_ajaran'])->where('kode_jenjang', $data['kode_jenjang'])->first();
        if ($bentrok) {
            throw new AppException(409, "Target jenjang \"{$data['kode_jenjang']}\" untuk T.A {$data['tahun_ajaran']} sudah ada. Sunting baris itu.");
        }

        return TargetSantri::create($this->rapikan($data));
    }

    public function update(int $id, array $data): TargetSantri
    {
        $lama = TargetSantri::find($id);
        if (! $lama) {
            throw new AppException(404, 'Target tidak ditemukan.');
        }
        $lama->update($this->rapikan($data));

        return $lama;
    }

    /**
     * Total = L + P bila keduanya diisi, supaya angka di Dashboard PPSB tak
     * pernah bertentangan dengan rinciannya. Bila L/P dikosongkan, target
     * dianggap belum dirinci dan `target` dipakai apa adanya.
     */
    private function rapikan(array $data): array
    {
        $l = ($data['target_l'] ?? null) === '' ? null : $data['target_l'] ?? null;
        $p = ($data['target_p'] ?? null) === '' ? null : $data['target_p'] ?? null;

        $bersih = [
            'tahun_ajaran' => $data['tahun_ajaran'],
            'kode_jenjang' => $data['kode_jenjang'],
            'target' => $l !== null || $p !== null ? (int) $l + (int) $p : (int) ($data['target'] ?? 0),
            'target_l' => $l === null ? null : (int) $l,
            'target_p' => $p === null ? null : (int) $p,
            'keterangan' => $data['keterangan'] ?? null,
        ];

        return $bersih;
    }

    public function remove(int $id): void
    {
        if (! TargetSantri::find($id)) {
            throw new AppException(404, 'Target tidak ditemukan.');
        }
        TargetSantri::destroy($id);
    }
}
