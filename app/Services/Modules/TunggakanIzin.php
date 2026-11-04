<?php

namespace App\Services\Modules;

use App\Models\Perizinan;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeIzin;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * KEBIJAKAN TUNGGAKAN perizinan (Tahap 3, keputusan user 4 Nov 2026) — per jenis izin, bisa diganti kapan saja:
 *
 *  • `tidak`         — tunggakan tak memengaruhi izin.
 *  • `tolak`         — izin TIDAK diberikan: wali/staf tak bisa mengajukan, dan izin yang sudah disetujui
 *                      tertahan di pos KELUAR bila tunggakannya muncul sebelum hari keluar.
 *  • `tahan_kembali` — boleh keluar, tetapi santri TERTAHAN di pos MASUK sampai tunggakannya diselesaikan.
 *
 * Ukuran: jumlah tagihan yang LEWAT JATUH TEMPO & belum lunas ≥ `tunggakan_periode`, lingkup `spp` (SPP
 * saja) atau `semua` tagihan. Petugas berhak `perizinan-tunggakan` (ubah) boleh MENGIZINKAN di pos dengan
 * alasan — tercatat di Jejak Audit, dan bisa dipakai berkali-kali.
 */
final class TunggakanIzin
{
    public const KEBIJAKAN = ['tidak' => 'Tidak berlaku', 'tolak' => 'Izin tidak diberikan', 'tahan_kembali' => 'Boleh keluar, tidak boleh kembali'];

    public const LINGKUP = ['semua' => 'Semua tagihan', 'spp' => 'SPP saja'];

    public const MODUL_IZIN = 'perizinan-tunggakan';

    /** @return object{jumlah:int, sisa:string} tagihan lewat jatuh tempo & belum lunas */
    public static function ringkas(Santri $santri, string $lingkup): object
    {
        $q = TagihanSantri::query()->berlaku()->where('id_santri', $santri->id)
            ->whereIn('status', ['belum_bayar', 'sebagian'])->where('sisa', '>', 0)
            ->whereNotNull('jatuh_tempo')->whereDate('jatuh_tempo', '<', Carbon::today())
            ->when($lingkup === 'spp', fn ($w) => $w->where('perilaku', 'spp'));

        return (object) ['jumlah' => (clone $q)->count(), 'sisa' => Money::of((clone $q)->sum('sisa'))];
    }

    /**
     * Hambatan kebijakan $kebijakan untuk santri ini menurut jenis izinnya; null = tak terhambat.
     *
     * @return object{jumlah:int, sisa:string, batas:int, lingkup:string}|null
     */
    public static function hambatan(TipeIzin $tipe, Santri $santri, string $kebijakan): ?object
    {
        if ($tipe->tunggakan_kebijakan !== $kebijakan) {
            return null;
        }
        $r = self::ringkas($santri, (string) $tipe->tunggakan_lingkup);
        if ($r->jumlah < max(1, (int) $tipe->tunggakan_periode)) {
            return null;
        }

        return (object) ['jumlah' => $r->jumlah, 'sisa' => $r->sisa, 'batas' => (int) $tipe->tunggakan_periode, 'lingkup' => (string) $tipe->tunggakan_lingkup];
    }

    /** Hambatan di pos untuk aksi keluar/kembali sebuah izin. */
    public static function diPos(Perizinan $z, string $aksi): ?object
    {
        $z->loadMissing(['tipe', 'santri']);

        return $z->tipe && $z->santri ? self::hambatan($z->tipe, $z->santri, $aksi === 'keluar' ? 'tolak' : 'tahan_kembali') : null;
    }

    public static function teks(object $h): string
    {
        return __(':jumlah tagihan lewat jatuh tempo (Rp :sisa)', ['jumlah' => $h->jumlah,
            'sisa' => number_format((float) $h->sisa, 0, ',', '.')]);
    }
}
