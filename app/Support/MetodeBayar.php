<?php

namespace App\Support;

use App\Exceptions\AppException;
use App\Services\Midtrans\PembayaranMidtrans;
use App\Support\Audit\Jejak;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar metode pembayaran portal wali — tabel `pengaturan_metode_bayar` (satu baris).
 *
 * Dompet Wali SELALU aktif dan tak punya saklar. Saklar hanya menahan pesanan BARU; yang sudah berjalan
 * (VA terbit, bukti menunggu verifikasi, notifikasi bank) tetap diproses. Ditegakkan di SERVER
 * (pastikan()), bukan sekadar disembunyikan dari layar.
 *
 * Tak memakai simpanan statis: dibaca ulang tiap panggilan (satu baris, beberapa kali per halaman) supaya
 * perubahan saklar langsung berlaku tanpa menunggu apa pun.
 */
final class MetodeBayar
{
    /** kode => label. Menambah metode (mis. VA BSI) = kolom baru + baris di sini. */
    public const METODE = [
        'midtrans' => 'Online (VA Midtrans)',
        'transfer' => 'Transfer + bukti',
    ];

    public const MODUL = 'metode-pembayaran';

    private static function baris(): ?object
    {
        return Schema::hasTable('pengaturan_metode_bayar') ? DB::table('pengaturan_metode_bayar')->orderBy('id')->first() : null;
    }

    public static function aktif(string $kode): bool
    {
        if (! isset(self::METODE[$kode])) {
            return false;
        }
        $b = self::baris();

        return $b === null ? true : (bool) $b->{$kode};
    }

    public static function pengumuman(): ?string
    {
        $p = trim((string) (self::baris()?->pengumuman ?? ''));

        return $p !== '' ? $p : null;
    }

    /** Tolak pesanan baru lewat metode yang sedang dimatikan. */
    public static function pastikan(string $kode): void
    {
        if (! self::aktif($kode)) {
            throw new AppException(422, __('Metode pembayaran ini sedang tidak tersedia. Silakan pilih cara bayar lain.'));
        }
    }

    /**
     * @param  array<string,bool>  $aktif  kode => nyala
     */
    public static function simpan(array $aktif, ?string $pengumuman, int $idPengguna): void
    {
        $baru = [];
        foreach (array_keys(self::METODE) as $k) {
            $baru[$k] = (bool) ($aktif[$k] ?? false);
        }
        // Minimal satu metode di luar Dompet Wali yang BENAR-BENAR bisa dipakai — VA Midtrans yang belum
        // terpasang (kunci/rekening) tak dihitung. Tanpa itu wali tak punya jalan memasukkan uang sama sekali.
        $bisaDipakai = $baru['transfer'] || ($baru['midtrans'] && PembayaranMidtrans::siap());
        if (! $bisaDipakai) {
            throw new AppException(422, 'Minimal satu metode pembayaran selain Dompet Wali harus tetap nyala (VA Midtrans dihitung hanya bila sudah terpasang).');
        }

        $lama = self::baris();
        $pengumuman = trim((string) $pengumuman) ?: null;
        DB::table('pengaturan_metode_bayar')->updateOrInsert(['id' => $lama->id ?? 1],
            $baru + ['pengumuman' => $pengumuman, 'diubah_oleh' => $idPengguna, 'updated_at' => now()]
            + ($lama ? [] : ['created_at' => now()]));

        $perubahan = [];
        foreach ($baru as $k => $v) {
            if (! $lama || (bool) $lama->{$k} !== $v) {
                $perubahan[$k] = ['dari' => $lama ? (bool) $lama->{$k} : null, 'ke' => $v];
            }
        }
        if ((string) ($lama->pengumuman ?? '') !== (string) $pengumuman) {
            $perubahan['pengumuman'] = ['dari' => $lama->pengumuman ?? null, 'ke' => $pengumuman];
        }
        if ($perubahan !== []) {
            Jejak::catat('ubah_metode_pembayaran', ['modul' => self::MODUL, 'id_pengguna' => $idPengguna,
                'detail' => collect($baru)->map(fn ($v, $k) => self::METODE[$k].': '.($v ? 'nyala' : 'mati'))->implode(', '),
                'perubahan' => $perubahan]);
        }
    }
}
