<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PersetujuanTerm;
use App\Models\TermTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SURAT PERNYATAAN WALI — teks berversi yang disetujui wali saat mendaftar di portal.
 *
 * Tiga aturan:
 *  • nomor VERSI bertambah sendiri, tak diketik petugas;
 *  • tepat SATU versi aktif — itulah yang tampil kepada wali. Mengaktifkan
 *    satu versi menonaktifkan yang lain dalam transaksi yang sama;
 *  • versi yang SUDAH disetujui seorang wali TERKUNCI: judul, isi, dan tanggal
 *    berlakunya tak bisa diubah, dan versinya tak bisa dihapus. Persetujuan
 *    memang menyimpan salinan teksnya sendiri, tapi tanpa kunci ini "versi 2"
 *    bisa berarti dua teks berbeda tergantung kapan seseorang membacanya.
 *    Mengubah isinya berarti membuat versi baru.
 */
class SuratPernyataanService
{
    /** @return Collection<int,TermTemplate> */
    public function list(): Collection
    {
        return TermTemplate::withCount('persetujuan')->orderByDesc('versi')->get();
    }

    /** Versi yang tampil kepada wali, atau null bila belum ada yang aktif. */
    public static function aktif(): ?TermTemplate
    {
        return TermTemplate::where('status', 'aktif')->orderByDesc('versi')->first();
    }

    public function create(array $data, int $idPengguna): TermTemplate
    {
        return DB::transaction(function () use ($data, $idPengguna) {
            // Dikunci supaya dua petugas yang menyimpan bersamaan tak berebut nomor yang sama.
            DB::statement('LOCK TABLE term_template IN SHARE ROW EXCLUSIVE MODE');
            $versi = (int) TermTemplate::max('versi') + 1;

            $baru = TermTemplate::create($this->bersih($data) + [
                'versi' => $versi,
                // Versi pertama langsung aktif: tanpa itu portal tak punya surat sama sekali.
                'status' => TermTemplate::where('status', 'aktif')->exists() ? 'nonaktif' : 'aktif',
                'dibuat_oleh' => $idPengguna,
            ]);

            return $baru;
        });
    }

    public function update(int $id, array $data): TermTemplate
    {
        $surat = $this->cari($id);
        $this->pastikanBelumDipakai($surat, 'diubah');
        $surat->update($this->bersih($data));

        return $surat;
    }

    public function aktifkan(int $id): TermTemplate
    {
        $surat = $this->cari($id);

        DB::transaction(function () use ($surat) {
            TermTemplate::where('id', '!=', $surat->id)->where('status', 'aktif')->update(['status' => 'nonaktif']);
            $surat->update(['status' => 'aktif']);
        });

        return $surat;
    }

    public function remove(int $id): void
    {
        $surat = $this->cari($id);
        $this->pastikanBelumDipakai($surat, 'dihapus');
        if ($surat->status === 'aktif') {
            throw new AppException(422, "Versi {$surat->versi} sedang aktif. Aktifkan versi lain dulu, baru hapus yang ini.");
        }
        $surat->delete();
    }

    public static function dipakai(TermTemplate $surat): int
    {
        return $surat->persetujuan_count ?? PersetujuanTerm::where('id_term_template', $surat->id)->count();
    }

    private function pastikanBelumDipakai(TermTemplate $surat, string $aksi): void
    {
        $jumlah = self::dipakai($surat);
        if ($jumlah > 0) {
            throw new AppException(422, "Versi {$surat->versi} sudah disetujui {$jumlah} wali, jadi tak bisa {$aksi}. "
                .'Buat versi baru bila isinya perlu diganti.');
        }
    }

    private function cari(int $id): TermTemplate
    {
        $surat = TermTemplate::find($id);
        if (! $surat) {
            throw new AppException(404, 'Surat pernyataan tidak ditemukan.');
        }

        return $surat;
    }

    /** @return array<string,mixed> */
    private function bersih(array $data): array
    {
        return [
            'judul' => trim($data['judul']),
            // Baris baru Windows diseragamkan: teksnya akan di-hash saat disetujui wali.
            'isi' => trim(str_replace("\r\n", "\n", $data['isi'])),
            'berlaku_mulai' => $data['berlaku_mulai'],
        ];
    }
}
