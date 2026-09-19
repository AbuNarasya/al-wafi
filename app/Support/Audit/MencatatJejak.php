<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Pasang pada model keuangan: setiap created/updated/deleted otomatis
 * meninggalkan baris di `activity_log`, lengkap dengan nilai LAMA → BARU.
 *
 * Sengaja berupa trait pada model, BUKAN pemanggilan manual di tiap service:
 * modul keuangan ini punya belasan service dan puluhan jalan masuk, dan yang
 * ditempel satu per satu pasti ada yang terlewat — lalu justru yang terlewat
 * itu yang dicari saat ada angka dipersoalkan.
 *
 * Model yang memakainya boleh menyetel:
 *   - `$jejakModul`   kode modul hak akses, untuk menyaring di layar Jejak Audit
 *   - `$jejakAbaikan` kolom yang tak perlu direkam (cap waktu, kolom turunan)
 */
trait MencatatJejak
{
    /** Kolom yang selalu diabaikan — deraunya jauh melebihi gunanya. */
    private const JEJAK_ABAIKAN_BAWAAN = ['created_at', 'updated_at'];

    public static function bootMencatatJejak(): void
    {
        static::created(fn (Model $m) => $m->tulisJejak('buat'));

        static::updated(function (Model $m) {
            $ubah = $m->ringkasPerubahan();
            // Penyimpanan yang tak mengubah apa pun (mis. touch, atau
            // menyimpan ulang nilai yang sama) tidak layak jadi baris jejak.
            if ($ubah !== []) {
                $m->tulisJejak('ubah', $ubah);
            }
        });

        static::deleted(fn (Model $m) => $m->tulisJejak('hapus'));
    }

    /** @return array<string,array{lama:mixed,baru:mixed}> */
    public function ringkasPerubahan(): array
    {
        $abaikan = array_merge(self::JEJAK_ABAIKAN_BAWAAN, $this->jejakAbaikan ?? []);
        $out = [];

        foreach ($this->getChanges() as $kolom => $baru) {
            if (in_array($kolom, $abaikan, true)) {
                continue;
            }
            $out[$kolom] = ['lama' => $this->getOriginal($kolom), 'baru' => $baru];
        }

        return $out;
    }

    /**
     * Kegagalan menulis jejak TIDAK boleh menggagalkan transaksinya. Jejak yang
     * hilang itu buruk; transaksi keuangan yang batal karena pencatatnya
     * tersandung jauh lebih buruk — dan penyebabnya akan tampak sebagai galat
     * di tempat yang sama sekali tak berhubungan.
     */
    protected function tulisJejak(string $aksi, ?array $perubahan = null): void
    {
        try {
            Jejak::catat($aksi.'_'.($this->jejakModul ?? class_basename($this)), [
                'modul' => $this->jejakModul ?? null,
                'ref_jenis' => class_basename($this),
                'ref_id' => $this->getKey(),
                'perubahan' => $perubahan,
            ]);
        } catch (\Throwable) {
            // sengaja dibiarkan
        }
    }
}
