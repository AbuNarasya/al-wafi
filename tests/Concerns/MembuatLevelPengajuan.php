<?php

namespace Tests\Concerns;

use App\Models\LevelPengajuan;

/**
 * Fixture level pengajuan.
 *
 * Dulu tiap test menulis `LevelPengajuan::create(['peringkat' => 4, 'nama' =>
 * 'Staff'])` sendiri — sah selama wewenangnya melekat pada ANGKA 4. Sejak
 * wewenang pindah ke tanda peran, baris telanjang seperti itu menghasilkan
 * "Staff" yang tak boleh mengajukan apa pun, dan gejalanya jauh dari sebabnya:
 * 403 di tengah test yang sama sekali tak berurusan dengan hak akses.
 *
 * Lewat satu pintu ini, perubahan berikutnya pada bentuk masternya tak lagi
 * menyentuh belasan berkas test — alasan yang sama seperti trait MembuatTarif.
 *
 * `$peran` menimpa bawaannya, untuk test yang justru menguji susunan tak lazim
 * (mis. peringkat 5, atau Mudir Bagian yang diberi wewenang mengajukan).
 */
trait MembuatLevelPengajuan
{
    /** @param  array<string,bool>  $peran */
    protected function buatLevelPengajuan(int $peringkat, ?string $nama = null, array $peran = []): LevelPengajuan
    {
        return LevelPengajuan::create(array_merge(
            ['peringkat' => $peringkat, 'nama' => $nama ?? "Level {$peringkat}", 'status' => 'aktif'],
            LevelPengajuan::peranBawaan($peringkat),
            $peran,
        ));
    }
}
