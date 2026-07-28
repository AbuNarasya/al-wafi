<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Services\Ppsb\BayarDompet;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AUTO-DEBET — memotong saldo Dompet Wali untuk melunasi tagihan santri bagi
 * keluarga yang opt-in (Wali.auto_debet). Tunggakan tertua dulu, boleh sebagian,
 * tak pernah minus, hanya Dompet Wali.
 */
class AutoDebetService
{
    public function setIzin(int $idWali, bool $aktif): Wali
    {
        $wali = Wali::find($idWali);
        if (! $wali) {
            throw new AppException(404, 'Wali tidak ditemukan.');
        }
        $wali->update(['auto_debet' => $aktif]);

        return $wali;
    }

    /**
     * Jalankan auto-debet untuk seluruh keluarga yang mengizinkan (atau subset).
     * @param  array<int>|null  $hanyaWali
     */
    public function jalankan(int $idPengguna, ?string $tanggal = null, ?array $hanyaWali = null): array
    {
        $tanggal ??= Carbon::now()->toDateString();
        $waliList = Wali::where('auto_debet', true)->where('status', 'aktif')
            ->when($hanyaWali, fn ($q) => $q->whereIn('id', $hanyaWali))
            ->whereHas('dompet', fn ($q) => $q->where('saldo', '>', 0))
            ->with('dompet')->get();

        $jumlahTagihan = 0;
        $totalDibayar = '0';
        $rincian = [];

        foreach ($waliList as $w) {
            if (! $w->dompet) {
                continue;
            }
            $saldo = Money::of($w->dompet->saldo);
            if (Money::lte($saldo, '0')) {
                continue;
            }

            $tagihanList = TagihanSantri::whereHas('santri', fn ($q) => $q->where('id_wali', $w->id)->where('status', 'aktif'))
                ->whereIn('status', ['belum_bayar', 'sebagian'])
                ->with(['jenis', 'santri'])
                ->orderBy('jatuh_tempo')->orderBy('periode')->orderBy('id')->get();

            foreach ($tagihanList as $t) {
                if (Money::lte($saldo, '0')) {
                    break;
                }
                $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->sum('nominal');
                $ruang = Money::sub($t->sisa, $menunggu);
                if (Money::lte($ruang, '0')) {
                    continue;
                }
                $bayar = Money::lt($ruang, $saldo) ? $ruang : $saldo;
                if (Money::lte($bayar, '0')) {
                    continue;
                }

                DB::transaction(fn () => BayarDompet::bayarTagihanDariDompet($t, [
                    'id_dompet' => $w->dompet->id, 'nominal' => (string) $bayar, 'tanggal' => $tanggal,
                    'id_pengguna' => $idPengguna, 'namaSantri' => $t->santri->nama, 'otomatis' => true,
                ]));

                $saldo = Money::sub($saldo, $bayar);
                $totalDibayar = Money::add($totalDibayar, $bayar);
                $jumlahTagihan++;
                $rincian[] = ['wali' => $w->nama, 'santri' => $t->santri->nama, 'tagihan' => "{$t->jenis->nama}".($t->periode ? " {$t->periode}" : ''), 'nominal' => (string) $bayar];
            }
        }

        return [
            'keluarga' => count(array_unique(array_column($rincian, 'wali'))),
            'tagihan' => $jumlahTagihan, 'total' => $totalDibayar, 'rincian' => $rincian,
        ];
    }
}
