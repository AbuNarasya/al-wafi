<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TipeBiaya;
use App\Services\Modules\SppService;
use App\Support\Money;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SPP — terbitkan (generate) tagihan SPP satu periode untuk seluruh santri aktif
 * (akrual, satu jurnal per jenis), nominal khusus per santri, dan setoran
 * prabayar. TARIF-nya sendiri terpusat di master Jenis Biaya (tipe `spp`),
 * bukan lagi di halaman ini. Controller tipis → SppService.
 */
class SppController extends Controller
{
    public function __construct(private readonly SppService $service) {}

    public function index(Request $request): View
    {
        $periode = $request->query('periode');
        $pratinjau = null;
        $periksa = null;
        if ($periode) {
            try {
                // Diperiksa DULU: periode tanpa tahun ajaran ditolak di sini,
                // bukan nanti saat tombol Terbitkan ditekan.
                $periksa = $this->service->periksaPeriode($periode);
                $pratinjau = $this->service->pratinjau($periode);
            } catch (AppException $e) {
                session()->flash('error', $e->getMessage());
            }
        }

        return view('spp.index', [
            'sppJenis' => JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('spp'))->where('status', 'aktif')->orderBy('kode')->get()
                ->mapWithKeys(fn ($j) => [$j->kode => "{$j->kode} — {$j->nama}"])->all(),
            'periode' => $periode,
            'pratinjau' => $pratinjau,
            // Tahun ajaran periode ini + apakah ia lintas tahun — dipakai layar
            // untuk menyebut tahunnya dan meminta alasan bila perlu.
            'periksa' => $periksa,
            'rekapJenjang' => $pratinjau === null ? [] : $this->rekapJenjang($pratinjau),
            // "NIS - Nama - Jenjang - Tingkat" — nama saja tak cukup membedakan
            // santri yang namanya mirip (lihat Referensi::santri).
            'santriOptions' => Referensi::santri(),
            'rekeningOptions' => BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
        ]);
    }

    /**
     * Rekap pratinjau per JENJANG — berapa rupiah yang akan terbit di masing-masing.
     *
     * Angka totalnya hanya menjumlahkan baris yang SIAP; yang "tanpa tarif" ikut
     * dihitung terpisah supaya jenjang yang sebagian selnya belum diisi tidak
     * tampak bertotal kecil tanpa sebab yang terlihat.
     *
     * Diurutkan menurut urutan master Jenjang (SDTQ → SMP → SMA), bukan abjad
     * kode: itulah urutan yang dipakai di seluruh layar lain.
     *
     * @param  array<int,array<string,mixed>>  $pratinjau
     * @return list<array{nama:string, siap:int, total:string, tanpa_tarif:int, sudah_ada:int}>
     */
    private function rekapJenjang(array $pratinjau): array
    {
        $urutan = Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();

        $per = [];
        foreach ($pratinjau as $p) {
            $kode = $p['kode_jenjang'] ?? null;
            $per[$kode] ??= ['nama' => $urutan[$kode] ?? ($kode ?: 'Tanpa jenjang'),
                'siap' => 0, 'total' => '0', 'tanpa_tarif' => 0, 'sudah_ada' => 0];

            if ($p['status'] === 'siap') {
                $per[$kode]['siap']++;
                $per[$kode]['total'] = Money::add($per[$kode]['total'], $p['nominal']);
            } elseif ($p['status'] === 'tanpa_tarif') {
                $per[$kode]['tanpa_tarif']++;
            } else {
                $per[$kode]['sudah_ada']++;
            }
        }

        // Susun ulang mengikuti urutan master; jenjang tak dikenal ditaruh terakhir.
        $hasil = [];
        foreach (array_keys($urutan) as $kode) {
            if (isset($per[$kode])) {
                $hasil[] = $per[$kode];
                unset($per[$kode]);
            }
        }

        return [...$hasil, ...array_values($per)];
    }

    /** Nominal SPP khusus per santri (beasiswa/keringanan/jalur tahfizh). Kosong = kembali ke tarif jenjang. */
    public function setNominalKhusus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'nominal_spp' => ['nullable', 'numeric', 'min:0'],
            'keterangan_spp' => ['nullable', 'string', 'max:255'],
        ]);
        $santri = Santri::findOrFail($data['id_santri']);
        $kosong = ($data['nominal_spp'] ?? '') === '';
        // Aturannya di service: pintunya dua (di sini & form penagihan PPSB).
        $this->service->setNominalKhusus($santri, $data['nominal_spp'] ?? null, $data['keterangan_spp'] ?? null);

        return back()->with('status', $kosong
            ? 'Nominal khusus dihapus — santri kembali memakai tarif jenjangnya.'
            : "Nominal SPP khusus untuk {$santri->nama} tersimpan.");
    }

    /** Setoran SPP / Prabayar: lunasi tunggakan tertua dulu, kelebihannya jadi saldo prabayar. */
    public function prabayar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'tanggal' => ['required', 'date'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'sumber' => ['required', Rule::in(['kas', 'dompet_wali'])],
            'kode_rekening' => ['nullable', 'required_if:sumber,kas', 'string', 'exists:bank_accounts,kode_coa'],
        ]);
        $data['kode_rekening'] = $data['sumber'] === 'kas' ? $data['kode_rekening'] : null;

        try {
            $h = $this->service->prabayar($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = 'Setoran diterima: Rp '.number_format((float) ($h['dipakai_tunggakan'] ?? 0), 0, ',', '.').' melunasi tunggakan, Rp '
            .number_format((float) ($h['jadi_prabayar'] ?? 0), 0, ',', '.').' menjadi saldo prabayar'
            .(isset($h['referensi']) ? " ({$h['referensi']})." : '.');

        return back()->with('status', $pesan);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'tanggal' => ['required', 'date'],
            // Wajibnya ditegakkan di service, bukan di sini: hanya service yang
            // tahu periode ini lintas tahun ajaran atau bukan.
            'alasan_lintas_ta' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $hasil = $this->service->generate($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $pesan = "Tagihan SPP periode {$data['periode']} (T.A {$hasil['tahun_ajaran']}) berhasil diterbitkan.";
        if ($hasil['lintas_ta']) {
            $pesan .= ' Penerbitan LINTAS tahun ajaran ini tercatat di log aktivitas beserta alasannya.';
        }

        return redirect()->route('spp.index', ['periode' => $data['periode']])->with('status', $pesan);
    }
}
