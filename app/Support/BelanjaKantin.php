<?php

namespace App\Support;

use App\Exceptions\AppException;
use App\Models\DompetSantri;
use App\Support\Audit\Jejak;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENGATURAN BELANJA KARTU DI KANTIN MITRA (keputusan user 9 Nov 2026) — tabel `pengaturan_belanja_kantin`.
 *
 *  • Mode batas `gabung`: satu batas harian untuk Wafi Mart + semua kantin (perilaku lama).
 *    Mode `pisah`: batas Wafi Mart (`dompet_santri.batas_harian` / Pengaturan Perusahaan) dan batas Kantin
 *    (`dompet_santri.batas_harian_kantin` / `batas_kantin_bawaan`) dihitung sendiri-sendiri.
 *  • Saklar wali: nyala → wali bisa mematikan belanja kantin per anak (`dompet_santri.kantin_nonaktif`);
 *    mati → pilihan itu tak tampil & tak berlaku (tetap tersimpan, berlaku lagi saat saklar dinyalakan).
 *
 * Dibaca ulang tiap panggilan (satu baris) supaya perubahan langsung berlaku. Ditegakkan di [[DompetBelanja]].
 */
final class BelanjaKantin
{
    public const MODUL = 'belanja-kantin';

    public const ASAL = ['mart' => 'Wafi Mart', 'kantin' => 'Kantin mitra'];

    public const MODE = ['gabung' => 'Gabung', 'pisah' => 'Pisah'];

    private static function baris(): ?object
    {
        return Schema::hasTable('pengaturan_belanja_kantin') ? DB::table('pengaturan_belanja_kantin')->orderBy('id')->first() : null;
    }

    public static function pisah(): bool
    {
        return (self::baris()?->mode_batas ?? 'gabung') === 'pisah';
    }

    public static function saklarWali(): bool
    {
        return (bool) (self::baris()?->saklar_wali ?? false);
    }

    public static function batasKantinBawaan(): string
    {
        return Money::of(self::baris()?->batas_kantin_bawaan ?? 20000);
    }

    /** Asal yang dikenal; selain itu dianggap Wafi Mart (klien lama tak mengirim asal). */
    public static function asal(?string $asal): string
    {
        return isset(self::ASAL[(string) $asal]) ? (string) $asal : 'mart';
    }

    /** Santri ini boleh belanja di kantin? Pilihan wali hanya berlaku selama saklar petugas menyala. */
    public static function bolehDiKantin(int $idSantri): bool
    {
        return ! self::saklarWali() || ! (bool) DompetSantri::where('id_santri', $idSantri)->value('kantin_nonaktif');
    }

    /** Tolak belanja kantin bila walinya mematikan (dipanggil cek kartu & potong). */
    public static function pastikanBoleh(int $idSantri, string $asal): void
    {
        if ($asal === 'kantin' && ! self::bolehDiKantin($idSantri)) {
            throw new AppException(403, 'Belanja di kantin untuk santri ini dimatikan oleh walinya — kartu hanya bisa dipakai di Wafi Mart.');
        }
    }

    /** @return array{mode_batas:string, batas_kantin_bawaan:string, saklar_wali:bool, diubah_oleh:?int, updated_at:mixed} */
    public static function semua(): array
    {
        $b = self::baris();

        return [
            'mode_batas' => $b->mode_batas ?? 'gabung',
            'batas_kantin_bawaan' => Money::of($b->batas_kantin_bawaan ?? 20000),
            'saklar_wali' => (bool) ($b->saklar_wali ?? false),
            'diubah_oleh' => $b->diubah_oleh ?? null,
            'updated_at' => $b->updated_at ?? null,
        ];
    }

    public static function simpan(string $mode, string|int|float $batasKantin, bool $saklarWali, int $idPengguna): void
    {
        if (! isset(self::MODE[$mode])) {
            throw new AppException(422, 'Mode batas tidak dikenal.');
        }
        $batasKantin = Money::of($batasKantin);
        if (Money::isNegative($batasKantin)) {
            throw new AppException(422, 'Batas kantin bawaan tidak boleh negatif.');
        }

        $lama = self::baris();
        $baru = ['mode_batas' => $mode, 'batas_kantin_bawaan' => $batasKantin, 'saklar_wali' => $saklarWali];
        DB::table('pengaturan_belanja_kantin')->updateOrInsert(['id' => $lama->id ?? 1],
            $baru + ['diubah_oleh' => $idPengguna, 'updated_at' => now()] + ($lama ? [] : ['created_at' => now()]));

        $perubahan = [];
        if (($lama->mode_batas ?? null) !== $mode) {
            $perubahan['mode_batas'] = ['dari' => $lama->mode_batas ?? null, 'ke' => $mode];
        }
        if (! $lama || ! Money::eq($lama->batas_kantin_bawaan, $batasKantin)) {
            $perubahan['batas_kantin_bawaan'] = ['dari' => $lama->batas_kantin_bawaan ?? null, 'ke' => $batasKantin];
        }
        if (! $lama || (bool) $lama->saklar_wali !== $saklarWali) {
            $perubahan['saklar_wali'] = ['dari' => $lama ? (bool) $lama->saklar_wali : null, 'ke' => $saklarWali];
        }
        if ($perubahan !== []) {
            Jejak::catat('ubah_pengaturan_belanja_kantin', ['modul' => self::MODUL, 'id_pengguna' => $idPengguna,
                'detail' => 'Batas '.self::MODE[$mode].', batas kantin bawaan Rp '.number_format((float) $batasKantin, 0, ',', '.')
                    .', pilihan kantin di portal wali '.($saklarWali ? 'nyala' : 'mati'),
                'perubahan' => $perubahan]);
        }
    }
}
