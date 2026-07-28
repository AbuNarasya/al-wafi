<?php

namespace App\Http\Controllers;

use App\Http\Requests\LevelRequest;
use App\Models\Level;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD Level Otorisasi Keuangan (batas nominal transaksi per pengguna).
 * Master sederhana — pola acuan untuk modul master lain.
 */
class LevelController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $levels = Level::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_level', 'ilike', "%{$q}%")
                    ->orWhere('nama_level', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_level')
            ->get();

        return view('levels.index', compact('levels', 'q'));
    }

    public function create(): View
    {
        return view('levels.form', ['level' => new Level(['status' => 'aktif'])]);
    }

    public function store(LevelRequest $request): RedirectResponse
    {
        Level::create($request->tersimpan());

        return redirect()->route('levels.index')->with('status', 'Level berhasil ditambahkan.');
    }

    public function edit(Level $level): View
    {
        return view('levels.form', compact('level'));
    }

    public function update(LevelRequest $request, Level $level): RedirectResponse
    {
        $level->update($request->tersimpan());

        return redirect()->route('levels.index')->with('status', 'Level berhasil diperbarui.');
    }

    public function destroy(Level $level): RedirectResponse
    {
        try {
            $level->delete();
        } catch (QueryException $e) {
            // FK: level masih dipakai pengguna → tidak boleh dihapus.
            return redirect()->route('levels.index')
                ->with('error', 'Level tidak bisa dihapus karena masih dipakai pengguna.');
        }

        return redirect()->route('levels.index')->with('status', 'Level berhasil dihapus.');
    }
}
