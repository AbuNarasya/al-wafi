<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Bagian;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * CRUD Pengguna. Password di-hash (Hash::make). is_admin tidak dapat diubah
 * lewat form ini (keamanan). Pengguna tidak boleh menghapus dirinya sendiri.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with(['level', 'bagian', 'levelPengajuan'])
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('username', 'ilike', "%{$q}%")->orWhere('nama', 'ilike', "%{$q}%"),
            ))
            ->orderBy('id_pengguna')
            ->get();

        return view('users.index', compact('users', 'q'));
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['status' => 'aktif']),
            ...$this->opsi(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('password');
        $data['password_hash'] = Hash::make($request->input('password'));
        $data['tim_keuangan'] = $request->boolean('tim_keuangan');

        User::create($data);

        return redirect()->route('users.index')->with('status', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user, ...$this->opsi()]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->safe()->except('password');
        $data['tim_keuangan'] = $request->boolean('tim_keuangan');
        if ($request->filled('password')) {
            $data['password_hash'] = Hash::make($request->input('password'));
        }

        $user->update($data);

        return redirect()->route('users.index')->with('status', 'Pengguna berhasil diperbarui.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id_pengguna === Auth::id()) {
            return redirect()->route('users.index')->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        try {
            $user->delete();
        } catch (QueryException $e) {
            return redirect()->route('users.index')
                ->with('error', 'Pengguna tidak bisa dihapus karena masih dirujuk data lain (jurnal/approval).');
        }

        return redirect()->route('users.index')->with('status', 'Pengguna berhasil dihapus.');
    }

    /** @return array{levels:mixed,bagianList:mixed,peringkatList:mixed} */
    private function opsi(): array
    {
        return [
            'levels' => Level::where('status', 'aktif')->orderBy('kode_level')
                ->get()->mapWithKeys(fn ($l) => [$l->kode_level => "{$l->kode_level} — {$l->nama_level}"])->all(),
            'bagianList' => ['' => '— Tanpa bagian —'] + Bagian::where('status', 'aktif')->orderBy('kode_bagian')
                ->get()->mapWithKeys(fn ($b) => [$b->kode_bagian => "{$b->kode_bagian} — {$b->nama_bagian}"])->all(),
            'peringkatList' => ['' => '— Tidak ikut rantai pengajuan —'] + LevelPengajuan::orderBy('peringkat')
                ->get()->mapWithKeys(fn ($p) => [$p->peringkat => "{$p->peringkat} — {$p->nama}"])->all(),
        ];
    }
}
