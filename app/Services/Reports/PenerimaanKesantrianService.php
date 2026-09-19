<?php

namespace App\Services\Reports;

use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PENERIMAAN KESANTRIAN PER PERIODE.
 *
 * Yang dihitung adalah UANG YANG MASUK (setoran terverifikasi), bukan tagihan
 * yang terbit. Keduanya sengaja tidak dicampur: tagihan diakui saat terbit
 * (akrual), penerimaannya bisa menyusul berbulan-bulan kemudian, dan laporan
 * yang mencampurnya akan memberi dua angka berbeda untuk pertanyaan yang sama.
 *
 * Baris = jenis biaya (snapshot perilaku & jenjang diambil dari tagihannya,
 * bukan dari master — master bisa berubah, tagihan yang sudah terbit tidak).
 * Kolom = bulan.
 */
class PenerimaanKesantrianService
{
    /**
     * @return array{
     *     from:string, to:string, bulan:list<array{kunci:string,label:string}>,
     *     baris:list<array>, total_per_bulan:array<string,string>, total:string,
     *     pembanding:array{buku_besar:string,selisih:string,cocok:bool}
     * }
     */
    public function laporan(
        string $from,
        string $to,
        ?string $kodeJenjang = null,
        ?string $perilaku = null,
    ): array {
        $bulan = $this->daftarBulan($from, $to);
        $kunciBulan = array_column($bulan, 'kunci');

        $rows = DB::table('pembayaran_santri as p')
            // LEFT JOIN: setoran yang tak menunjuk tagihan tetap harus terhitung.
            // Kalau di-INNER, uangnya hilang dari laporan tanpa jejak — dan total
            // laporan berhenti sama dengan uang yang benar-benar diterima.
            ->leftJoin('tagihan_santri as t', 'p.id_tagihan', '=', 't.id')
            ->leftJoin('jenis_biaya as j', 't.kode_jenis', '=', 'j.kode')
            ->leftJoin('jenjang as jg', 't.kode_jenjang', '=', 'jg.kode')
            ->where('p.status', 'terverifikasi')
            ->whereBetween('p.tanggal', [$from, $to])
            ->when($kodeJenjang, fn ($q) => $q->where('t.kode_jenjang', $kodeJenjang))
            ->when($perilaku, fn ($q) => $q->where('t.perilaku', $perilaku))
            ->selectRaw("
                t.kode_jenis as kode_jenis,
                j.nama as nama_jenis,
                t.perilaku as perilaku,
                t.kode_jenjang as kode_jenjang,
                jg.nama as nama_jenjang,
                to_char(p.tanggal, 'YYYY-MM') as bulan,
                SUM(p.nominal) as nominal
            ")
            ->groupByRaw("t.kode_jenis, j.nama, t.perilaku, t.kode_jenjang, jg.nama, to_char(p.tanggal, 'YYYY-MM')")
            ->get();

        // Rakit jadi matriks baris × bulan.
        $peta = [];
        foreach ($rows as $r) {
            $kunci = $r->kode_jenis ?? '__tanpa_tagihan__';
            if (! isset($peta[$kunci])) {
                $peta[$kunci] = [
                    'kode_jenis' => $r->kode_jenis,
                    'nama_jenis' => $r->nama_jenis ?? 'Setoran tanpa tagihan',
                    'perilaku' => $r->perilaku,
                    'kode_jenjang' => $r->kode_jenjang,
                    'nama_jenjang' => $r->nama_jenjang ?? ($r->kode_jenjang ?: '—'),
                    'per_bulan' => array_fill_keys($kunciBulan, Money::of('0')),
                    'total' => '0',
                ];
            }
            if (isset($peta[$kunci]['per_bulan'][$r->bulan])) {
                $peta[$kunci]['per_bulan'][$r->bulan] = Money::add($peta[$kunci]['per_bulan'][$r->bulan], $r->nominal);
            }
            $peta[$kunci]['total'] = Money::add($peta[$kunci]['total'], $r->nominal);
        }

        // Urutan: yang bukan jenis biaya (setoran tanpa tagihan) selalu di bawah.
        uasort($peta, function ($a, $b) {
            if (($a['kode_jenis'] === null) !== ($b['kode_jenis'] === null)) {
                return $a['kode_jenis'] === null ? 1 : -1;
            }

            return [$a['nama_jenjang'], $a['nama_jenis']] <=> [$b['nama_jenjang'], $b['nama_jenis']];
        });

        $baris = array_values(array_map(
            fn ($b) => [...$b, 'total' => Money::of($b['total'])],
            $peta,
        ));

        $totalPerBulan = array_fill_keys($kunciBulan, Money::of('0'));
        $total = '0';
        foreach ($baris as $b) {
            foreach ($kunciBulan as $k) {
                $totalPerBulan[$k] = Money::add($totalPerBulan[$k], $b['per_bulan'][$k]);
            }
            $total = Money::add($total, $b['total']);
        }

        return [
            'from' => $from, 'to' => $to,
            'kode_jenjang' => $kodeJenjang, 'perilaku' => $perilaku,
            'bulan' => $bulan,
            'baris' => $baris,
            'total_per_bulan' => $totalPerBulan,
            'total' => Money::of($total),
            'pembanding' => $this->pembandingBukuBesar($from, $to, $total, $kodeJenjang !== null || $perilaku !== null),
        ];
    }

    /**
     * Uji-diri: jumlah setoran harus sama dengan jumlah sisi DEBET seluruh jurnal
     * bersumber `PembayaranSantri` pada rentang yang sama — tiap setoran melahirkan
     * tepat satu jurnal sebesar nominalnya, entah mendebet kas atau titipan dompet.
     *
     * Saat laporan sedang DISARING, perbandingan ini dimatikan: buku besar tak
     * mengenal jenjang maupun perilaku, jadi membandingkan sebagian lawan
     * keseluruhan hanya akan melahirkan lampu merah palsu.
     */
    private function pembandingBukuBesar(string $from, string $to, string $total, bool $disaring): array
    {
        if ($disaring) {
            return ['buku_besar' => null, 'selisih' => null, 'cocok' => true, 'dimatikan' => true];
        }

        $bukuBesar = Money::of(
            DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
                ->where('je.sumber_modul', 'PembayaranSantri')
                ->where('je.status', 'aktif')
                ->whereBetween('je.tanggal', [$from, $to])
                ->sum('jl.debet')
        );
        $selisih = Money::sub($total, $bukuBesar);

        return [
            'buku_besar' => $bukuBesar,
            'selisih' => $selisih,
            'cocok' => Money::isZero($selisih),
            'dimatikan' => false,
        ];
    }

    /** @return list<array{kunci:string,label:string}> */
    private function daftarBulan(string $from, string $to): array
    {
        $mulai = Carbon::parse($from)->startOfMonth();
        $akhir = Carbon::parse($to)->startOfMonth();

        $out = [];
        // Batas 60 bulan: rentang yang lebih panjang dari itu hampir pasti salah
        // ketik, dan tabelnya jadi tak terbaca jauh sebelum berguna.
        while ($mulai->lte($akhir) && count($out) < 60) {
            $out[] = ['kunci' => $mulai->format('Y-m'), 'label' => $mulai->isoFormat('MMM YY')];
            $mulai->addMonth();
        }

        return $out;
    }
}
