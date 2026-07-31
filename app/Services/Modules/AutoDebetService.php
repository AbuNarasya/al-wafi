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
 * keluarga yang opt-in (Wali.auto_debet). Tunggakan tertua dulu, tak pernah
 * minus, hanya Dompet Wali.
 *
 * REGISTRASI dikecualikan sama sekali: itu biaya tahap pendaftaran yang disetor
 * di meja PPSB, dan pelunasannya yang memajukan tahap calon. Membayarnya lewat
 * proses latar akan memajukan tahap tanpa ada petugas yang menyaksikannya.
 *
 * SEBAGIAN boleh untuk tagihan yang memang bisa diangsur (uang pangkal punya
 * modul Angsurannya sendiri) — TETAPI TIDAK UNTUK SPP. SPP dipotong penuh atau
 * tidak sama sekali; yang saldonya kurang dibiarkan menggantung utuh sampai
 * dompetnya diisi, lalu terpotong penuh dengan sendirinya (verifikasi top-up
 * memanggil kembali auto-debet — lihat DompetService::verifikasiTopUp).
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
                // REGISTRASI tak pernah ikut auto-debet. Biaya itu milik tahap
                // PENDAFTARAN — disetor di meja PPSB sebagai syarat calon boleh
                // maju, dan pelunasannyalah yang memajukan tahapnya. Membiarkan
                // dompet memotongnya diam-diam memindahkan keputusan itu ke
                // proses latar yang tak dilihat petugas PPSB.
                ->where('perilaku', '!=', 'registrasi')
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

                if ($t->perilaku === 'spp') {
                    // SPP: penuh atau tidak sama sekali. `continue` — bukan `break`
                    // — supaya satu SPP yang tak terjangkau tidak ikut membekukan
                    // tagihan lain keluarga yang sama yang saldonya cukup.
                    // Setoran yang belum diverifikasi juga menahan: sisanya masih
                    // bisa berubah, jadi "penuh" belum tentu benar-benar penuh.
                    if (Money::gtZero($menunggu) || Money::lt($saldo, $t->sisa)) {
                        continue;
                    }
                    $bayar = Money::of($t->sisa);
                } else {
                    $bayar = Money::lt($ruang, $saldo) ? $ruang : $saldo;
                }

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
