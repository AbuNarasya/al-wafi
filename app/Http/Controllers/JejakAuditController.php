<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ModulRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * JEJAK AUDIT (read-only).
 *
 * Tak ada rute simpan, ubah, atau hapus — dan itu disengaja. Jejak yang bisa
 * dihapus dari dalam aplikasi berhenti menjadi jejak: yang paling ingin
 * menghapusnya justru orang yang paling perlu terekam.
 *
 * Khusus admin. Isinya memperlihatkan siapa mengubah apa di modul keuangan,
 * jadi ia sendiri termasuk informasi yang perlu dijaga.
 */
class JejakAuditController extends Controller
{
    public function index(Request $request): View
    {
        $f = [
            'pengguna' => trim((string) $request->query('pengguna', '')),
            'modul' => trim((string) $request->query('modul', '')),
            'aksi' => trim((string) $request->query('aksi', '')),
            'ref' => trim((string) $request->query('ref', '')),
            'from' => trim((string) $request->query('from', '')),
            'to' => trim((string) $request->query('to', '')),
        ];

        $rows = ActivityLog::query()
            ->with('user:id_pengguna,nama')
            ->when($f['pengguna'] !== '', fn ($q) => $q->where('id_pengguna', $f['pengguna']))
            ->when($f['modul'] !== '', fn ($q) => $q->where('modul', $f['modul']))
            ->when($f['aksi'] !== '', fn ($q) => $q->where('aksi', 'ilike', "%{$f['aksi']}%"))
            ->when($f['ref'] !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('ref_id', 'ilike', "%{$f['ref']}%")->orWhere('ref_jenis', 'ilike', "%{$f['ref']}%"),
            ))
            ->when($f['from'] !== '', fn ($q) => $q->whereDate('created_at', '>=', $f['from']))
            ->when($f['to'] !== '', fn ($q) => $q->whereDate('created_at', '<=', $f['to']))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('jejak-audit.index', [
            'rows' => $rows,
            'f' => $f,
            'penggunaOptions' => ['' => 'Semua pengguna'] + User::orderBy('nama')->pluck('nama', 'id_pengguna')->all(),
            'modulOptions' => ['' => 'Semua modul'] + collect(ModulRegistry::MODUL)
                ->pluck('nama', 'kode')->sort()->all(),
        ]);
    }
}
