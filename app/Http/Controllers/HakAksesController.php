<?php

namespace App\Http\Controllers;

use App\Models\HakAksesModul;
use App\Models\User;
use App\Support\ModulRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Matriks hak akses modul per pengguna. Khusus admin (route requireAdmin).
 * Set UTUH (bukan tambal sulam): modul tak disebut = tak berhak (deny default).
 * Port aturan dari hak-akses.service.ts.
 */
class HakAksesController extends Controller
{
    public function index(): View
    {
        $users = User::orderBy('id_pengguna')->get();

        return view('hak-akses.index', compact('users'));
    }

    public function edit(User $user): View
    {
        $rows = HakAksesModul::where('id_pengguna', $user->id_pengguna)->get()->keyBy('kode_modul');

        // Seluruh registri terurut menu, tiap modul dengan status hak saat ini.
        $modul = collect($this->modulTerurut())->map(function ($m) use ($rows) {
            $r = $rows->get($m['kode']);

            return [
                'kode' => $m['kode'],
                'nama' => $m['nama'],
                'grup' => $m['grup'],
                'sub' => $m['sub'] ?? null,
                'lihat' => (bool) ($r->lihat ?? false),
                'buat' => (bool) ($r->buat ?? false),
                'ubah' => (bool) ($r->ubah ?? false),
                'hapus' => (bool) ($r->hapus ?? false),
                'menu' => (bool) ($r->menu ?? false),
            ];
        });

        // Nol baris = pengguna yang belum pernah diatur (mis. baru dibuat).
        // Dibedakan dari "sudah diatur tapi semuanya dimatikan" hanya untuk
        // pesan di layar; keduanya sama-sama tak berhak apa pun.
        return view('hak-akses.edit', ['user' => $user, 'modul' => $modul, 'belumPernahDiatur' => $rows->isEmpty()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        /** @var array<string,array<string,mixed>> $input */
        $input = $request->input('hak', []);

        $tulisTanpaLihat = [];
        $menuTanpaLihat = [];
        $simpan = [];

        foreach (ModulRegistry::MODUL as $m) {
            $kode = $m['kode'];
            $row = $input[$kode] ?? [];
            $lihat = ! empty($row['lihat']);
            $buat = ! empty($row['buat']);
            $ubah = ! empty($row['ubah']);
            $hapus = ! empty($row['hapus']);
            $menu = ! empty($row['menu']);

            if (! $lihat && ($buat || $ubah || $hapus)) {
                $tulisTanpaLihat[] = $m['nama'];
            }
            if ($menu && ! $lihat) {
                $menuTanpaLihat[] = $m['nama'];
            }

            // Simpan hanya baris yang benar-benar memberi sesuatu.
            if ($lihat || $buat || $ubah || $hapus) {
                $simpan[] = [
                    'id_pengguna' => $user->id_pengguna,
                    'kode_modul' => $kode,
                    'lihat' => $lihat,
                    'buat' => $buat,
                    'ubah' => $ubah,
                    'hapus' => $hapus,
                    'menu' => $menu,
                ];
            }
        }

        if ($tulisTanpaLihat !== []) {
            return back()->withInput()->with('error',
                'Modul berikut diberi hak tulis tanpa hak lihat: ' . implode(', ', $tulisTanpaLihat) . '. Centang "lihat" juga.');
        }
        if ($menuTanpaLihat !== []) {
            return back()->withInput()->with('error',
                'Modul berikut diminta tampil di menu tanpa hak lihat: ' . implode(', ', $menuTanpaLihat) . '. Centang "lihat" juga, atau matikan menunya.');
        }

        DB::transaction(function () use ($user, $simpan) {
            HakAksesModul::where('id_pengguna', $user->id_pengguna)->delete();
            if ($simpan !== []) {
                HakAksesModul::insert($simpan);
            }
        });

        return redirect()->route('users.index')->with('status', "Hak akses {$user->username} berhasil disimpan.");
    }

    /**
     * Registri modul terurut mengikuti menu (grup lalu sub).
     *
     * @return list<array{kode:string,nama:string,grup:string,sub?:string}>
     */
    private function modulTerurut(): array
    {
        $modul = ModulRegistry::MODUL;
        usort($modul, function ($a, $b) {
            $g = array_search($a['grup'], ModulRegistry::GRUP_ORDER, true) - array_search($b['grup'], ModulRegistry::GRUP_ORDER, true);
            if ($g !== 0) {
                return $g;
            }
            $subs = ModulRegistry::SUB_ORDER[$a['grup']] ?? [];

            return array_search($a['sub'] ?? '', $subs, true) - array_search($b['sub'] ?? '', $subs, true);
        });

        return $modul;
    }
}
