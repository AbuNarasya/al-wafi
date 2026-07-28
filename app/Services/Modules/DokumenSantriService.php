<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DokumenSantri;
use App\Models\Santri;
use App\Services\Ppsb\DokumenPolicy;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Dokumen santri — metadata di DB, isi berkas di FileStorage (disk lokal di luar
 * webroot). Tahap diturunkan dari jenis. Baris & berkas dibuang bersama saat hapus.
 */
class DokumenSantriService
{
    private const DISK = 'local';
    private const DIR = 'dokumen-santri';

    public function list(int $idSantri)
    {
        return DokumenSantri::where('id_santri', $idSantri)
            ->orderBy('tahap')->orderBy('jenis')->orderByDesc('created_at')->get();
    }

    public function get(int $id): DokumenSantri
    {
        $row = DokumenSantri::find($id);
        if (! $row) {
            throw new AppException(404, 'Dokumen tidak ditemukan.');
        }

        return $row;
    }

    /** Unggah berkas + catat metadata. Tahap diturunkan dari jenis. */
    public function unggah(array $data, UploadedFile $berkas, int $idPengguna): DokumenSantri
    {
        $santri = Santri::find($data['id_santri']);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if (Tahap::isFinal($santri->status)) {
            throw new AppException(422, "Proses santri ini sudah berakhir dengan status \"{$santri->status}\"; berkas tidak bisa ditambahkan lagi.");
        }
        $izin = DokumenPolicy::bolehUnggah($data['jenis'], $santri->status);
        if (! $izin['boleh']) {
            throw new AppException(422, $izin['alasan']);
        }
        if ($data['jenis'] === 'lainnya' && empty($data['keterangan'])) {
            throw new AppException(422, 'Jenis "Dokumen Lainnya" wajib disertai keterangan isinya.');
        }

        $hash = hash_file('sha256', $berkas->getRealPath());
        $path = $berkas->store(self::DIR, self::DISK);

        try {
            return DokumenSantri::create([
                'id_santri' => $data['id_santri'], 'jenis' => $data['jenis'],
                'tahap' => DokumenPolicy::TAHAP_DOKUMEN[$data['jenis']], // diturunkan
                'nama_asli' => $berkas->getClientOriginalName(), 'path' => $path,
                'mime' => $berkas->getClientMimeType(), 'ukuran' => $berkas->getSize(),
                'hash_sha256' => $hash, 'keterangan' => $data['keterangan'] ?? null, 'diunggah_oleh' => $idPengguna,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path); // jangan tinggalkan sampah
            throw $e;
        }
    }

    /** Hapus berkas salah unggah (baris + berkas bersama). Selama proses belum berakhir. */
    public function hapus(int $id): void
    {
        $row = DokumenSantri::with('santri:id,status')->find($id);
        if (! $row) {
            throw new AppException(404, 'Dokumen tidak ditemukan.');
        }
        if (Tahap::isFinal($row->santri->status)) {
            throw new AppException(422, 'Proses santri ini sudah berakhir; berkasnya bagian dari riwayat dan tidak bisa dihapus.');
        }
        $path = $row->path;
        $row->delete();
        Storage::disk(self::DISK)->delete($path);
    }

    /** Ringkasan kelengkapan untuk layar verifikasi berkas. */
    public function kelengkapan(int $idSantri): array
    {
        $ada = DokumenSantri::where('id_santri', $idSantri)->pluck('jenis')->unique()->all();

        return array_map(fn ($jenis, $tahap) => [
            'jenis' => $jenis,
            'label' => DokumenPolicy::LABEL_DOKUMEN[$jenis],
            'tahap' => $tahap,
            'kelompok' => DokumenPolicy::KELOMPOK_DOKUMEN[$jenis] ?? $tahap,
            'ada' => in_array($jenis, $ada, true),
        ], array_keys(DokumenPolicy::TAHAP_DOKUMEN), array_values(DokumenPolicy::TAHAP_DOKUMEN));
    }
}
