@extends('layouts.app')

@section('title', 'Pengguna')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari username / nama…" />

        @if (\App\Support\Akses::boleh('users', 'buat'))
            <a href="{{ route('users.create') }}"
               class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
                + Tambah Pengguna
            </a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Username</th>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Level</th>
                    <th class="px-4 py-3">Bagian</th>
                    <th class="px-4 py-3">Peringkat</th>
                    <th class="px-4 py-3">Keu.</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" type="select" /><x-fcol :col="3" type="select" /><x-fcol :col="4" type="select" /><x-fcol :col="5" type="select" /><x-fcol :col="6" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($users as $u)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{ $u->username }}
                            @if ($u->is_admin)
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">ADMIN</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $u->nama }}<div class="text-xs text-gray-400">{{ $u->jabatan }}</div></td>
                        <td class="px-4 py-3 text-gray-600">{{ $u->level?->nama_level ?? $u->kode_level }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $u->bagian?->nama_bagian ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $u->levelPengajuan?->nama ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $u->tim_keuangan ? '✓' : '' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $u->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ ucfirst($u->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (auth()->user()->is_admin)
                                    <a href="{{ route('hak_akses.edit', $u) }}" class="text-indigo-600 hover:underline">Hak Akses</a>
                                @endif
                                @if (\App\Support\Akses::boleh('users', 'ubah'))
                                    <a href="{{ route('users.edit', $u) }}" class="text-brand hover:underline">Ubah</a>
                                @endif
                                @if (\App\Support\Akses::boleh('users', 'hapus'))
                                    <form method="POST" action="{{ route('users.destroy', $u) }}"
                                          onsubmit="return confirm('Hapus pengguna {{ $u->username }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:underline">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada data.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="8" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
