<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\Karyawan;
use App\Services\Modules\PinjamanKaryawanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pinjaman Karyawan. Controller tipis → PinjamanKaryawanService, yang memegang
 * seluruh aturan jurnalnya (termasuk perbedaan tunai vs potong gaji).
 */
class PinjamanKaryawanController extends Controller
{
    public function __construct(private readonly PinjamanKaryawanService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        return view('pinjaman-karyawan.index', [
            'rows' => $this->service->list($status ?: null, $q ?: null)->paginate(25)->withQueryString(),
            'q' => $q,
            'filter' => ['status' => $status],
            'opsiStatus' => ['aktif' => 'Aktif', 'lunas' => 'Lunas', 'void' => 'Void'],
        ]);
    }

    public function create(): View
    {
        return view('pinjaman-karyawan.create', $this->opsi());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_karyawan' => ['required', 'string'],
            'tanggal' => ['required', 'date'],
            'pokok' => ['required', 'numeric', 'gt:0'],
            'kode_coa_piutang' => ['required', 'string'],
            'kode_rekening' => ['nullable', 'string'],
            'posting_pencairan' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);
        $data['termin'] = $this->terminDari($request);

        try {
            $rec = $this->service->create($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pinjaman_karyawan.show', $rec->id)->with('status', "Pinjaman {$rec->nomor} dicatat.");
    }

    public function show(int $id): View
    {
        try {
            $rec = $this->service->ambil($id);
        } catch (AppException $e) {
            abort($e->status, $e->getMessage());
        }

        return view('pinjaman-karyawan.show', [
            'rec' => $rec,
            'rekeningOptions' => $this->rekeningOptions(),
            'bebanOptions' => CoaDetail::where('kode_coa', 'like', '5%')->orderBy('kode_coa')
                ->get()->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'caraOptions' => PinjamanKaryawanService::CARA,
        ]);
    }

    public function bayar(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'cara' => ['required', 'string'],
            'kode_rekening' => ['nullable', 'string'],
            'kode_coa_lawan' => ['nullable', 'string'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->bayar($id, $data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pinjaman_karyawan.show', $id)->with('status', 'Cicilan tercatat.');
    }

    public function aturTermin(Request $request, int $id): RedirectResponse
    {
        try {
            $this->service->aturTermin($id, $this->terminDari($request));
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pinjaman_karyawan.show', $id)->with('status', 'Jadwal termin diperbarui.');
    }

    /** @return list<array{nominal:string,jatuh_tempo:string,keterangan:?string}> */
    private function terminDari(Request $request): array
    {
        $hasil = [];
        foreach ((array) $request->input('termin', []) as $t) {
            if (trim((string) ($t['nominal'] ?? '')) === '' && trim((string) ($t['jatuh_tempo'] ?? '')) === '') {
                continue; // baris kosong di form diabaikan
            }
            $hasil[] = [
                'nominal' => (string) ($t['nominal'] ?? '0'),
                'jatuh_tempo' => (string) ($t['jatuh_tempo'] ?? ''),
                'keterangan' => $t['keterangan'] ?? null,
            ];
        }

        return $hasil;
    }

    /** @return array<string,mixed> */
    private function opsi(): array
    {
        return [
            'karyawanOptions' => Karyawan::where('status', 'aktif')->orderBy('nama')
                ->get()->mapWithKeys(fn ($k) => [$k->kode => "{$k->kode} — {$k->nama}"])->all(),
            'piutangOptions' => CoaDetail::where('kode_coa', 'like', '1%')->orderBy('kode_coa')
                ->get()->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
            'rekeningOptions' => $this->rekeningOptions(),
        ];
    }

    /** @return array<string,string> */
    private function rekeningOptions(): array
    {
        return BankAccount::with('coa')->where('status', 'aktif')->orderBy('kode_coa')
            ->get()->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->kode_coa} — {$r->nama_rekening}"])->all();
    }
}
