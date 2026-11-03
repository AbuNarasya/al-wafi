<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Laundry;
use App\Models\Santri;
use App\Models\TagihanLaundry;
use App\Services\Modules\TagihanLaundryService;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** LAUNDRY tahap 2: Kuota & Tagihan (keuangan) dan Pengaturan Laundry (tarif & kuota) — lihat TagihanLaundryService. */
class LaundryTagihanController extends Controller
{
    public function __construct(private readonly TagihanLaundryService $svc) {}

    /**
     * Bulan lalu (atau yang dipilih): draf / terbit / dikecualikan. Bulan berjalan: PERKIRAAN (belum bisa ditagih).
     */
    public function index(Request $request): View
    {
        $periode = (string) $request->query('periode', now()->subMonthNoOverflow()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode), 404);
        $berjalan = $periode >= now()->format('Y-m');
        $p = $this->svc->pengaturan();

        $baris = $berjalan ? $this->perkiraan($periode) : TagihanLaundry::with(['santri:id,nama,nis,id_kelas,jenis_kelamin', 'santri.kelas:id,nama'])
            ->where('periode', $periode)->get()->sortBy(fn ($t) => $t->santri?->nama)->values();
        // Saringan ikhwan / akhwat (3 Nov 2026) — tampilan saja; penerbitan tetap seluruh draf bulan itu.
        $bagian = isset(\App\Support\BagianLaundry::BAGIAN[$request->query('bagian')]) ? $request->query('bagian') : null;
        if ($bagian) {
            $baris = $baris->filter(fn ($r) => $r->santri?->jenis_kelamin === \App\Support\BagianLaundry::BAGIAN[$bagian]['jk'])->values();
        }

        return view('laundry.tagihan', [
            'periode' => $periode, 'berjalan' => $berjalan, 'baris' => $baris, 'pengaturan' => $p, 'bagian' => $bagian,
            'bulan' => Carbon::createFromFormat('Y-m-d', "{$periode}-01")->locale('id')->translatedFormat('F Y'),
            'tanggalTerbit' => Carbon::createFromFormat('Y-m-d', "{$periode}-01")->addMonthNoOverflow()->day(min(28, (int) $p->hari_terbit)),
            'ringkas' => ['kg' => (float) Laundry::where('status', '!=', 'dibatalkan')->whereBetween('diterima_pada', TagihanLaundryService::batas($periode))->sum('berat'),
                'santri' => Laundry::where('status', '!=', 'dibatalkan')->whereBetween('diterima_pada', TagihanLaundryService::batas($periode))->distinct('id_santri')->count('id_santri')],
        ]);
    }

    public function susun(Request $request): RedirectResponse
    {
        $periode = $request->validate(['periode' => ['required', 'regex:/^\d{4}-\d{2}$/']])['periode'];

        return $this->coba(fn () => $this->svc->susunDraf($periode), fn ($h) => "Draf disusun ulang: {$h['draf']} santri melebihi kuota.");
    }

    public function terbitkan(Request $request): RedirectResponse
    {
        $periode = $request->validate(['periode' => ['required', 'regex:/^\d{4}-\d{2}$/']])['periode'];

        return $this->coba(fn () => $this->svc->terbitkan($periode, $request->user()->id_pengguna),
            fn ($h) => $h['terbit'] ? "{$h['terbit']} tagihan kelebihan laundry terbit — total Rp ".number_format((float) $h['total'], 0, ',', '.').'.' : 'Tidak ada draf untuk diterbitkan.');
    }

    public function kecualikan(Request $request, TagihanLaundry $tagihan): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return $this->coba(fn () => $this->svc->kecualikan($tagihan, $d['alasan'], $request->user()->id_pengguna), fn () => 'Dikecualikan dari tagihan.');
    }

    public function batalKecuali(TagihanLaundry $tagihan): RedirectResponse
    {
        return $this->coba(fn () => $this->svc->batalKecuali($tagihan), fn () => 'Pengecualian ditarik — kembali jadi draf.');
    }

    // ---- Pengaturan (tarif & kuota) ----

    public function pengaturan(): View
    {
        return view('laundry.pengaturan', [
            'p' => $this->svc->pengaturan(), 'perJenjang' => $this->svc->pengaturanJenjang(),
            'jenjang' => Jenjang::orderBy('urutan')->get(['kode', 'nama']),
            // Hanya jenis biaya perilaku Lain-lain yang aktif — jalur penerbitan tagihan lain-lain.
            'opsiJenis' => JenisBiaya::where('status', 'aktif')->orderBy('nama')->get()
                ->filter(fn ($j) => \App\Models\TipeBiaya::perilakuDari($j->tipe) === 'lain')->pluck('nama', 'kode'),
        ]);
    }

    public function simpanPengaturan(Request $request): RedirectResponse
    {
        $jenisSah = JenisBiaya::where('status', 'aktif')->get()->filter(fn ($j) => \App\Models\TipeBiaya::perilakuDari($j->tipe) === 'lain')->pluck('kode')->all();
        $d = $request->validate([
            'tarif_per_kg' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'kuota_bawaan_kg' => ['required', 'numeric', 'min:0', 'max:999'],
            'kode_jenis_bawaan' => ['nullable', Rule::in($jenisSah)],
            'hari_terbit' => ['required', 'integer', 'between:1,28'],
            'terbit_otomatis' => ['nullable', 'boolean'],
            'jenjang' => ['nullable', 'array'], 'jenjang.*.kuota_kg' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'jenjang.*.kode_jenis' => ['nullable', Rule::in($jenisSah)],
        ]);
        $lama = (array) $this->svc->pengaturan();
        DB::transaction(function () use ($d, $request) {
            DB::table('pengaturan_laundry')->updateOrInsert(['id' => 1], [
                'tarif_per_kg' => Money::of($d['tarif_per_kg']), 'kuota_bawaan_kg' => Money::of($d['kuota_bawaan_kg']),
                'kode_jenis_bawaan' => $d['kode_jenis_bawaan'] ?? null, 'hari_terbit' => (int) $d['hari_terbit'],
                'terbit_otomatis' => $request->boolean('terbit_otomatis'), 'diubah_oleh' => $request->user()->id_pengguna, 'updated_at' => now(),
            ]);
            $kodeJenjang = Jenjang::pluck('kode')->all();
            foreach (($d['jenjang'] ?? []) as $kode => $isi) {
                if (! in_array($kode, $kodeJenjang, true)) {
                    continue;
                }
                $kuota = ($isi['kuota_kg'] ?? '') === '' || $isi['kuota_kg'] === null ? null : Money::of($isi['kuota_kg']);
                $jenis = ($isi['kode_jenis'] ?? '') ?: null;
                if ($kuota === null && $jenis === null) {
                    DB::table('pengaturan_laundry_jenjang')->where('kode_jenjang', $kode)->delete();
                } else {
                    DB::table('pengaturan_laundry_jenjang')->updateOrInsert(['kode_jenjang' => $kode], ['kuota_kg' => $kuota, 'kode_jenis' => $jenis, 'updated_at' => now(), 'created_at' => now()]);
                }
            }
        });
        Jejak::catat('ubah_pengaturan_laundry', ['modul' => 'laundry-master', 'id_pengguna' => $request->user()->id_pengguna,
            'detail' => ['lama' => array_intersect_key($lama, array_flip(['tarif_per_kg', 'kuota_bawaan_kg', 'kode_jenis_bawaan', 'hari_terbit', 'terbit_otomatis'])),
                'baru' => array_intersect_key($d, array_flip(['tarif_per_kg', 'kuota_bawaan_kg', 'kode_jenis_bawaan', 'hari_terbit'])) + ['terbit_otomatis' => $request->boolean('terbit_otomatis')]]]);

        return back()->with('status', 'Pengaturan laundry disimpan.');
    }

    /** Nyalakan / matikan terbit otomatis tanpa membuka seluruh Pengaturan Laundry. */
    public function saklarOtomatis(Request $request): RedirectResponse
    {
        $nyala = $request->validate(['aktif' => ['required', 'boolean']])['aktif'];
        $nyala = filter_var($nyala, FILTER_VALIDATE_BOOLEAN);
        $lama = (bool) $this->svc->pengaturan()->terbit_otomatis;
        DB::table('pengaturan_laundry')->where('id', 1)->update([
            'terbit_otomatis' => $nyala, 'diubah_oleh' => $request->user()->id_pengguna, 'updated_at' => now(),
        ]);
        Jejak::catat('ubah_pengaturan_laundry', ['modul' => 'laundry-master', 'id_pengguna' => $request->user()->id_pengguna,
            'detail' => ['lama' => ['terbit_otomatis' => $lama], 'baru' => ['terbit_otomatis' => $nyala]]]);

        return back()->with('status', $nyala ? 'Terbit otomatis DINYALAKAN.' : 'Terbit otomatis DIMATIKAN — tagihan diterbitkan manual.');
    }

    /** Bulan berjalan: siapa yang sudah melewati / mendekati kuota — angka perkiraan, belum ditagih. */
    private function perkiraan(string $periode)
    {
        $pakai = Laundry::where('status', '!=', 'dibatalkan')->whereBetween('diterima_pada', TagihanLaundryService::batas($periode))
            ->selectRaw('id_santri, sum(berat) as kg')->groupBy('id_santri')->pluck('kg', 'id_santri');
        $tarif = $this->svc->pengaturan()->tarif_per_kg;

        return Santri::with('kelas:id,nama')->whereIn('id', $pakai->keys())->get(['id', 'nama', 'nis', 'kode_jenjang', 'id_kelas', 'jenis_kelamin'])
            ->map(function ($s) use ($pakai, $tarif) {
                $kuota = $this->svc->kuota($s->kode_jenjang);
                $kg = Money::of($pakai[$s->id]);
                $lebih = Money::gt($kg, $kuota) ? Money::sub($kg, $kuota) : '0.00';

                return (object) ['santri' => $s, 'kg_total' => $kg, 'kuota_kg' => $kuota, 'kg_lebih' => $lebih,
                    'nominal' => $tarif !== null ? TagihanLaundryService::nominal($lebih, $tarif) : null, 'status' => 'perkiraan'];
            })->sortByDesc(fn ($r) => (float) $r->kg_total / max(0.01, (float) $r->kuota_kg))->values();
    }

    private function coba(\Closure $aksi, \Closure $pesan): RedirectResponse
    {
        try {
            $h = $aksi();
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $pesan($h));
    }
}
