<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Http\Requests\JenisBiayaRequest;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JenisBiaya;
use App\Services\Modules\JenisBiayaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Jenis Biaya (registrasi, uang pangkal, SPP, lain). Controller tipis →
 * JenisBiayaService.
 */
class JenisBiayaController extends Controller
{
    public function __construct(private readonly JenisBiayaService $service) {}

    public function index(): View
    {
        return view('jenis-biaya.index', ['rows' => $this->service->list()]);
    }

    public function create(): View
    {
        return view('jenis-biaya.form', ['jb' => new JenisBiaya(['status' => 'aktif', 'tipe' => 'registrasi']), 'baru' => true, ...$this->opsi()]);
    }

    /**
     * Duplikat master ke tahun ajaran baru. Halaman ini SELALU menampilkan
     * pratinjau lebih dulu — menyalin master tarif diam-diam terlalu berisiko:
     * kode barunya ditebak program dan bisa bentrok dengan yang sudah ada.
     */
    public function duplikatForm(Request $request): View
    {
        $opsiTa = (new \App\Services\Modules\TahunAjaranService)->opsiAktif()
            + \App\Models\TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all();

        $sumber = (string) $request->query('sumber', '');
        $tujuan = (string) $request->query('tujuan', '');
        $siap = $sumber !== '' && $tujuan !== '' && $sumber !== $tujuan && isset($opsiTa[$sumber], $opsiTa[$tujuan]);

        return view('jenis-biaya.duplikat', [
            'opsiTa' => $opsiTa,
            'sumber' => $sumber,
            'tujuan' => $tujuan,
            'rencana' => $siap ? $this->service->pratinjauDuplikat($sumber, $tujuan) : null,
        ]);
    }

    public function duplikat(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sumber' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'tujuan' => ['required', 'string', 'different:sumber', 'exists:tahun_ajaran,kode'],
        ]);

        try {
            $hasil = $this->service->duplikat($data['sumber'], $data['tujuan']);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = "{$hasil['disalin']} jenis biaya disalin ke T.A {$data['tujuan']}.";
        if ($hasil['dilewati'] > 0) {
            $pesan .= " {$hasil['dilewati']} baris dilewati karena sudah ada.";
        }

        return redirect()->route('jenis_biaya.index')->with('status', $pesan);
    }

    public function store(JenisBiayaRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->tersimpan());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('jenis_biaya.index')->with('status', 'Jenis biaya berhasil ditambahkan.');
    }

    public function edit(string $kode): View
    {
        return view('jenis-biaya.form', ['jb' => $this->service->get($kode), 'baru' => false, ...$this->opsi()]);
    }

    public function update(JenisBiayaRequest $request, string $kode): RedirectResponse
    {
        try {
            $this->service->update($kode, $request->tersimpan());
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('jenis_biaya.index')->with('status', 'Jenis biaya berhasil diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        try {
            $this->service->remove($kode);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('jenis_biaya.index')->with('status', 'Jenis biaya berhasil dihapus.');
    }

    private function opsi(): array
    {
        $coa = CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return [
            'coaWajib' => ['' => '— pilih —'] + $coa,
            'coaOpsional' => ['' => '— (opsional) —'] + $coa,
            'unitOptions' => ['' => '— pilih unit —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => "{$u->kode_unit} — {$u->nama_unit}"])->all(),
            'taOptions' => ['' => '— pilih tahun ajaran —'] + (new \App\Services\Modules\TahunAjaranService)->opsiAktif(),
        ];
    }
}
