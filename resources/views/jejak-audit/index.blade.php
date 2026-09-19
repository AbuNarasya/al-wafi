@extends('layouts.app')

@section('title', 'Jejak Audit')

@section('content')
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-900">Jejak Audit</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Siapa mengubah apa, kapan, dari mana — beserta nilai <b>sebelum</b> dan <b>sesudahnya</b>.
            Baris di sini tak pernah diubah dan tak bisa dihapus dari dalam aplikasi.
        </p>
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Pengguna</label>
            <select name="pengguna" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                @foreach ($penggunaOptions as $id => $nama)
                    <option value="{{ $id }}" @selected((string) $f['pengguna'] === (string) $id)>{{ $nama }}</option>
                @endforeach
            </select></div>
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Modul</label>
            <select name="modul" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                @foreach ($modulOptions as $kode => $nama)
                    <option value="{{ $kode }}" @selected($f['modul'] === (string) $kode)>{{ $nama }}</option>
                @endforeach
            </select></div>
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Aksi</label>
            <input type="text" name="aksi" value="{{ $f['aksi'] }}" placeholder="mis. void, tutup_bulan"
                   class="w-40 rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Dokumen</label>
            <input type="text" name="ref" value="{{ $f['ref'] }}" placeholder="no./jenis dokumen"
                   class="w-40 rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
            <input type="date" name="from" value="{{ $f['from'] }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
        <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
            <input type="date" name="to" value="{{ $f['to'] }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
        <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Saring</button>
        <a href="{{ route('jejak_audit.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Reset</a>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2.5">Waktu</th>
                    <th class="px-3 py-2.5">Pengguna</th>
                    <th class="px-3 py-2.5">Aksi</th>
                    <th class="px-3 py-2.5">Dokumen</th>
                    <th class="px-3 py-2.5">Perubahan / Keterangan</th>
                    <th class="px-3 py-2.5">Asal</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="align-top hover:bg-gray-50">
                        <td class="px-3 py-2 whitespace-nowrap text-gray-600">{{ $r->created_at?->format('d/m/Y H:i:s') }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ $r->user?->nama ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="font-medium text-gray-800">{{ $r->aksi }}</span>
                            @if ($r->modul)<div class="text-[11px] text-gray-400">{{ $r->modul }}</div>@endif
                        </td>
                        <td class="px-3 py-2 text-gray-600">
                            @if ($r->ref_jenis){{ $r->ref_jenis }}@endif
                            @if ($r->ref_id)<div class="font-mono text-[11px] text-gray-500">{{ $r->ref_id }}</div>@endif
                        </td>
                        <td class="px-3 py-2">
                            @if ($r->perubahan)
                                <table class="text-[11px]">
                                    @foreach ($r->perubahan as $kolom => $nilai)
                                        <tr>
                                            <td class="pr-2 text-gray-500">{{ $kolom }}</td>
                                            <td class="pr-1 text-red-700 line-through">{{ \Illuminate\Support\Str::limit((string) ($nilai['lama'] ?? '—'), 40) }}</td>
                                            <td class="px-1 text-gray-400">&rarr;</td>
                                            <td class="text-emerald-700">{{ \Illuminate\Support\Str::limit((string) ($nilai['baru'] ?? '—'), 40) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif
                            @if ($r->detail)
                                <div class="mt-0.5 text-[11px] text-gray-500">{{ \Illuminate\Support\Str::limit($r->detail, 200) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-[11px] text-gray-400">{{ $r->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada jejak yang cocok dengan saringan ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
