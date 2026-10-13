<?php

namespace App\Support\Audit;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * JEJAK AUDIT — satu pintu penulisan `activity_log`.
 *
 * Dua lapis dipakai bersama:
 *
 *  1. LAPIS OTOMATIS — trait [[MencatatJejak]] pada model keuangan. Ia merekam
 *     created/updated/deleted berikut nilai LAMA → BARU tiap kolom. Tak bisa
 *     lupa dipasang karena bukan pemanggilan manual di tiap service.
 *
 *  2. LAPIS NIAT — `Jejak::catat()` untuk tindakan yang maknanya tak terbaca
 *     dari perubahan kolom: menutup/membuka periode, menjalankan depresiasi,
 *     menerbitkan tagihan massal, menyetujui permohonan. Di sinilah ALASAN
 *     dicatat, dan alasan itulah yang paling dicari saat sebuah angka
 *     dipersoalkan berbulan-bulan kemudian.
 *
 * Jejak tak pernah bisa dihapus dari dalam aplikasi: tak ada rute destroy, tak
 * ada tombol, dan kelas ini tak punya metode penghapus.
 */
final class Jejak
{
    /**
     * Catat satu tindakan.
     *
     * @param  string  $aksi  kata kerja pendek, snake_case (mis. `tutup_bulan`)
     * @param  array{
     *     modul?:?string, ref_jenis?:?string, ref_id?:string|int|null,
     *     detail?:array|string|null, perubahan?:?array, id_pengguna?:?int
     * }  $p
     */
    public static function catat(string $aksi, array $p = []): ?ActivityLog
    {
        $detail = $p['detail'] ?? null;

        return ActivityLog::create([
            'id_pengguna' => $p['id_pengguna'] ?? self::penggunaStaf(),
            'aksi' => $aksi,
            'modul' => $p['modul'] ?? null,
            'ref_jenis' => $p['ref_jenis'] ?? null,
            'ref_id' => isset($p['ref_id']) ? (string) $p['ref_id'] : null,
            'detail' => is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $detail,
            'perubahan' => $p['perubahan'] ?? null,
            ...self::asalPermintaan(),
        ]);
    }

    /**
     * Pengguna STAF pelaku tindakan, atau null.
     *
     * `activity_log.id_pengguna` ber-FK ke `users`. Dua jebakan sejak ada Portal Wali:
     *  • `Auth::id()` membaca guard BAWAAN — bila guard `wali` yang jadi bawaan,
     *    id WALI tercatat sebagai id staf. Insert-nya gagal karena FK (atau, lebih
     *    buruk, menunjuk staf yang kebetulan ber-id sama), dan karena galat jejak
     *    ditelan sementara PostgreSQL membatalkan transaksinya, seluruh pembayaran
     *    ikut gagal tanpa pesan yang jelas.
     *  • Staf yang juga membuka portal di browser yang sama: tindakan wali di
     *    portal akan tercatat atas nama staf itu.
     * Karena itu: guard `web` disebut tegas, dan permintaan dari portal tak pernah
     * dianggap tindakan staf — pelakunya ada di kolom `dicatat_oleh_wali` transaksinya.
     */
    private static function penggunaStaf(): ?int
    {
        try {
            if (request()->routeIs('portal.*')) {
                return null;
            }
        } catch (\Throwable) {
            // Tanpa permintaan HTTP (artisan, penjadwal) — lanjut ke guard web.
        }
        $id = Auth::guard('web')->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * IP & peramban pemohon. Dibungkus try/catch karena jejak juga ditulis dari
     * perintah artisan & penjadwal, yang tak punya permintaan HTTP sama sekali —
     * dan sebuah galat di pencatat tak boleh menggagalkan transaksi yang dicatat.
     */
    private static function asalPermintaan(): array
    {
        try {
            return [
                'ip' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255) ?: null,
            ];
        } catch (\Throwable) {
            return ['ip' => null, 'user_agent' => null];
        }
    }
}
