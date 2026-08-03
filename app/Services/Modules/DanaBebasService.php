<?php

namespace App\Services\Modules;

use App\Models\AkunPengurangDanaBebas;
use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\OpeningBalance;
use App\Models\PerintahPembayaran;
use App\Models\PerintahPembayaranDetail;
use App\Support\Money;

/**
 * DANA BEBAS DIPAKAI — berapa uang yang benar-benar boleh dibelanjakan.
 *
 * Saldo kas & bank BUKAN uang yang boleh dipakai seluruhnya. Di dalamnya ada
 * uang milik orang lain — tabungan santri, dompet wali, titipan kantin pihak
 * ketiga — yang kebetulan disimpan di rekening yang sama. Membelanjakannya sama
 * dengan meminjam uang santri tanpa mereka tahu.
 *
 *     saldo seluruh kas & bank
 *   − saldo akun pengurang (diatur admin, lihat AkunPengurangDanaBebas)
 *   − komitmen: PP yang sudah diotorisasi tapi belum direalisasi
 *   = dana bebas dipakai
 *
 * BARIS KETIGA ITU YANG PALING MUDAH TERLUPA, dan tanpanya penjagaan ini hanya
 * ilusi: dua PP bisa lolos pemeriksaan sendiri-sendiri lalu bersama-sama
 * melampaui saldo, karena masing-masing melihat saldo penuh — uangnya memang
 * belum keluar. Uang yang sudah diperintahkan bayar harus dianggap terpakai
 * sejak diotorisasi.
 *
 * SATU TEMPAT, SATU ANGKA. Dipakai bersama layar otorisasi PP dan kartu
 * Dashboard Keuangan. Kalau dihitung dua kali di dua tempat, keduanya pasti
 * berbeda suatu hari nanti — dan tak ada yang tahu mana yang benar.
 */
class DanaBebasService
{
    /**
     * Rincian lengkap dana bebas.
     *
     * @return array{
     *     saldo_kas: string, pengurang: string, komitmen: string, dana_bebas: string,
     *     rincian_kas: list<array{kode_coa:string,nama:string,jenis:string,saldo:string}>,
     *     rincian_pengurang: list<array{kode_coa:string,nama:string,saldo:string}>,
     *     rincian_komitmen: list<array{kode_transaksi:int,nomor:string,sisa:string}>
     * }
     */
    public function hitung(): array
    {
        $saldo = $this->saldoPerAkun();

        $rincianKas = BankAccount::orderBy('kode_coa')->get()->map(fn ($b) => [
            'kode_coa' => $b->kode_coa,
            'nama' => $b->nama_rekening,
            'jenis' => $b->jenis_rekening,
            // Kas = aset, saldo normal debet.
            'saldo' => $this->saldoDebet($saldo, $b->kode_coa),
        ])->all();
        $saldoKas = array_reduce($rincianKas, fn ($t, $r) => Money::add($t, $r['saldo']), '0');

        $rincianPengurang = AkunPengurangDanaBebas::with('akun')->get()
            ->sortBy('kode_coa')
            ->map(fn ($a) => [
                'kode_coa' => $a->kode_coa,
                'nama' => $a->akun?->nama_coa ?? $a->kode_coa,
                // Titipan = kewajiban, saldo normal kredit.
                'saldo' => $this->saldoKredit($saldo, $a->kode_coa),
            ])->values()->all();
        $pengurang = array_reduce($rincianPengurang, fn ($t, $r) => Money::add($t, $r['saldo']), '0');

        $rincianKomitmen = $this->komitmen();
        $komitmen = array_reduce($rincianKomitmen, fn ($t, $r) => Money::add($t, $r['sisa']), '0');

        return [
            'saldo_kas' => $saldoKas,
            'pengurang' => $pengurang,
            'komitmen' => $komitmen,
            'dana_bebas' => Money::sub(Money::sub($saldoKas, $pengurang), $komitmen),
            'rincian_kas' => $rincianKas,
            'rincian_pengurang' => $rincianPengurang,
            'rincian_komitmen' => $rincianKomitmen,
        ];
    }

    /** Angka akhirnya saja — untuk pemeriksaan cepat. */
    public function danaBebas(): string
    {
        return $this->hitung()['dana_bebas'];
    }

    /**
     * Dana bebas bila komitmen sebuah PP TIDAK ikut dihitung.
     *
     * Dipakai saat mengotorisasi PP yang sedang dibuka: komitmennya sendiri tak
     * boleh mengurangi jatah yang sedang diperiksa, kalau tidak ia akan
     * menghalangi dirinya sendiri begitu diotorisasi ulang.
     */
    public function danaBebasKecuali(int $kodeTransaksi): string
    {
        $h = $this->hitung();
        $milikSendiri = array_reduce(
            array_filter($h['rincian_komitmen'], fn ($r) => $r['kode_transaksi'] === $kodeTransaksi),
            fn ($t, $r) => Money::add($t, $r['sisa']),
            '0',
        );

        return Money::add($h['dana_bebas'], $milikSendiri);
    }

    /** Saldo AKTUAL tiap rekening — kenyataan fisik, sebelum dikurangi apa pun. */
    public function saldoRekening(string $kodeCoa): string
    {
        return $this->saldoDebet($this->saldoPerAkun(), $kodeCoa);
    }

    /**
     * PP yang sudah diotorisasi tapi belum selesai direalisasikan.
     *
     * @return list<array{kode_transaksi:int,nomor:string,sisa:string}>
     */
    private function komitmen(): array
    {
        $sisaPerPp = PerintahPembayaranDetail::query()
            ->whereIn('status_baris', PerintahPembayaranDetail::STATUS_MENGUNCI)
            ->whereHas('perintah', fn ($q) => $q->whereIn('status', ['diotorisasi', 'sebagian']))
            ->selectRaw('kode_transaksi, COALESCE(SUM(sisa), 0) AS sisa')
            ->groupBy('kode_transaksi')
            ->pluck('sisa', 'kode_transaksi');

        if ($sisaPerPp->isEmpty()) {
            return [];
        }

        return PerintahPembayaran::whereIn('kode_transaksi', $sisaPerPp->keys())
            ->orderBy('kode_transaksi')
            ->get(['kode_transaksi', 'nomor'])
            ->map(fn ($p) => [
                'kode_transaksi' => (int) $p->kode_transaksi,
                'nomor' => $p->nomor,
                'sisa' => Money::of($sisaPerPp[$p->kode_transaksi] ?? '0'),
            ])
            ->filter(fn ($r) => Money::gtZero($r['sisa']))
            ->values()->all();
    }

    /**
     * Σdebet & Σkredit per akun, ditambah saldo awal. Satu kueri agregat untuk
     * seluruh akun — bukan satu kueri per rekening.
     *
     * @return array<string,array{d:string,k:string}>
     */
    private function saldoPerAkun(): array
    {
        $m = [];
        foreach (JournalLine::selectRaw('kode_coa, COALESCE(SUM(debet),0) d, COALESCE(SUM(kredit),0) k')
            ->groupBy('kode_coa')->get() as $r) {
            $m[$r->kode_coa] = ['d' => Money::of($r->d), 'k' => Money::of($r->k)];
        }

        foreach (OpeningBalance::all(['kode_coa', 'jenis_saldo', 'saldo']) as $o) {
            $cur = $m[$o->kode_coa] ?? ['d' => '0', 'k' => '0'];
            $sisi = $o->jenis_saldo === 'debet' ? 'd' : 'k';
            $cur[$sisi] = Money::add($cur[$sisi], Money::of($o->saldo));
            $m[$o->kode_coa] = $cur;
        }

        return $m;
    }

    /** @param array<string,array{d:string,k:string}> $saldo */
    private function saldoDebet(array $saldo, string $kodeCoa): string
    {
        $s = $saldo[$kodeCoa] ?? ['d' => '0', 'k' => '0'];

        return Money::sub($s['d'], $s['k']);
    }

    /** @param array<string,array{d:string,k:string}> $saldo */
    private function saldoKredit(array $saldo, string $kodeCoa): string
    {
        $s = $saldo[$kodeCoa] ?? ['d' => '0', 'k' => '0'];

        return Money::sub($s['k'], $s['d']);
    }
}
