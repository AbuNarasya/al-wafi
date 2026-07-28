@extends('layouts.app')

@section('title', 'Default Unit Bisnis')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Unit bisnis default per modul asal jurnal — dipakai bila transaksi tidak menentukan unit sendiri.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar />
        @if (\App\Support\Akses::boleh('unit-default', 'buat'))
            <a href="{{ route('unit_default.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Modul Asal</th><th class="px-4 py-3">Unit Bisnis</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" type="select" /><x-fcol :col="1" /><x-fcol :col="2" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->sumber_modul }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->kode_unit }} — {{ $r->unit?->nama_unit }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->keterangan }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('unit-default', 'ubah'))<a href="{{ route('unit_default.edit', $r) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('unit-default', 'hapus'))
                                    <form method="POST" action="{{ route('unit_default.destroy', $r) }}" onsubmit="return confirm('Hapus default {{ $r->sumber_modul }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">Belum ada data.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="4" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
