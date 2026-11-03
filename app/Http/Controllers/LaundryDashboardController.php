<?php

namespace App\Http\Controllers;

use App\Services\Modules\TagihanLaundryService;
use App\Support\BagianLaundry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * DASHBOARD LAUNDRY (permintaan user 3 Nov 2026) — 12 bulan berakhir di bulan pilihan, ikhwan & akhwat
 * atau salah satunya. Semua angka dihitung langsung dari `laundry`, `laundry_item`, & `tagihan_laundry`;
 * tak ada tabel ringkasan yang bisa basi.
 *
 *  - Pemakaian dihitung menurut tanggal DITERIMA (sama dengan kuota & tagihan);
 *    penyerahan menurut tanggal DISERAHKAN.
 *  - Kelebihan: bulan yang sudah punya baris tagihan_laundry memakai angka itu (kuotanya dijepret saat
 *    disusun); bulan lain (mis. bulan berjalan) = PERKIRAAN dari kuota sekarang, ditandai "≈".
 */
class LaundryDashboardController extends Controller
{
    public function index(Request $request, TagihanLaundryService $svc): View
    {
        $sampai = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('sampai')) ? $request->query('sampai') : now()->format('Y-m');
        $bagian = isset(BagianLaundry::BAGIAN[$request->query('bagian')]) ? $request->query('bagian') : null;
        $jk = $bagian ? [BagianLaundry::BAGIAN[$bagian]['jk']] : ['L', 'P'];

        $akhirBulan = Carbon::createFromFormat('Y-m-d', "{$sampai}-01")->startOfMonth();
        $bulan = collect(range(11, 0))->map(fn ($i) => $akhirBulan->copy()->subMonthsNoOverflow($i)->format('Y-m'))->all();
        $awal = Carbon::createFromFormat('Y-m-d', "{$bulan[0]}-01")->startOfDay();
        $akhir = $akhirBulan->copy()->endOfMonth();

        // ---- Pemakaian per bulan & jenis kelamin (menurut tanggal diterima) ----
        $pakai = DB::table('laundry as l')->join('santri as s', 's.id', '=', 'l.id_santri')
            ->where('l.status', '!=', 'dibatalkan')->whereBetween('l.diterima_pada', [$awal, $akhir])->whereIn('s.jenis_kelamin', $jk)
            ->selectRaw("to_char(l.diterima_pada, 'YYYY-MM') as bln, s.jenis_kelamin as jk, count(*) as kantong, coalesce(sum(l.berat), 0) as kg,
                count(distinct l.id_santri) as santri, sum(case when l.ada_kurang then 1 else 0 end) as kurang,
                coalesce(sum(extract(epoch from (l.selesai_pada - l.diterima_pada))) filter (where l.selesai_pada is not null), 0) as detik_proses,
                count(l.selesai_pada) as n_proses")
            ->groupBy('bln', 'jk')->get();

        // ---- Penyerahan per bulan (menurut tanggal diserahkan) ----
        $serah = DB::table('laundry as l')->join('santri as s', 's.id', '=', 'l.id_santri')
            ->where('l.status', 'diserahkan')->whereBetween('l.diserahkan_pada', [$awal, $akhir])->whereIn('s.jenis_kelamin', $jk)
            ->selectRaw("to_char(l.diserahkan_pada, 'YYYY-MM') as bln, count(*) as kali, count(distinct l.id_santri) as santri")
            ->groupBy('bln')->get()->keyBy('bln');

        // ---- Kelebihan yang sudah disusun / ditagih ----
        $tagih = DB::table('tagihan_laundry as t')->join('santri as s', 's.id', '=', 't.id_santri')
            ->leftJoin('tagihan_santri as ts', 'ts.id', '=', 't.id_tagihan')
            ->whereIn('t.periode', $bulan)->whereIn('s.jenis_kelamin', $jk)
            ->selectRaw("t.periode as bln, count(*) filter (where t.status <> 'dikecualikan') as santri_lebih,
                coalesce(sum(t.kg_lebih) filter (where t.status <> 'dikecualikan'), 0) as kg_lebih,
                coalesce(sum(t.nominal) filter (where t.status = 'terbit'), 0) as ditagih,
                coalesce(sum(t.nominal) filter (where t.status = 'terbit' and ts.status = 'lunas'), 0) as lunas,
                coalesce(sum(t.nominal) filter (where t.status = 'draf'), 0) as draf")
            ->groupBy('t.periode')->get()->keyBy('bln');

        // ---- Perkiraan kelebihan untuk bulan yang belum punya baris tagihan ----
        $tarif = $svc->pengaturan()->tarif_per_kg;
        $tanpaTagihan = array_values(array_diff($bulan, $tagih->keys()->all()));
        $perkiraan = [];
        if ($tanpaTagihan !== []) {
            $perSantri = DB::table('laundry as l')->join('santri as s', 's.id', '=', 'l.id_santri')
                ->where('l.status', '!=', 'dibatalkan')->whereBetween('l.diterima_pada', [$awal, $akhir])->whereIn('s.jenis_kelamin', $jk)
                ->whereIn(DB::raw("to_char(l.diterima_pada, 'YYYY-MM')"), $tanpaTagihan)
                ->selectRaw("to_char(l.diterima_pada, 'YYYY-MM') as bln, l.id_santri, s.kode_jenjang, sum(l.berat) as kg")
                ->groupBy('bln', 'l.id_santri', 's.kode_jenjang')->get();
            foreach ($perSantri as $r) {
                $lebih = max(0, (float) $r->kg - (float) $svc->kuota($r->kode_jenjang));
                $perkiraan[$r->bln] ??= ['santri_lebih' => 0, 'kg_lebih' => 0.0, 'ditagih' => null];
                if ($lebih > 0) {
                    $perkiraan[$r->bln]['santri_lebih']++;
                    $perkiraan[$r->bln]['kg_lebih'] += $lebih;
                }
            }
            foreach ($perkiraan as $b => $p) {
                $perkiraan[$b]['ditagih'] = $tarif !== null ? (float) TagihanLaundryService::nominal(number_format($p['kg_lebih'], 2, '.', ''), $tarif) : null;
            }
        }

        // ---- Satu baris per bulan ----
        $baris = collect($bulan)->map(function ($b) use ($pakai, $serah, $tagih, $perkiraan) {
            $p = $pakai->where('bln', $b);
            $santri = (int) $p->sum('santri');
            $kg = (float) $p->sum('kg');
            $nProses = (int) $p->sum('n_proses');
            $t = $tagih->get($b);
            $s = $serah->get($b);
            $kira = $t ? null : ($perkiraan[$b] ?? null);

            return (object) [
                'bulan' => $b,
                'label' => Carbon::createFromFormat('Y-m-d', "{$b}-01")->locale('id')->translatedFormat('M Y'),
                'kantong' => (int) $p->sum('kantong'), 'kg' => $kg,
                'kg_ikhwan' => (float) $p->where('jk', 'L')->sum('kg'), 'kg_akhwat' => (float) $p->where('jk', 'P')->sum('kg'),
                'santri' => $santri, 'kg_per_santri' => $santri ? $kg / $santri : 0,
                'serah' => (int) ($s->kali ?? 0), 'santri_serah' => (int) ($s->santri ?? 0),
                'serah_per_santri' => ($s->santri ?? 0) ? $s->kali / $s->santri : 0,
                'kurang' => (int) $p->sum('kurang'),
                'jam_proses' => $nProses ? (float) $p->sum('detik_proses') / $nProses / 3600 : null,
                'perkiraan' => $t === null,
                'santri_lebih' => (int) ($t->santri_lebih ?? $kira['santri_lebih'] ?? 0),
                'kg_lebih' => (float) ($t->kg_lebih ?? $kira['kg_lebih'] ?? 0),
                'ditagih' => $t ? (float) $t->ditagih : ($kira['ditagih'] ?? 0),
                'lunas' => (float) ($t->lunas ?? 0),
                'draf' => (float) ($t->draf ?? 0),
            ];
        });

        $ini = $baris->last();
        $lalu = $baris->get(10);

        // ---- Rincian bulan pilihan ----
        [$awalIni, $akhirIni] = TagihanLaundryService::batas($sampai);
        $dasarIni = fn () => DB::table('laundry as l')->join('santri as s', 's.id', '=', 'l.id_santri')
            ->where('l.status', '!=', 'dibatalkan')->whereBetween('l.diterima_pada', [$awalIni, $akhirIni])->whereIn('s.jenis_kelamin', $jk);

        return view('laundry.dashboard', [
            'sampai' => $sampai, 'bagian' => $bagian, 'baris' => $baris, 'ini' => $ini, 'lalu' => $lalu,
            'labelIni' => $akhirBulan->locale('id')->translatedFormat('F Y'),
            'santriAktif' => DB::table('santri')->where('status', 'aktif')->whereIn('jenis_kelamin', $jk)->count(),
            'belumLunas' => (float) DB::table('tagihan_laundry as t')->join('santri as s', 's.id', '=', 't.id_santri')
                ->join('tagihan_santri as ts', 'ts.id', '=', 't.id_tagihan')->where('t.status', 'terbit')
                ->whereNotIn('ts.status', ['lunas', 'batal'])->whereIn('s.jenis_kelamin', $jk)->sum('t.nominal'),
            'perJenjang' => $dasarIni()->leftJoin('jenjang as j', 'j.kode', '=', 's.kode_jenjang')
                ->selectRaw('coalesce(j.nama, s.kode_jenjang) as nama, j.urutan, count(*) as kantong, sum(l.berat) as kg, count(distinct l.id_santri) as santri')
                ->groupBy('j.nama', 's.kode_jenjang', 'j.urutan')->orderBy('j.urutan')->get(),
            'teratas' => $dasarIni()->leftJoin('kelas as k', 'k.id', '=', 's.id_kelas')
                ->selectRaw('s.nama, s.nis, k.nama as kelas, count(*) as kantong, sum(l.berat) as kg')
                ->groupBy('s.id', 's.nama', 's.nis', 'k.nama')->orderByDesc('kg')->limit(10)->get(),
            'pakaian' => $dasarIni()->join('laundry_item as i', 'i.id_laundry', '=', 'l.id')
                ->selectRaw('i.nama_pakaian as nama, sum(i.jumlah) as potong')->groupBy('i.nama_pakaian')->orderByDesc('potong')->limit(10)->get(),
        ]);
    }
}
