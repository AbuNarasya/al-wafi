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
        ];
    }
}
