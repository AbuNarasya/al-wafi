<?php

namespace App\Http\Controllers;

use App\Models\Bagian;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Karyawan (ringkas). Dibuat untuk menopang modul Pinjaman Karyawan;
 * data kepegawaian sesungguhnya menjadi milik HRD nanti.
 */
class KaryawanController extends Controller
{
    public function index(): View
    {
        return view('karyawan.index', [
            'rows' => Karyawan::with('bagian')->orderBy('nama')->get(),
        ]);
    }

    public function create(): View
    {
        return view('karyawan.form', ['row' => new Karyawan(['status' => 'aktif']), 'baru' => true] + $this->opsi());
    }

    public function store(Request $request): RedirectResponse
    {
        Karyawan::create($this->validasi($request, true));

        return redirect()->route('karyawan.index')->with('status', 'Karyawan ditambahkan.');
    }

    public function edit(string $kode): View
    {
        return view('karyawan.form', ['row' => Karyawan::findOrFail($kode), 'baru' => false] + $this->opsi());
    }

    public function update(Request $request, string $kode): RedirectResponse
    {
        Karyawan::findOrFail($kode)->update($this->validasi($request, false));

        return redirect()->route('karyawan.index')->with('status', 'Karyawan diperbarui.');
    }

    public function destroy(string $kode): RedirectResponse
    {
        $row = Karyawan::withCount('pinjaman')->findOrFail($kode);

        // Menghapus karyawan yang masih punya pinjaman akan memutus riwayat
        // hutangnya. Nonaktifkan saja — datanya tetap terbaca.
        if ($row->pinjaman_count > 0) {
            return back()->with('error', "Karyawan \"{$row->nama}\" masih punya {$row->pinjaman_count} pinjaman. Nonaktifkan saja, jangan dihapus.");
        }

        $row->delete();

        return redirect()->route('karyawan.index')->with('status', 'Karyawan dihapus.');
    }

    /** @return array<string,mixed> */
    private function opsi(): array
    {
        return [
            'bagianOptions' => ['' => '— tanpa bagian —'] + Bagian::orderBy('kode_bagian')
                ->get()->mapWithKeys(fn ($b) => [$b->kode_bagian => "{$b->kode_bagian} — {$b->nama_bagian}"])->all(),
            'penggunaOptions' => ['' => '— tanpa akun login —'] + User::where('status', 'aktif')
                ->orderBy('nama')->get()->mapWithKeys(fn ($u) => [$u->id_pengguna => "{$u->nama} ({$u->username})"])->all(),
        ];
    }

    private function validasi(Request $request, bool $baru): array
    {
        return $request->validate([
            'kode' => $baru
                ? ['required', 'string', 'max:50', Rule::unique('karyawan', 'kode')]
                : ['prohibited'],
            'nama' => ['required', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'kode_bagian' => ['nullable', 'string', Rule::exists('bagian', 'kode_bagian')],
            'id_pengguna' => ['nullable', 'integer', Rule::exists('users', 'id_pengguna')],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'keterangan' => ['nullable', 'string'],
        ]);
    }
}
