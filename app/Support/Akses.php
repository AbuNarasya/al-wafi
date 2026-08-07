<?php

namespace App\Support;

use App\Models\HakAksesModul;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Helper akses untuk lapisan tampilan (sidebar & tombol aksi). Menegakkan hak
 * yang sama seperti middleware HakAksesModul, tetapi untuk RENDER (bukan gate):
 * sidebar hanya menampilkan menu yang boleh dilihat, tombol hanya muncul bila
 * aksinya diizinkan. Admin melihat semuanya.
 */
final class Akses
{
    /** @var array<int,array<string,array{lihat:bool,buat:bool,ubah:bool,hapus:bool,menu:bool}>> cache per pengguna */
    private static array $cache = [];

    /**
     * Buang cache hak. Satu permintaan HTTP = satu proses, jadi di lapangan
     * cache ini tak pernah basi. Yang butuh justru TEST: seluruh test berbagi
     * satu proses PHP, sehingga hak yang diberikan di tengah test tak terlihat
     * karena yang dibaca masih hasil permintaan sebelumnya — dan gejalanya
     * menyesatkan, tampak seperti tombolnya yang tak muncul.
     */
    public static function lupakan(): void
    {
        self::$cache = [];
    }

    /**
     * @return array<string,array{lihat:bool,buat:bool,ubah:bool,hapus:bool,menu:bool}>
     */
    private static function hakUser(User $user): array
    {
        if (isset(self::$cache[$user->id_pengguna])) {
            return self::$cache[$user->id_pengguna];
        }

        $rows = HakAksesModul::query()
            ->where('id_pengguna', $user->id_pengguna)
            ->get()
            ->keyBy('kode_modul')
            ->map(fn ($h) => [
                'lihat' => (bool) $h->lihat,
                'buat' => (bool) $h->buat,
                'ubah' => (bool) $h->ubah,
                'hapus' => (bool) $h->hapus,
                'menu' => (bool) $h->menu,
            ])
            ->all();

        return self::$cache[$user->id_pengguna] = $rows;
    }

    /** Apakah pengguna login boleh menjalankan aksi pada modul. */
    public static function boleh(string $kode, string $aksi = 'lihat'): bool
    {
        if (ModulRegistry::isBebas($kode)) {
            return true;
        }

        $user = Auth::user();
        if (! $user || $user->status !== 'aktif') {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }

        return (bool) (self::hakUser($user)[$kode][$aksi] ?? false);
    }

    /** Apakah modul tampil di sidebar untuk pengguna login. */
    public static function bolehMenu(string $kode): bool
    {
        $user = Auth::user();
        if (! $user || $user->status !== 'aktif') {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }

        return (bool) (self::hakUser($user)[$kode]['menu'] ?? false);
    }

    /**
     * Bangun struktur menu sidebar untuk pengguna login, mengikuti GRUP_ORDER
     * dan SUB_ORDER. Grup "TANPA MENU" tidak pernah ditampilkan.
     *
     * @return list<array{grup:string,subs:list<array{sub:?string,items:list<array{kode:string,nama:string}>}>}>
     */
    public static function menu(): array
    {
        $tampil = array_filter(
            ModulRegistry::MODUL,
            fn ($m) => $m['grup'] !== 'TANPA MENU' && self::bolehMenu($m['kode']),
        );

        $hasil = [];
        foreach (ModulRegistry::GRUP_ORDER as $grup) {
            if ($grup === 'TANPA MENU') {
                continue;
            }

            $anggota = array_values(array_filter($tampil, fn ($m) => $m['grup'] === $grup));
            if ($anggota === []) {
                continue;
            }

            // Kelompokkan per sub, ikuti SUB_ORDER; item tanpa sub di grup awal.
            $subOrder = ModulRegistry::SUB_ORDER[$grup] ?? [];
            $subs = [];

            $tanpaSub = array_values(array_filter($anggota, fn ($m) => empty($m['sub'])));
            if ($tanpaSub !== []) {
                $subs[] = ['sub' => null, 'items' => self::petakanItem($tanpaSub)];
            }

            foreach ($subOrder as $sub) {
                $isi = array_values(array_filter($anggota, fn ($m) => ($m['sub'] ?? null) === $sub));
                if ($isi !== []) {
                    $subs[] = ['sub' => $sub, 'items' => self::petakanItem($isi)];
                }
            }

            $hasil[] = ['grup' => $grup, 'subs' => $subs];
        }

        return $hasil;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{kode:string,nama:string}>
     */
    private static function petakanItem(array $items): array
    {
        return array_map(fn ($m) => ['kode' => $m['kode'], 'nama' => $m['nama']], $items);
    }
}
