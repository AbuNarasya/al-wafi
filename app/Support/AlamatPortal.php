<?php

namespace App\Support;

/**
 * Alamat portal wali — di domainnya sendiri bila `config('portal.domain')` diisi (lihat config/portal.php).
 * Dipakai untuk link portal yang dibuat di LUAR permintaan portal (mis. staf membuat link verifikasi WA dari
 * erp.al-wafi.sch.id): `route()` biasa akan memakai domain staf.
 */
final class AlamatPortal
{
    public static function domain(): ?string
    {
        $d = strtolower(trim((string) config('portal.domain')));

        return $d !== '' ? $d : null;
    }

    public static function rute(string $nama, mixed $parameter = []): string
    {
        $domain = self::domain();

        return $domain ? 'https://'.$domain.route($nama, $parameter, false) : route($nama, $parameter);
    }

    /** Alamat portal: /wali dan seluruh isinya. */
    public static function alamatPortal(string $path): bool
    {
        $path = trim($path, '/');

        return $path === 'wali' || str_starts_with($path, 'wali/');
    }
}
