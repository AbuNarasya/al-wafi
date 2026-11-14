<?php

namespace App\Http\Controllers;

use App\Models\Jenjang;
use App\Models\KalenderLibur;
use App\Support\Audit\Jejak;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** KALENDER LIBUR (14 Nov 2026) — diisi admin/pemegang hak `kalender-libur`; dibaca menu Absensi Portal Wali. */
class KalenderLiburController extends Controller
{
    public function index(Request $r): View
    {
        $tahun = (int) ($r->query('tahun') ?: now('Asia/Jakarta')->year);

        return view('kalender-libur.index', [
            'tahun' => $tahun,
            'daftar' => KalenderLibur::with('jenjang:kode,nama')
                ->where('mulai', '<=', "{$tahun}-12-31")->where('selesai', '>=', "{$tahun}-01-01")
                ->orderBy('mulai')->get(),
            'jenjang' => Jenjang::orderBy('urutan')->orderBy('nama')->get(['kode', 'nama']),
            'ubah' => $r->filled('ubah') ? KalenderLibur::find((int) $r->query('ubah')) : null,
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $libur = KalenderLibur::create($this->isian($r) + ['dibuat_oleh' => auth()->id()]);
        Jejak::catat('tambah_kalender_libur', ['modul' => 'kalender-libur', 'ref_jenis' => 'KalenderLibur', 'ref_id' => $libur->id, 'detail' => $libur->only('nama', 'mulai', 'selesai', 'kode_jenjang', 'cakupan')]);

        return redirect()->route('kalender_libur.index', ['tahun' => $libur->mulai->year])->with('status', "Libur \"{$libur->nama}\" ditambahkan.");
    }

    public function update(Request $r, int $id): RedirectResponse
    {
        $libur = KalenderLibur::findOrFail($id);
        $libur->update($this->isian($r));
        Jejak::catat('ubah_kalender_libur', ['modul' => 'kalender-libur', 'ref_jenis' => 'KalenderLibur', 'ref_id' => $libur->id, 'detail' => $libur->only('nama', 'mulai', 'selesai', 'kode_jenjang', 'cakupan')]);

        return redirect()->route('kalender_libur.index', ['tahun' => $libur->mulai->year])->with('status', "Libur \"{$libur->nama}\" diperbarui.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $libur = KalenderLibur::findOrFail($id);
        Jejak::catat('hapus_kalender_libur', ['modul' => 'kalender-libur', 'ref_jenis' => 'KalenderLibur', 'ref_id' => $libur->id, 'detail' => $libur->only('nama', 'mulai', 'selesai', 'kode_jenjang', 'cakupan')]);
        $libur->delete();

        return back()->with('status', "Libur \"{$libur->nama}\" dihapus.");
    }

    private function isian(Request $r): array
    {
        $d = $r->validate([
            'nama' => ['required', 'string', 'max:150'],
            'mulai' => ['required', 'date'],
            'selesai' => ['required', 'date', 'after_or_equal:mulai'],
            'kode_jenjang' => ['nullable', Rule::exists('jenjang', 'kode')],
            'cakupan' => ['required', Rule::in(array_keys(KalenderLibur::CAKUPAN))],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ], [], ['mulai' => 'tanggal mulai', 'selesai' => 'tanggal selesai', 'kode_jenjang' => 'jenjang']);

        return ['nama' => trim($d['nama']), 'mulai' => $d['mulai'], 'selesai' => $d['selesai'], 'kode_jenjang' => $d['kode_jenjang'] ?: null,
            'cakupan' => $d['cakupan'], 'keterangan' => $d['keterangan'] ?? null];
    }
}
