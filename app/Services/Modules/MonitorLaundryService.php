<?php

namespace App\Services\Modules;

use App\Models\Santri;
use App\Support\BagianLaundry;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MONITOR KELEBIHAN LAUNDRY (paket 2, keputusan user 3 Nov 2026) — tiga tampilan dari data yang sudah ada,
 * tanpa tabel ringkasan:
 *
 *  - pemakaian : bulan pilihan, per santri — terpakai vs kuota matriks (jenjang × ikhwan/akhwat), lebih & perkiraan;
 *  - tagihan   : tagihan kelebihan yang sudah TERBIT per periode, lunas / belum (tujuan klik kartu dashboard);
 *  - tunggakan : per santri — tagihan kelebihan belum lunas yang SUDAH LEWAT jatuh tempo, berapa periode & rupiah.
 *
 * Tagihan kelebihan = baris `tagihan_laundry` status terbit; status bayarnya dibaca dari `tagihan_santri`.
 */
class MonitorLaundryService
{
    public function __construct(private readonly TagihanLaundryService $tagihan = new TagihanLaundryService) {}

    /** @param array{bagian?:?string, jenjang?:?string, kelas?:?string, q?:?string} $f */
    private function santriSaring(Builder $q, array $f, string $s = 's'): Builder
    {
        $jk = ($f['bagian'] ?? null) && isset(BagianLaundry::BAGIAN[$f['bagian']]) ? [BagianLaundry::BAGIAN[$f['bagian']]['jk']] : ['L', 'P'];

        return $q->whereIn("{$s}.jenis_kelamin", $jk)
            ->when($f['jenjang'] ?? null, fn ($w, $v) => $w->where("{$s}.kode_jenjang", $v))
            ->when($f['kelas'] ?? null, fn ($w, $v) => $w->where("{$s}.id_kelas", (int) $v))
            ->when(trim((string) ($f['q'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where("{$s}.nama", 'ilike', '%'.trim($f['q']).'%')
                ->orWhere("{$s}.nis", 'ilike', '%'.trim($f['q']).'%')));
    }

    /**
     * Pemakaian satu bulan per santri. `$f['saring']`: over (lebih dari kuota) | dekat (≥ 80%) | kosong = semua.
     *
     * @return Collection<int,object{id:int,nama:string,nis:?string,kelas:?string,jk:string,kantong:int,kg:float,kuota:float,lebih:float,persen:float,perkiraan:?float}>
     */
    public function pemakaian(string $periode, array $f = []): Collection
    {
        [$awal, $akhir] = TagihanLaundryService::batas($periode);
        $tarif = $this->tagihan->pengaturan()->tarif_per_kg;
        $rows = $this->santriSaring(DB::table('laundry as l')->join('santri as s', 's.id', '=', 'l.id_santri')
            ->leftJoin('kelas as k', 'k.id', '=', 's.id_kelas')
            ->where('l.status', '!=', 'dibatalkan')->whereBetween('l.diterima_pada', [$awal, $akhir]), $f)
            ->selectRaw('s.id, s.nama, s.nis, s.kode_jenjang, s.jenis_kelamin as jk, k.nama as kelas, count(*) as kantong, sum(l.berat) as kg')
            ->groupBy('s.id', 's.nama', 's.nis', 's.kode_jenjang', 's.jenis_kelamin', 'k.nama')->get();

        return $rows->map(function ($r) use ($tarif) {
            $kuota = (float) $this->tagihan->kuota($r->kode_jenjang, $r->jk);
            $lebih = max(0, (float) $r->kg - $kuota);

            return (object) ['id' => $r->id, 'nama' => $r->nama, 'nis' => $r->nis, 'kelas' => $r->kelas, 'jk' => $r->jk,
                'kantong' => (int) $r->kantong, 'kg' => (float) $r->kg, 'kuota' => $kuota, 'lebih' => $lebih,
                'persen' => $kuota > 0 ? (float) $r->kg / $kuota * 100 : 100,
                'perkiraan' => $tarif !== null && $lebih > 0 ? (float) TagihanLaundryService::nominal(number_format($lebih, 2, '.', ''), $tarif) : null];
        })->filter(fn ($r) => match ($f['saring'] ?? '') {
            'over' => $r->lebih > 0,
            'dekat' => $r->persen >= 80,
            default => true,
        })->sortByDesc('persen')->values();
    }

    /** Dasar tagihan kelebihan yang sudah terbit (+ status bayarnya). */
    private function dasarTagihan(array $f): Builder
    {
        return $this->santriSaring(DB::table('tagihan_laundry as t')->join('santri as s', 's.id', '=', 't.id_santri')
            ->join('tagihan_santri as ts', 'ts.id', '=', 't.id_tagihan')->leftJoin('kelas as k', 'k.id', '=', 's.id_kelas')
            ->where('t.status', 'terbit')->whereNotIn('ts.status', ['batal', 'dihapus']), $f);
    }

    /**
     * Tagihan kelebihan per periode. `$f['status']`: lunas | belum | kosong = semua; `dari`/`sampai` = YYYY-MM (opsional).
     *
     * @return Collection<int,object>
     */
    public function tagihan(array $f = []): Collection
    {
        return $this->dasarTagihan($f)
            ->when($f['dari'] ?? null, fn ($w, $v) => $w->where('t.periode', '>=', $v))
            ->when($f['sampai'] ?? null, fn ($w, $v) => $w->where('t.periode', '<=', $v))
            ->when(($f['status'] ?? '') === 'lunas', fn ($w) => $w->where('ts.status', 'lunas'))
            ->when(($f['status'] ?? '') === 'belum', fn ($w) => $w->whereIn('ts.status', ['belum_bayar', 'sebagian']))
            ->selectRaw('t.id, t.periode, t.kg_total, t.kuota_kg, t.kg_lebih, t.nominal, ts.id as id_tagihan, ts.sisa, ts.status as status_bayar,
                ts.jatuh_tempo, s.id as id_santri, s.nama, s.nis, s.jenis_kelamin as jk, k.nama as kelas')
            ->orderByDesc('t.periode')->orderBy('s.nama')->get();
    }

    /**
     * Tunggakan per santri: tagihan kelebihan belum lunas yang SUDAH LEWAT jatuh tempo (keputusan user: penahanan
     * pengambilan menghitung yang sudah jatuh tempo saja).
     *
     * @return Collection<int,object{id_santri:int,nama:string,nis:?string,kelas:?string,jk:string,periode:list<string>,jumlah:int,sisa:string,terlama:?string}>
     */
    public function tunggakan(array $f = []): Collection
    {
        return $this->dasarTagihan($f)->whereIn('ts.status', ['belum_bayar', 'sebagian'])->where('ts.sisa', '>', 0)
            ->when($f['id_santri'] ?? null, fn ($w, $v) => $w->where('s.id', (int) $v))
            ->whereNotNull('ts.jatuh_tempo')->whereDate('ts.jatuh_tempo', '<', Carbon::today())
            ->selectRaw("s.id as id_santri, s.nama, s.nis, s.jenis_kelamin as jk, k.nama as kelas, count(*) as jumlah, sum(ts.sisa) as sisa,
                min(ts.jatuh_tempo) as terlama, string_agg(t.periode, ',' order by t.periode) as periode")
            ->groupBy('s.id', 's.nama', 's.nis', 's.jenis_kelamin', 'k.nama')
            ->orderByDesc('jumlah')->orderByDesc('sisa')->get()
            ->map(function ($r) {
                $r->periode = explode(',', (string) $r->periode);
                $r->jumlah = (int) $r->jumlah;
                $r->sisa = Money::of($r->sisa);

                return $r;
            });
    }

    /**
     * Santri TERTAHAN (paket 3): menunggak ≥ N periode yang sudah lewat jatuh tempo, N = pengaturan
     * `tahan_setelah_periode` (0 = mati). Null = boleh mengambil cucian.
     */
    public function tertahan(Santri $santri): ?object
    {
        $n = (int) ($this->tagihan->pengaturan()->tahan_setelah_periode ?? 0);
        if ($n < 1) {
            return null;
        }
        $t = $this->tunggakanSantri($santri);

        return $t && $t->jumlah >= $n ? $t : null;
    }

    /** Ringkasan satu santri (dipakai paket 3: penahanan pengambilan & penanda portal). */
    public function tunggakanSantri(Santri $santri): ?object
    {
        return $this->tunggakan(['id_santri' => $santri->id])->first();
    }
}
