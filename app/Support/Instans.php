<?php

namespace App\Support;

/**
 * Jati diri instans yang sedang berjalan (ERP Al Wafi / ERP Wafi Mart) — lihat config/instans.php.
 * Semua layar membaca nama & logo dari sini; semua pemeriksa hak akses menanyakan modulHidup().
 */
final class Instans
{
    public static function kode(): string
    {
        $kode = (string) config('instans.kode', 'alwafi');

        return array_key_exists($kode, (array) config('instans.daftar')) ? $kode : 'alwafi';
    }

    /** @return array<string,mixed> */
    private static function data(): array
    {
        return (array) config('instans.daftar.'.self::kode());
    }

    public static function adalah(string $kode): bool
    {
        return self::kode() === $kode;
    }

    public static function nama(): string
    {
        return (string) (self::data()['nama'] ?? 'Al Wafi ERP');
    }

    public static function subjudul(): string
    {
        return (string) (self::data()['subjudul'] ?? '');
    }

    /** Jalur logo relatif terhadap public/, atau null bila berkasnya belum dipasang. */
    public static function logo(): ?string
    {
        $jalur = self::data()['logo'] ?? null;

        return $jalur && file_exists(public_path($jalur)) ? $jalur : null;
    }

    public static function manifest(): string
    {
        return (string) (self::data()['manifest'] ?? '/manifest.json');
    }

    public static function portalWali(): bool
    {
        return (bool) (self::data()['portal_wali'] ?? true);
    }

    /** @return list<string> */
    public static function modulMati(): array
    {
        $tambahan = array_filter(array_map('trim', explode(',', (string) config('instans.modul_mati_tambahan', ''))));

        $toko = self::tokoTerpisah() ? (self::data()['modul_mati_bila_toko_terpisah'] ?? []) : [];

        return array_values(array_unique([...(self::data()['modul_mati'] ?? []), ...$toko, ...$tambahan]));
    }

    /** Wafi Mart sudah berjalan di instansnya sendiri (WAFIMART_TERPISAH) — fungsi toko di sini dimatikan. */
    public static function tokoTerpisah(): bool
    {
        return (bool) config('instans.wafimart_terpisah', false);
    }

    /** Grup menu sidebar yang disembunyikan utuh di instans ini. */
    public static function grupMenuSembunyi(string $grup): bool
    {
        return self::tokoTerpisah() && in_array($grup, self::data()['grup_menu_sembunyi_bila_toko_terpisah'] ?? [], true);
    }

    /** Modul hidup di instans ini? Dimatikan per kode atau karena grupnya dimatikan. */
    public static function modulHidup(string $kode): bool
    {
        if (in_array($kode, self::modulMati(), true)) {
            return false;
        }
        $grup = ModulRegistry::byKode($kode)['grup'] ?? null;

        return $grup === null || ! in_array($grup, self::data()['grup_mati'] ?? [], true);
    }

    /** Alamat ditutup di instans ini? Cocok persis atau di bawahnya (`reports/x` menutup `reports/x/...`). */
    public static function alamatMati(string $path): bool
    {
        $path = trim($path, '/');
        foreach (self::data()['alamat_mati'] ?? [] as $awalan) {
            if ($path === $awalan || str_starts_with($path, $awalan.'/')) {
                return true;
            }
        }

        return false;
    }

    /** Item menu (grup + URL) yang disembunyikan di instans ini walau modulnya hidup. */
    public static function menuSembunyi(string $grup, string $url): bool
    {
        return in_array("{$grup}|{$url}", self::data()['menu_sembunyi'] ?? [], true);
    }

    public static function imporHidup(string $kunci): bool
    {
        return ! in_array($kunci, self::data()['impor_mati'] ?? [], true);
    }

    /** Fitur kecil per instans (`laporan_unit_toko`, `pengaturan_santri`) — bawaan hidup. */
    public static function fitur(string $nama): bool
    {
        return (bool) (self::data()[$nama] ?? true);
    }
}
