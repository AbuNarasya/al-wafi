<?php

namespace App\Http\Controllers;

use App\Models\LevelPengajuan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Level Pengajuan — peringkat 1–4 adalah tulang punggung rantai persetujuan dan
 * dirujuk kode (peringkat-pengajuan). Karena itu HANYA label/status yang boleh
 * diubah; tidak ada create/delete.
 */
class LevelPengajuanController extends Controller
{
    public function index(): View
    {
        $rows = LevelPengajuan::withCount('users')->orderBy('peringkat')->get();

        return view('level-pengajuan.index', compact('rows'));
    }

    public function edit(LevelPengajuan $level_pengajuan): View
    {
        return view('level-pengajuan.form', ['row' => $level_pengajuan]);
    }

    public function update(Request $request, LevelPengajuan $level_pengajuan): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);

        $level_pengajuan->update($data);

        return redirect()->route('level_pengajuan.index')->with('status', 'Level pengajuan berhasil diperbarui.');
    }
}
