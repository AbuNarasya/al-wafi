<?php

namespace App\Services\PortalWali;

use App\Models\NotifikasiWali;
use App\Services\Modules\NotificationService;
use App\Support\BahasaPortal;
use App\Support\Teks;

/**
 * Kabar kepada PENCATAT sebuah transaksi — staf atau wali.
 *
 * Penolakan oleh keuangan dulu selalu dikirim ke `dicatat_oleh` (staf). Sejak
 * transaksi bisa dicatat wali lewat portal, kolom itu bisa kosong, dan tabel
 * notifikasi staf menolak id kosong — penolakannya sendiri ikut gagal. Inilah
 * satu pintu yang memilih penerimanya.
 */
class KabarWali
{
    /**
     * Kabar ke SETIAP orang di keluarga yang bisa masuk portal (punya email) — untuk kabar milik keluarga, bukan
     * milik pencatat transaksi (tagihan terbit, pembayaran diverifikasi, mutasi dompet anak).
     *
     * `judul`/`pesan` boleh App\Support\Teks: disusun PER ORANG dalam bahasa pilihannya (portal tiga bahasa,
     * tahap 2) dan disimpan sudah jadi — push memakai teks yang sama. String biasa disimpan apa adanya.
     *
     * @param  array{judul:string|Teks, pesan:string|Teks, jenis:string, ref_jenis?:?string, ref_id?:?string, id_santri?:?int}  $isi
     */
    public static function keKeluarga(?\App\Models\Wali $wali, array $isi): void
    {
        if (! $wali) {
            return;
        }
        foreach (array_keys(\App\Models\Wali::PERAN) as $p) {
            if (filled($wali->{"email_{$p}"})) {
                $bahasa = self::bahasa($wali->id, $p);
                NotifikasiWali::create([
                    'id_wali' => $wali->id, 'peran' => $p, 'id_santri' => $isi['id_santri'] ?? null,
                    'judul' => mb_substr(Teks::jadi($isi['judul'], $bahasa), 0, 250), 'pesan' => Teks::jadi($isi['pesan'], $bahasa), 'jenis' => $isi['jenis'],
                    'ref_jenis' => $isi['ref_jenis'] ?? null, 'ref_id' => $isi['ref_id'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  object{dicatat_oleh:?int, dicatat_oleh_wali:?int, dicatat_oleh_peran:?string}  $transaksi
     * @param  array{judul:string|Teks, pesan:string|Teks, jenis:string, ref_jenis?:?string, ref_id?:?string, id_santri?:?int}  $isi
     */
    public static function kePencatat(object $transaksi, array $isi): void
    {
        if ($transaksi->dicatat_oleh_wali !== null) {
            $bahasa = self::bahasa((int) $transaksi->dicatat_oleh_wali, (string) $transaksi->dicatat_oleh_peran);
            NotifikasiWali::create([
                'id_wali' => $transaksi->dicatat_oleh_wali, 'peran' => $transaksi->dicatat_oleh_peran,
                'id_santri' => $isi['id_santri'] ?? null, 'judul' => mb_substr(Teks::jadi($isi['judul'], $bahasa), 0, 250),
                'pesan' => Teks::jadi($isi['pesan'], $bahasa),
                'jenis' => $isi['jenis'], 'ref_jenis' => $isi['ref_jenis'] ?? null, 'ref_id' => $isi['ref_id'] ?? null,
            ]);

            return;
        }
        if ($transaksi->dicatat_oleh !== null) {
            (new NotificationService)->kirim([[
                // Staf: selalu bahasa Indonesia.
                'id_pengguna' => $transaksi->dicatat_oleh, 'judul' => (string) $isi['judul'], 'pesan' => (string) $isi['pesan'],
                'jenis' => $isi['jenis'], 'ref_jenis' => $isi['ref_jenis'] ?? null, 'ref_id' => $isi['ref_id'] ?? null,
            ]]);
        }
    }

    /** Untuk pengirim yang menulis NotifikasiWali sendiri (mis. per orang dengan isi berbeda): teks dalam bahasa orang itu. */
    public static function teksUntuk(int $idWali, string $peran, string|Teks $teks): string
    {
        return Teks::jadi($teks, self::bahasa($idWali, $peran));
    }

    /** Bahasa portal pilihan satu orang; belum pernah memilih = Indonesia. */
    public static function bahasa(int $idWali, string $peran): string
    {
        $b = \Illuminate\Support\Facades\DB::table('bahasa_wali')->where('id_wali', $idWali)->where('peran', $peran)->value('bahasa');

        return isset(BahasaPortal::BAHASA[$b]) ? $b : BahasaPortal::BAWAAN;
    }
}
