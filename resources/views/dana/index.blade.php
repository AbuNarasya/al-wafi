@extends('layouts.app')

@section('title', 'Dana Terikat')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Dana Terikat</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-500">
                Wakaf, donasi berperuntukan, beasiswa, dan dana bantuan. Dana yang terikat menolak dibebani
                akun di luar peruntukannya — pembatasannya ditegakkan saat menjurnal, bukan diingat-ingat.
            </p>
        </div>
        <div class="flex items-end gap-2">
            <form method="GET" class="flex items-end gap-2">
                <input type="text" name="q" value="{{ $q }}" placeholder="Cari kode / nama / donatur…"
                       class="w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <select name="jenis" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <option value="">Semua jenis</option>
                    @foreach ($jenisOptions as $k => $label)
                        <option value="{{ $k }}" @selected($jenis === $k)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Cari</button>
            </form>
            @if (\App\Support\Akses::boleh('dana', 'buat'))
                <a href="{{ route('dana.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Dana</a>
            @endif
        </div>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Dana</th>
                    <th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Donatur</th>
                    <th class="px-4 py-3">Peruntukan</th>
                    <th class="px-4 py-3 text-right">Target</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="align-top hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ $r->kode_dana }}</td>
                        <td class="px-4 py-3">{{ $r->nama_dana }}</td>
                        <td class="px-4 py-3">
                            @if ($r->jenis === 'tidak_terikat')
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Tidak Terikat</span>
                            @elseif ($r->jenis === 'terikat_temporer')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Terikat Temporer</span>
                            @else
                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">Terikat Permanen</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->donatur ?: '—' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ \Illuminate\Support\Str::limit($r->peruntukan, 80) ?: '—' }}
                            @if ($r->akun_count > 0)
                                <div class="mt-0.5 text-[11px] text-gray-400">{{ $r->akun_count }} akun beban diizinkan</div>
                            @elseif ($r->jenis !== 'tidak_terikat')
                                {{-- Terikat tapi tanpa daftar akun = pembatasannya
                                     baru tertulis, belum ditegakkan mesin. --}}
                                <div class="mt-0.5 text-[11px] text-amber-700">belum ada daftar akun — belum ditegakkan</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->target_nominal)</td>
                        <td class="px-4 py-3">
                            @if ($r->status === 'aktif')
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Aktif</span>
                            @elseif ($r->status === 'selesai')
                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">Selesai</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">Nonaktif</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('dana', 'ubah'))
                                    <a href="{{ route('dana.edit', $r->kode_dana) }}" class="text-brand hover:underline">Ubah</a>
                                @endif
                                @if (\App\Support\Akses::boleh('dana', 'hapus'))
                                    <form method="POST" action="{{ route('dana.destroy', $r->kode_dana) }}" onsubmit="return confirm('Hapus dana {{ $r->kode_dana }}?')">
                                        @csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada dana terdaftar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
