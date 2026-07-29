<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Services\Modules\SppService;
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
        if ($periode) {
            try {
                $pratinjau = $this->service->pratinjau($periode);
            } catch (AppException $e) {
                session()->flash('error', $e->getMessage());
            }
        }

        return view('spp.index', [
            'sppJenis' => JenisBiaya::whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku('spp'))->where('status', 'aktif')->orderBy('kode')->get()
                ->mapWithKeys(fn ($j) => [$j->kode => "{$j->kode} — {$j->nama}"])->all(),
            'periode' => $periode,
            'pratinjau' => $pratinjau,
            'santriOptions' => \App\Models\Santri::orderBy('nama')->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->nama.($s->nis ? " ({$s->nis})" : '')])->all(),
            'rekeningOptions' => \App\Models\BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
        ]);
    }

    /** Nominal SPP khusus per santri (beasiswa/keringanan/jalur tahfizh). Kosong = kembali ke tarif jenjang. */
    public function setNominalKhusus(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'nominal_spp' => ['nullable', 'numeric', 'min:0'],
            'keterangan_spp' => ['nullable', 'string', 'max:255'],
        ]);
        $santri = \App\Models\Santri::findOrFail($id);
        $kosong = ($data['nominal_spp'] ?? '') === '';
        $santri->update([
            'nominal_spp' => $kosong ? null : $data['nominal_spp'],
            'keterangan_spp' => $kosong ? null : ($data['keterangan_spp'] ?: null),
        ]);

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
        ]);

        try {
            $hasil = $this->service->generate($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $jml = is_array($hasil) ? ($hasil['jumlah'] ?? count($hasil)) : 0;

        return redirect()->route('spp.index', ['periode' => $data['periode']])
            ->with('status', "Tagihan SPP periode {$data['periode']} berhasil diterbitkan.");
    }
}
