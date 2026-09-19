<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\Dana;
use App\Models\DanaAkun;

/**
 * KEBIJAKAN DANA TERIKAT — penjaga peruntukan.
 *
 * Inti seluruh modul dana ada di sini: uang wakaf pembangunan tidak boleh
 * terpakai untuk gaji. Sebelum ini aplikasi tak punya satu pun cara untuk
 * mengatakannya, apalagi menolaknya — dan laporan ke donatur hanya bisa
 * disusun dari ingatan.
 *
 * Yang dibatasi hanya baris BEBAN. Dana terikat membatasi BELANJA-nya;
 * kasnya sendiri menumpang rekening yang sama dengan dana lain, dan
 * pendapatannya justru penerimaan dana itu. Membatasi seluruh jenis akun
 * hanya akan menolak jurnal yang sebetulnya sah dan mengajari penggunanya
 * berhenti menandai dana sama sekali.
 */
final class DanaPolicy
{
    /**
     * Pastikan tiap baris berdana boleh dibebankan ke akunnya.
     *
     * @param  array<int,array<string,mixed>>  $lines
     */
    public static function assertPeruntukan(array $lines): void
    {
        $kodeDana = array_values(array_unique(array_filter(
            array_map(fn ($l) => $l['kode_dana'] ?? null, $lines),
        )));
        if ($kodeDana === []) {
            return;
        }

        $dana = Dana::whereIn('kode_dana', $kodeDana)->get()->keyBy('kode_dana');
        $akar = self::akarAkun($lines);

        // Daftar akun yang diizinkan, per dana. Dana yang tak punya daftar
        // sama sekali berarti tak membatasi akun — bukan melarang semuanya.
        $izin = DanaAkun::whereIn('kode_dana', $kodeDana)->get()
            ->groupBy('kode_dana')->map(fn ($g) => $g->pluck('kode_coa')->all());

        foreach ($lines as $i => $l) {
            $kode = $l['kode_dana'] ?? null;
            if (! $kode) {
                continue;
            }

            $d = $dana->get($kode);
            if (! $d) {
                throw new AppException(422, 'Baris '.($i + 1).": dana {$kode} tidak ditemukan.");
            }
            if ($d->status === 'nonaktif') {
                throw new AppException(422, 'Baris '.($i + 1).": dana \"{$d->nama_dana}\" berstatus nonaktif.");
            }

            // Hanya baris beban yang dibatasi — lihat catatan kelas.
            if (($akar[$l['kode_coa']] ?? null) !== '5') {
                continue;
            }

            $diizinkan = $izin->get($kode, []);
            if ($diizinkan === [] || in_array($l['kode_coa'], $diizinkan, true)) {
                continue;
            }

            $nama = CoaDetail::find($l['kode_coa'])?->nama_coa ?? $l['kode_coa'];
            throw new AppException(422,
                'Baris '.($i + 1).": akun \"{$nama}\" berada di luar peruntukan dana \"{$d->nama_dana}\" "
                .'('.$d->labelJenis().'). '
                .($d->peruntukan ? "Peruntukannya: {$d->peruntukan}. " : '')
                .'Pakai dana lain, atau tambahkan akun ini ke daftar peruntukan dananya.');
        }
    }

    /**
     * Akar kelompok (1..5) tiap akun yang dipakai baris-baris ini.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<string,?string>
     */
    private static function akarAkun(array $lines): array
    {
        $kode = array_values(array_unique(array_map(fn ($l) => $l['kode_coa'], $lines)));

        $out = [];
        foreach (CoaDetail::whereIn('kode_coa', $kode)->get(['kode_coa', 'kode_grup']) as $a) {
            $out[$a->kode_coa] = CoaDetail::akarKelompok($a->kode_grup);
        }

        return $out;
    }
}
