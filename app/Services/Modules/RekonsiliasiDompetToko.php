<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Penjualan;
use App\Models\PermintaanKartu;
use App\Models\RekonsiliasiDompetToko as Rekon;
use App\Models\ReturPenjualan;
use App\Services\Penghubung\KlienDompetToko;
use App\Services\Penghubung\PenghubungTakPasti;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REKONSILIASI MALAM BELANJA KARTU — Fase 3c-2 (keputusan user 20 Okt 2026), dijalankan di instans Wafi Mart.
 *
 * Daftar transaksi penghubung satu tanggal ditarik dari ERP Al Wafi (`GET …/transaksi`) lalu dicocokkan PER NOMOR
 * (tanpa memandang tanggal di toko, supaya struk lewat tengah malam tak salah tanda) dengan:
 *   potong      ↔ penjualan.ref_dompet
 *   kembalikan  ↔ penjualan.ref_dompet_batal · retur_penjualan.ref_dompet · pengembalian yatim (WM-YATIM-…)
 *
 *  • Potong tanpa struk = POTONGAN YATIM → dikembalikan OTOMATIS ke santri (nomor tetap WM-YATIM-<asal>, idempoten).
 *    Kasir saat itu sudah diminta melayani tunai, jadi santri tak membawa barang dengan uang sakunya.
 *  • Pengembalian tanpa retur/batal di toko → ditandai; yang rugi toko, jadi diputus staf (tak ada pemotongan ulang).
 *  • Struk kartu di toko yang tak dikenal Al Wafi → dipastikan dulu (bisa sekadar beda tanggal); tetap tak ada → ditandai.
 */
class RekonsiliasiDompetToko
{
    public const AWALAN_YATIM = 'WM-YATIM-';

    /** Batas mundur saat menjalankan otomatis: tanggal yang belum/ gagal direkonsiliasi dalam N hari terakhir. */
    public const HARI_MUNDUR = 7;

    public function __construct(private readonly KlienDompetToko $klien) {}

    /** @return list<Rekon> tanggal-tanggal sebelum hari ini yang belum direkonsiliasi atau gagal sebelumnya. */
    public function jalankanTertunda(): array
    {
        $sudah = Rekon::where('status', '!=', 'gagal')->where('tanggal', '>=', now()->subDays(self::HARI_MUNDUR)->toDateString())
            ->pluck('tanggal')->map(fn ($t) => $t->toDateString())->all();
        $tanggal = [];
        for ($i = self::HARI_MUNDUR; $i >= 1; $i--) {
            $tgl = now()->subDays($i)->toDateString();
            if (! in_array($tgl, $sudah, true)) {
                $tanggal[] = $tgl;
            }
        }
        // Tanggal yang GAGAL diulang berapa pun umurnya (audit 21 Okt 2026) — dulu yang lebih tua dari 7 hari
        // terlupakan, padahal potongan yatimnya belum kembali ke santri.
        $gagalLama = Rekon::where('status', 'gagal')->where('tanggal', '<', now()->subDays(self::HARI_MUNDUR)->toDateString())
            ->orderBy('tanggal')->pluck('tanggal')->map(fn ($t) => $t->toDateString())->all();

        return array_map(fn ($t) => $this->jalankan($t), array_merge($gagalLama, $tanggal));
    }

    public function jalankan(string $tanggal): Rekon
    {
        if (! KasirService::lewatPenghubung()) {
            throw new AppException(404, 'Rekonsiliasi kartu hanya untuk instans Wafi Mart.');
        }
        if ($tanggal >= now()->toDateString()) {
            throw new AppException(422, 'Rekonsiliasi hanya untuk tanggal yang sudah lewat — transaksi hari ini masih berjalan.');
        }

        // Niat bayar yang ditinggal kasir dalam keadaan tak pasti: pastikan dulu (yang tak terjadi jadi nisan di Al
        // Wafi & "gagal" di sini; yang terjadi muncul sebagai potongan tanpa struk di bawah).
        $kasir = app(KasirService::class);
        PermintaanKartu::whereIn('status', ['menunggu', 'belum_pasti'])->whereDate('created_at', '<=', $tanggal)->get()
            ->each(fn (PermintaanKartu $p) => $kasir->pastikanPermintaan($p));

        try {
            $daftar = collect($this->klien->daftar($tanggal));
        } catch (AppException $e) {
            return $this->simpan($tanggal, ['status' => 'gagal', 'pesan' => 'ERP Al Wafi tak terjangkau: '.$e->getMessage(), 'rincian' => []]);
        }

        $berhasil = $daftar->where('status', 'berhasil');
        $potong = $berhasil->where('jenis', 'potong');
        $kembali = $berhasil->where('jenis', 'kembalikan');

        $adaStruk = Penjualan::whereIn('ref_dompet', $potong->pluck('nomor'))->pluck('ref_dompet')->flip();
        $adaKembali = Penjualan::whereIn('ref_dompet_batal', $kembali->pluck('nomor'))->pluck('ref_dompet_batal')
            ->merge(ReturPenjualan::whereIn('ref_dompet', $kembali->pluck('nomor'))->pluck('ref_dompet'))->flip();
        $sudahKembali = $kembali->groupBy('nomor_asal')->map(fn (Collection $g) => $g->reduce(fn ($s, $t) => Money::add($s, $t['nominal']), '0'));

        $rincian = [];
        $yatim = 0;
        $nilaiYatim = '0';
        $perlu = 0;
        $takPasti = 0;

        foreach ($potong as $t) {
            if (isset($adaStruk[$t['nomor']])) {
                continue;
            }
            $sisa = Money::sub($t['nominal'], $sudahKembali[$t['nomor']] ?? '0');
            if (! Money::gtZero($sisa)) {
                $rincian[] = $this->butir($t, 'Potongan tanpa struk', 'Sudah dikembalikan sebelumnya.');

                continue;
            }
            // KLAIM dulu secara atomik (audit 21 Okt 2026): kasir yang menekan "Cek lagi" tepat saat ini tak boleh
            // menyimpan struknya sementara potongannya dikembalikan (barang gratis). Siapa yang mengunci niat bayar
            // lebih dulu, dia yang menang; simpanJual memeriksa ulang status di bawah kunci yang sama.
            $diklaim = DB::transaction(function () use ($t, $tanggal) {
                $p = PermintaanKartu::where('nomor', $t['nomor'])->lockForUpdate()->first();
                if ($p?->id_penjualan || Penjualan::where('ref_dompet', $t['nomor'])->exists()) {
                    return false;
                }
                $p?->update(['status' => 'gagal', 'pesan' => "Dikembalikan otomatis oleh rekonsiliasi {$tanggal}."]);

                return true;
            });
            if (! $diklaim) {
                continue;   // struknya baru saja tersimpan — bukan yatim
            }
            $nomor = self::AWALAN_YATIM.$t['nomor'];
            try {
                $this->klien->kembalikan($nomor, $t['nomor'], $sisa, "Dikembalikan otomatis: bayar kartu tanpa struk (rekonsiliasi {$tanggal})");
                $yatim++;
                $nilaiYatim = Money::add($nilaiYatim, $sisa);
                $rincian[] = $this->butir($t, 'Potongan tanpa struk', "Dikembalikan otomatis ke santri ({$nomor}).");
            } catch (PenghubungTakPasti) {
                // Bisa jadi SUDAH masuk — jangan dilaporkan "gagal" (staf lalu mengembalikan manual = ganda).
                // Nomornya tetap, jadi aman diulang: tanggal ini ditandai gagal agar diulang otomatis.
                $takPasti++;
                $rincian[] = $this->butir($t, 'Potongan tanpa struk', "Pengembalian BELUM PASTI (jawaban hilang) — diulang otomatis ({$nomor}). Jangan dikembalikan manual.");
            } catch (AppException $e) {
                $perlu++;
                $rincian[] = $this->butir($t, 'Potongan tanpa struk', 'GAGAL dikembalikan: '.$e->getMessage(), true);
            }
        }

        foreach ($kembali as $t) {
            if (isset($adaKembali[$t['nomor']]) || str_starts_with($t['nomor'], self::AWALAN_YATIM)) {
                continue;
            }
            $perlu++;
            $rincian[] = $this->butir($t, 'Uang kembali ke santri tanpa retur/batal tercatat di toko',
                'Periksa: bila barangnya memang diretur, catat penyesuaian stok & jurnal; bila tidak, toko menanggung selisihnya.', true);
        }

        // Struk/retur kartu di toko bertanggal ini yang tak tercantum di daftar Al Wafi.
        $semuaNomor = $daftar->pluck('nomor')->flip();
        foreach ($this->transaksiToko($tanggal) as $t) {
            if (isset($semuaNomor[$t['nomor']])) {
                continue;
            }
            try {
                $h = $this->klien->pastikan($t['nomor'], $t['jenis'], $t['nominal']);
                if (($h['status'] ?? '') === 'berhasil') {
                    continue;   // ada di Al Wafi, hanya tercatat di tanggal lain (lewat tengah malam)
                }
                $masalah = 'Tercatat di toko, TIDAK ada di ERP Al Wafi';
            } catch (AppException $e) {
                $masalah = 'Tercatat di toko, tak bisa dipastikan di ERP Al Wafi ('.$e->getMessage().')';
            }
            $perlu++;
            $rincian[] = $this->butir($t, $masalah, $t['jenis'] === 'potong'
                ? 'Belanja kartu ini belum terbayar dari dompet santri — tagih ulang atau putuskan sebagai kerugian toko.'
                : 'Pengembalian ini belum sampai ke santri — kembalikan manual lewat staf ERP Al Wafi.', true);
        }

        $bersih = $berhasil->reduce(fn ($s, $t) => $t['jenis'] === 'potong' ? Money::add($s, $t['nominal']) : Money::sub($s, $t['nominal']), '0');

        return $this->simpan($tanggal, [
            // Ada pengembalian yang belum pasti → "gagal" supaya diulang otomatis (nomornya idempoten di Al Wafi).
            'status' => $takPasti > 0 ? 'gagal' : ($perlu > 0 ? 'perlu_diputus' : ($yatim > 0 ? 'dibereskan' : 'cocok')),
            'jumlah_transaksi' => $berhasil->count(), 'bersih_al_wafi' => $bersih,
            'jumlah_yatim' => $yatim, 'nilai_yatim' => $nilaiYatim, 'perlu_diputus' => $perlu,
            'rincian' => $rincian, 'pesan' => $takPasti > 0 ? "{$takPasti} pengembalian belum pasti — diulang otomatis malam berikutnya / tombol Jalankan." : null,
        ]);
    }

    /** Staf menandai tanggal yang perlu diputus sudah ditangani. */
    public function tangani(int $id, string $catatan, int $idPengguna): Rekon
    {
        $r = Rekon::findOrFail($id);
        if ($r->status !== 'perlu_diputus') {
            throw new AppException(422, 'Hanya rekonsiliasi berstatus "perlu diputus" yang perlu ditangani.');
        }
        $r->update(['ditangani_oleh' => $idPengguna, 'ditangani_pada' => now(), 'catatan_tangani' => mb_substr(trim($catatan), 0, 250)]);

        return $r;
    }

    /** @return list<array{nomor:string, jenis:string, nominal:string}> */
    private function transaksiToko(string $tanggal): array
    {
        $jual = Penjualan::where('metode', 'dompet')->whereNotNull('ref_dompet')->whereDate('waktu', $tanggal)->get(['ref_dompet', 'total'])
            ->map(fn ($j) => ['nomor' => $j->ref_dompet, 'jenis' => 'potong', 'nominal' => Money::of($j->total)]);
        $batal = Penjualan::whereNotNull('ref_dompet_batal')->whereDate('dibatalkan_pada', $tanggal)->get(['ref_dompet_batal', 'total'])
            ->map(fn ($j) => ['nomor' => $j->ref_dompet_batal, 'jenis' => 'kembalikan', 'nominal' => Money::of($j->total)]);
        $retur = ReturPenjualan::whereNotNull('ref_dompet')->whereDate('waktu', $tanggal)->get(['ref_dompet', 'total'])
            ->map(fn ($r) => ['nomor' => $r->ref_dompet, 'jenis' => 'kembalikan', 'nominal' => Money::of($r->total)]);

        return $jual->concat($batal)->concat($retur)->values()->all();
    }

    private function butir(array $t, string $masalah, string $tindakan, bool $perluDiputus = false): array
    {
        return ['nomor' => $t['nomor'], 'jenis' => $t['jenis'], 'nominal' => Money::of($t['nominal']),
            'masalah' => $masalah, 'tindakan' => $tindakan, 'perlu_diputus' => $perluDiputus];
    }

    private function simpan(string $tanggal, array $isi): Rekon
    {
        $r = Rekon::firstOrNew(['tanggal' => $tanggal]);
        $statusLama = $r->exists ? $r->status : null;
        $r->fill($isi + ['jumlah_transaksi' => 0, 'bersih_al_wafi' => '0', 'jumlah_yatim' => 0, 'nilai_yatim' => '0', 'perlu_diputus' => 0]);
        $r->dijalankan_pada = now();
        // Diulang dengan hasil lain → penanganan lama tak lagi berlaku.
        if ($statusLama !== null && $statusLama !== $r->status) {
            $r->fill(['ditangani_oleh' => null, 'ditangani_pada' => null, 'catatan_tangani' => null]);
        }
        $r->save();

        return $r;
    }
}
