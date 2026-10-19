<?php

namespace App\Services\Impor\Pemeta;

use App\Models\ImporBatch;
use App\Models\KartuSantri;
use App\Models\Santri;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Services\Impor\PemetaBisaDibatalkan;
use App\Services\Modules\KartuSantriService;
use Illuminate\Support\Facades\Auth;

/**
 * KARTU RFID SANTRI — ekspor UID dari sistem absensi lama (NIS ↔ UID) sekali jalan.
 *
 * Kartu fisiknya sudah dipegang santri; yang dipindah hanya pasangan nomornya. Santri yang
 * SUDAH berkartu aktif lain ditolak (gantinya lewat layar Kartu Santri, supaya kartu lama
 * terblokir dengan sadar), dan pasangan yang sama persis dilewati — berkas boleh diulang.
 */
class PemetaKartuSantri implements Pemeta, PemetaBisaDibatalkan
{
    use BantuanPemeta;

    public static function kunci(): string
    {
        return 'kartu-santri';
    }

    public static function judul(): string
    {
        return 'Kartu Santri (RFID)';
    }

    public static function penjelasan(): string
    {
        return 'Memasang kartu RFID ke santri aktif dari daftar NIS & UID (ekspor sistem absensi). '
            .'Batas belanja harian & PIN belanja anak diatur wali dari portal.';
    }

    public function kolom(): array
    {
        return [
            'nis' => ['wajib' => true, 'contoh' => '242501001', 'ket' => 'NIS santri aktif.'],
            'uid' => ['wajib' => true, 'contoh' => '0012345678', 'ket' => 'UID kartu persis seperti diketik pembaca kartu. FORMAT KOLOM SEBAGAI TEKS (nol di depan).'],
        ];
    }

    public function parameter(): array
    {
        return [];
    }

    public function periksaParameter(array $param): ?string
    {
        return null;
    }

    public function kolomUnik(): array
    {
        return ['nis', 'uid'];
    }

    public function periksa(array $baris, array $param): array
    {
        $uid = KartuSantriService::bersihkanUid($baris['uid'] ?? '');
        if ($uid === null) {
            return $this->masalah('UID kosong atau bukan UID yang sah.');
        }
        $santri = Santri::where('nis', trim($baris['nis'] ?? ''))->first();
        if (! $santri) {
            return $this->masalah('NIS "'.trim($baris['nis'] ?? '').'" tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            return $this->masalah("{$santri->nama} bukan santri aktif.");
        }

        $ada = KartuSantri::with('santri:id,nama')->where('uid', $uid)->first();
        if ($ada && $ada->id_santri === $santri->id && $ada->status === 'aktif') {
            return $this->lewati();
        }
        if ($ada) {
            return $this->masalah($ada->id_santri === $santri->id
                ? 'Kartu ini milik santri yang sama tetapi sedang diblokir — buka blokirnya di layar Kartu Santri.'
                : "UID ini sudah terdaftar atas nama {$ada->santri?->nama}.");
        }
        if (KartuSantri::where('id_santri', $santri->id)->where('status', 'aktif')->exists()) {
            return $this->masalah("{$santri->nama} sudah punya kartu aktif lain — ganti lewat layar Kartu Santri.");
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $svc = new KartuSantriService;
        $n = 0;
        foreach ($baris as $b) {
            $santri = Santri::where('nis', trim($b['nis']))->first();
            $svc->pasang($santri->id, $b['uid'], Auth::user()?->id_pengguna, $param['id_batch'] ?? null);
            $n++;
        }

        return ['kartu' => $n];
    }

    public function halanganBatal(ImporBatch $batch): array
    {
        $kartu = KartuSantri::where('id_batch', $batch->id)->pluck('uid');
        if ($kartu->isEmpty()) {
            return ['Tidak ada kartu dari batch ini yang tersisa.'];
        }
        $dipakai = \Illuminate\Support\Facades\Schema::hasColumn('penjualan', 'uid_kartu')
            ? \App\Models\Penjualan::whereIn('uid_kartu', $kartu)->count() : 0;

        return $dipakai > 0 ? ["{$dipakai} struk belanja sudah memakai kartu dari batch ini."] : [];
    }

    public function batalkan(ImporBatch $batch, ?int $idPengguna): void
    {
        KartuSantri::where('id_batch', $batch->id)->delete();
    }
}
