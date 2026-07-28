@extends('layouts.app')

@section('title', 'Grup COA')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari kode / nama…" />
        @if (\App\Support\Akses::boleh('coa-groups', 'buat'))
            <a href="{{ route('coa_groups.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Grup</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Grup</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Induk</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" type="select" /><x-fcol :col="3" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($groups as $g)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900"><span style="padding-left: {{ ($g->level - 1) * 16 }}px">{{ $g->kode_grup }}</span></td>
                        <td class="px-4 py-3">{{ $g->nama_grup }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $g->level }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $g->kode_induk ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('coa-groups', 'ubah'))<a href="{{ route('coa_groups.edit', $g) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('coa-groups', 'hapus'))
                                    <form method="POST" action="{{ route('coa_groups.destroy', $g) }}" onsubmit="return confirm('Hapus grup {{ $g->kode_grup }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada data.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
