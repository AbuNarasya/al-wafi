<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DompetSantri;
use App\Models\MutasiDompet;
use App\Services\Ledger\DocNumber;
use App\Support\Money;

/**
 * POTONG & KEMBALIKAN UANG SAKU untuk belanja kartu — satu pintu untuk kasir di instans yang sama
 * ([[KasirService]], mode lokal) DAN penghubung dari instans Wafi Mart ([[LayananDompetToko]]).
 * Aturannya karena itu tak mungkin berbeda: saldo cukup, cadangan tarik tunai kegiatan, batas harian.
 *
 * Mutasinya `jajan` (− potong, + kembali) TANPA jurnal: kasir lokal menjurnalnya saat tutup shift,
 * penghubung lewat jurnal ringkas tiap malam. Dipanggil DI DALAM transaksi pemanggil.
 */
class DompetBelanja
{
    /** Belanja kartu bersih hari ini (potongan dikurangi pengembalian) — dasar batas harian. */
    public static function hariIni(int $idSantri): string
    {
        $idDompet = DompetSantri::where('id_santri', $idSantri)->value('id');
        if (! $idDompet) {
            return '0';
        }

        return Money::sub('0', Money::of(MutasiDompet::where('pemilik', 'santri')->where('id_dompet', $idDompet)
            ->where('jenis', 'jajan')->whereDate('tanggal', now()->toDateString())->sum('nominal')));
    }

    /** Lewat batas harian bila belanja ini ditambahkan? (PIN belanja anak wajib) */
    public static function lewatBatas(int $idSantri, string $nominal): bool
    {
        return Money::gt(Money::add(self::hariIni($idSantri), $nominal), KartuSantriService::batasHarian($idSantri));
    }

    /** Potong saldo (baris saldo dikunci; batas dihitung ULANG di bawah kunci). */
    public static function potong(int $idSantri, string $nominal, string $keterangan, ?int $idPengguna, bool $pinBenar): MutasiDompet
    {
        $dompet = DompetSantri::where('id_santri', $idSantri)->lockForUpdate()->first();
        if (! $dompet || Money::lt($dompet->saldo, $nominal)) {
            throw new AppException(422, 'Saldo uang saku tidak cukup untuk belanja ini.');
        }
        PenarikanTunaiService::pastikanTersedia($idSantri, $dompet->saldo, $nominal, 'Saldo uang saku tidak cukup untuk belanja ini');
        if (! $pinBenar && self::lewatBatas($idSantri, $nominal)) {
            throw new AppException(409, 'Batas belanja harian terlampaui — ulangi dengan PIN belanja.');
        }

        return self::catat($dompet, Money::sub('0', $nominal), $keterangan, $idPengguna);
    }

    public static function kembalikan(int $idSantri, string $nominal, string $keterangan, ?int $idPengguna): MutasiDompet
    {
        $dompet = DompetSantri::where('id_santri', $idSantri)->lockForUpdate()->firstOrFail();

        return self::catat($dompet, $nominal, $keterangan, $idPengguna);
    }

    private static function catat(DompetSantri $dompet, string $nominalBertanda, string $keterangan, ?int $idPengguna): MutasiDompet
    {
        $saldo = Money::add($dompet->saldo, $nominalBertanda);
        $dompet->update(['saldo' => $saldo]);
        $base = DocNumber::docBase('DMP', now());
        $nomor = DocNumber::nextDocNumber($base, MutasiDompet::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor'));

        return MutasiDompet::create([
            'nomor' => $nomor, 'pemilik' => 'santri', 'id_dompet' => $dompet->id, 'jenis' => 'jajan',
            'nominal' => $nominalBertanda, 'saldo_setelah' => $saldo, 'tanggal' => now()->toDateString(),
            'keterangan' => $keterangan, 'status' => 'terverifikasi', 'dicatat_oleh' => $idPengguna,
        ]);
    }
}
