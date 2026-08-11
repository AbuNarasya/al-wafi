<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\LampiranDokumen;
use App\Support\SumberLampiran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * LAMPIRAN DOKUMEN — berkas pendukung dokumen keuangan (invoice, penawaran,
 * nota, kwitansi, bukti transfer).
 *
 * Metadata di DB, isi berkas di disk yang disebut `config('lampiran.disk')`.
 * Nama disknya IKUT DISIMPAN di barisnya: mengganti setelan tidak membuat
 * berkas lama tak terbaca.
 *
 * Aturan siapa boleh apa TIDAK ada di sini — ada di [[SumberLampiran]], supaya
 * modul berikutnya yang butuh lampiran cukup menambah satu baris di sana.
 */
class LampiranService
{
    private function disk(): string
    {
        return (string) config('lampiran.disk', 'local');
    }

    /** @return Collection<int,LampiranDokumen> */
    public function daftar(string $jenis, string $idDokumen): Collection
    {
        return LampiranDokumen::with('pengunggah:id_pengguna,nama')
            ->where('jenis_dokumen', $jenis)
            ->where('id_dokumen', $idDokumen)
            ->orderBy('created_at')->orderBy('id')
            ->get();
    }

    public function jumlah(string $jenis, string $idDokumen): int
    {
        return LampiranDokumen::where('jenis_dokumen', $jenis)
            ->where('id_dokumen', $idDokumen)->count();
    }

    /**
     * Unggah satu berkas. Dokumen induknya harus ada DAN masih terbuka —
     * pemeriksaan hak akses modulnya dilakukan controller.
     */
    public function unggah(string $jenis, string $idDokumen, UploadedFile $berkas, int $idPengguna, ?string $keterangan = null): LampiranDokumen
    {
        $dokumen = SumberLampiran::dokumen($jenis, $idDokumen);
        if (! $dokumen) {
            throw new AppException(404, SumberLampiran::label($jenis).' tidak ditemukan.');
        }
        if (! SumberLampiran::terbuka($jenis, $dokumen)) {
            throw new AppException(422, sprintf(
                'Dokumen ini berstatus "%s"; lampirannya sudah menjadi bagian bukti pembukuan dan tidak bisa ditambah lagi.',
                $dokumen->status,
            ));
        }

        $disk = $this->disk();
        $hash = hash_file('sha256', $berkas->getRealPath());
        $path = $berkas->store((string) config('lampiran.folder', 'lampiran-dokumen'), $disk);

        try {
            return LampiranDokumen::create([
                'jenis_dokumen' => $jenis,
                'id_dokumen' => $idDokumen,
                'nama_asli' => $berkas->getClientOriginalName(),
                'path' => $path,
                'disk' => $disk,
                'mime' => $berkas->getClientMimeType(),
                'ukuran' => $berkas->getSize(),
                'hash_sha256' => $hash,
                'keterangan' => $keterangan ?: null,
                'diunggah_oleh' => $idPengguna,
            ]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); // jangan tinggalkan sampah
            throw $e;
        }
    }

    /**
     * Unggah beberapa berkas sekaligus (isian form "Buat …"). Berkas yang gagal
     * TIDAK menggagalkan sisanya — dokumennya sendiri sudah terbit, dan
     * menggagalkan seluruh pengajuan gara-gara satu lampiran justru memaksa
     * pemohon mengetik ulang segalanya.
     *
     * @param  list<UploadedFile|null>  $berkasList
     * @return array{berhasil:int,gagal:list<string>}
     */
    public function unggahBanyak(string $jenis, string $idDokumen, array $berkasList, int $idPengguna): array
    {
        $berhasil = 0;
        $gagal = [];

        foreach (array_filter($berkasList) as $berkas) {
            try {
                $this->unggah($jenis, $idDokumen, $berkas, $idPengguna);
                $berhasil++;
            } catch (\Throwable $e) {
                $gagal[] = $berkas->getClientOriginalName();
            }
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    /** Hapus satu lampiran (baris + berkas bersama). */
    public function hapus(int $id): LampiranDokumen
    {
        $row = LampiranDokumen::find($id);
        if (! $row) {
            throw new AppException(404, 'Lampiran tidak ditemukan.');
        }

        $dokumen = SumberLampiran::dokumen($row->jenis_dokumen, $row->id_dokumen);
        if (! SumberLampiran::terbuka($row->jenis_dokumen, $dokumen)) {
            throw new AppException(422, 'Dokumen ini sudah terkunci; lampirannya bagian dari bukti pembukuan dan tidak bisa dihapus.');
        }

        $this->buangBerkas($row);

        return $row;
    }

    /**
     * Buang SELURUH lampiran sebuah dokumen — dipanggil saat dokumen induknya
     * benar-benar dihapus dari database. Tabel ini polimorfik, jadi tak ada
     * cascade dari kunci asing yang mengerjakannya.
     */
    public function hapusMilik(string $jenis, string $idDokumen): int
    {
        $rows = LampiranDokumen::where('jenis_dokumen', $jenis)->where('id_dokumen', $idDokumen)->get();
        foreach ($rows as $row) {
            $this->buangBerkas($row);
        }

        return $rows->count();
    }

    /** Baris dibuang dulu, berkasnya menyusul: berkas yatim lebih baik daripada baris yatim. */
    private function buangBerkas(LampiranDokumen $row): void
    {
        $disk = $row->disk ?: 'local';
        $path = $row->path;
        $row->delete();
        Storage::disk($disk)->delete($path);
    }
}
