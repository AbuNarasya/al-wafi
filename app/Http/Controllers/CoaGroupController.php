<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoaGroupRequest;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD Grup COA (hierarki 1–5, maks 3 tingkat; akun detail = level 4 di menu
 * Chart of Account). Level dihitung dari induk. Port dari coa-groups.service.ts.
 */
class CoaGroupController extends Controller
{
    private const MAX_LEVEL = 3;

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $groups = CoaGroup::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('kode_grup', 'ilike', "%{$q}%")->orWhere('nama_grup', 'ilike', "%{$q}%"),
            ))
            ->orderBy('kode_grup')
            ->get();

        return view('coa-groups.index', compact('groups', 'q'));
    }

    public function create(): View
    {
        return view('coa-groups.form', ['group' => new CoaGroup(), 'indukOptions' => $this->indukOptions()]);
    }

    public function store(CoaGroupRequest $request): RedirectResponse
    {
        $data = $request->safe()->only(['kode_grup', 'nama_grup', 'kode_induk']);
        $level = $this->resolveLevel($data['kode_induk'] ?? null);
        if ($level === null) {
            return back()->withInput()->with('error', $this->pesan);
        }

        CoaGroup::create([...$data, 'level' => $level]);

        return redirect()->route('coa_groups.index')->with('status', 'Grup COA berhasil ditambahkan.');
    }

    public function edit(CoaGroup $coa_group): View
    {
        return view('coa-groups.form', ['group' => $coa_group, 'indukOptions' => $this->indukOptions($coa_group->kode_grup)]);
    }

    public function update(CoaGroupRequest $request, CoaGroup $coa_group): RedirectResponse
    {
        $data = $request->safe()->only(['nama_grup', 'kode_induk']);
        $indukBaru = $data['kode_induk'] ?? null;

        if ($indukBaru !== ($coa_group->kode_induk ?? null)) {
            if ($indukBaru === $coa_group->kode_grup) {
                return back()->withInput()->with('error', 'Grup tidak boleh menjadi induk dirinya sendiri.');
            }
            if (CoaGroup::where('kode_induk', $coa_group->kode_grup)->exists() || CoaDetail::where('kode_grup', $coa_group->kode_grup)->exists()) {
                return back()->withInput()->with('error', 'Grup ini sudah memiliki sub-grup / akun detail; induknya tidak dapat dipindah.');
            }
            $level = $this->resolveLevel($indukBaru);
            if ($level === null) {
                return back()->withInput()->with('error', $this->pesan);
            }
            $data['level'] = $level;
        }

        $coa_group->update($data);

        return redirect()->route('coa_groups.index')->with('status', 'Grup COA berhasil diperbarui.');
    }

    public function destroy(CoaGroup $coa_group): RedirectResponse
    {
        $sub = CoaGroup::where('kode_induk', $coa_group->kode_grup)->count();
        $akun = CoaDetail::where('kode_grup', $coa_group->kode_grup)->count();

        if ($sub || $akun) {
            $isi = [];
            if ($sub) $isi[] = "{$sub} sub-grup";
            if ($akun) $isi[] = "{$akun} akun detail";

            return redirect()->route('coa_groups.index')->with('error',
                "Grup \"{$coa_group->nama_grup}\" masih menaungi: " . implode(', ', $isi) . '. Hapus/pindahkan isinya dulu.');
        }

        $coa_group->delete();

        return redirect()->route('coa_groups.index')->with('status', 'Grup COA berhasil dihapus.');
    }

    private string $pesan = '';

    private function resolveLevel(?string $kodeInduk): ?int
    {
        if (! $kodeInduk) {
            return 1;
        }
        $parent = CoaGroup::find($kodeInduk);
        if (! $parent) {
            $this->pesan = 'Grup induk tidak ditemukan.';

            return null;
        }
        if ($parent->level >= self::MAX_LEVEL) {
            $this->pesan = 'Grup induk sudah level ' . self::MAX_LEVEL .
                '. Struktur grup COA maksimal ' . self::MAX_LEVEL . ' tingkat; akun detail (level 4) dibuat di menu Chart of Account.';

            return null;
        }

        return $parent->level + 1;
    }

    /** @return array<string,string> */
    private function indukOptions(?string $kecuali = null): array
    {
        return CoaGroup::query()
            ->where('level', '<', self::MAX_LEVEL)
            ->when($kecuali, fn ($q) => $q->where('kode_grup', '!=', $kecuali))
            ->orderBy('kode_grup')
            ->get()
            ->mapWithKeys(fn ($g) => [$g->kode_grup => "{$g->kode_grup} — {$g->nama_grup}"])
            ->all();
    }
}
