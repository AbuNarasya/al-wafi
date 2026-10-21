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

        // Pengembalian HARI INI atas belanja HARI LAIN (retur struk kemarin, potongan yatim yang dikembalikan
        // rekonsiliasi malam) TIDAK menambah jatah hari ini — dulu ia membuat batas harian terlampaui tanpa PIN
        // (audit 21 Okt 2026). Yang dikurangkan hanya pengembalian atas belanja hari ini juga.
        $hariIni = now()->toDateString();
        $sum = MutasiDompet::query()->from('mutasi_dompet as m')
            ->where('m.pemilik', 'santri')->where('m.id_dompet', $idDompet)->where('m.jenis', 'jajan')->whereDate('m.tanggal', $hariIni)
            ->where(fn ($q) => $q->where('m.nominal', '<', 0)
                ->orWhere(fn ($k) => $k
                    ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('transaksi_dompet_toko as t')
                        ->join('transaksi_dompet_toko as a', 'a.nomor_toko', '=', 't.nomor_asal')
                        ->whereColumn('t.id_mutasi_dompet', 'm.id')->whereDate('a.tanggal', '<', $hariIni))
                    ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('retur_penjualan as r')
                        ->join('penjualan as p', 'p.id_penjualan', '=', 'r.id_penjualan')
                        ->whereColumn('r.id_mutasi_dompet', 'm.id')->whereDate('p.waktu', '<', $hariIni))))
            ->sum('m.nominal');

        return Money::sub('0', Money::of($sum));
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
