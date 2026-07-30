<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JalurPendaftaran;
use App\Models\PotonganGelombang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\TargetSantri;
use App\Models\TarifBiaya;
use Illuminate\Support\Facades\DB;

/**
 * Master Tahun Ajaran. kode ("2026/2027") dirujuk sebagai string oleh
 * jenis_biaya, potongan_gelombang, target_santri, dan santri — karena itu kode
 * tak bisa diubah dan TA yang terpakai tak bisa dihapus. Maksimal satu TA aktif
 * menjadi default_pendaftaran.
 *
 * Jalur pendaftaran TIDAK termasuk: ia berlaku lintas tahun ajaran.
 */
class TahunAjaranService
{
    public function list()
    {
        return TahunAjaran::orderByDesc('kode')->get();
    }

    public function get(int $id): TahunAjaran
    {
        $row = TahunAjaran::find($id);
        if (! $row) {
            throw new AppException(404, 'Tahun ajaran tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): TahunAjaran
    {
        $this->periksaTanggal($data);

        return DB::transaction(function () use ($data) {
            $row = TahunAjaran::create($data);
            if ($row->default_pendaftaran) {
                $this->jadikanSatuSatunyaDefault($row);
            }

            return $row;
        });
    }

    public function update(int $id, array $data): TahunAjaran
    {
        $row = $this->get($id);
        unset($data['kode']); // kode dirujuk tabel lain — tidak boleh berubah
        $this->periksaTanggal($data);
        if (($data['status'] ?? $row->status) === 'nonaktif' && ($data['default_pendaftaran'] ?? $row->default_pendaftaran)) {
            throw new AppException(422, 'Tahun ajaran nonaktif tidak bisa menjadi default pendaftaran.');
        }

        return DB::transaction(function () use ($row, $data) {
            $row->update($data);
            if ($row->default_pendaftaran) {
                $this->jadikanSatuSatunyaDefault($row);
            }

            return $row->refresh();
        });
    }

    public function remove(int $id): void
    {
        $row = $this->get($id);
        $dipakai = [
            // Jenis biaya TIDAK lagi dihitung: sejak tarif pindah ke tabelnya
            // sendiri, jenis biaya hanya memegang akun dan berlaku lintas T.A.
            // Yang merujuk tahun ajaran sekarang adalah sel tarifnya.
            'sel tarif' => TarifBiaya::where('tahun_ajaran', $row->kode)->count(),
            // Jalur pendaftaran TIDAK dihitung: sejak 2026-07-28 jalur berlaku
            // lintas tahun ajaran, jadi tak pernah merujuk satu T.A.
            'potongan gelombang' => PotonganGelombang::where('tahun_ajaran', $row->kode)->count(),
            'target santri' => TargetSantri::where('tahun_ajaran', $row->kode)->count(),
            'santri' => Santri::where('tahun_ajaran', $row->kode)->count(),
        ];
        $ada = array_filter($dipakai);
        if ($ada !== []) {
            $rincian = implode(', ', array_map(fn ($n, $t) => "{$n} {$t}", $ada, array_keys($ada)));
            throw new AppException(409, "Tahun ajaran {$row->kode} masih dirujuk ({$rincian}). Nonaktifkan saja bila sudah tidak dipakai.");
        }
        $row->delete();
    }

    /** @return array<string,string> kode => kode, hanya TA aktif (untuk dropdown). */
    public function opsiAktif(): array
    {
        return TahunAjaran::where('status', 'aktif')->orderByDesc('kode')
            ->pluck('kode', 'kode')->all();
    }

    /** TA default form registrasi; fallback TA aktif terbaru. */
    public function defaultPendaftaran(): ?TahunAjaran
    {
        return TahunAjaran::where('status', 'aktif')->where('default_pendaftaran', true)->first()
            ?? TahunAjaran::where('status', 'aktif')->orderByDesc('kode')->first();
    }

    /** Pastikan kode TA ada & aktif; kembalikan barisnya. */
    public function pastikanAktif(string $kode): TahunAjaran
    {
        $row = TahunAjaran::where('kode', $kode)->first();
        if (! $row) {
            throw new AppException(422, "Tahun ajaran \"{$kode}\" tidak terdaftar. Tambahkan dulu di menu PPSB → Tahun Ajaran.");
        }
        if ($row->status !== 'aktif') {
            throw new AppException(422, "Tahun ajaran {$kode} berstatus nonaktif.");
        }

        return $row;
    }

    private function jadikanSatuSatunyaDefault(TahunAjaran $row): void
    {
        TahunAjaran::where('id', '!=', $row->id)->where('default_pendaftaran', true)
            ->update(['default_pendaftaran' => false]);
    }

    private function periksaTanggal(array $data): void
    {
        if (! empty($data['tanggal_mulai']) && ! empty($data['tanggal_selesai'])
            && $data['tanggal_selesai'] < $data['tanggal_mulai']) {
            throw new AppException(422, 'Tanggal selesai tidak boleh mendahului tanggal mulai.');
        }
    }
}
