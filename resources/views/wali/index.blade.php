@extends('layouts.app')

@section('title', 'Wali / Keluarga Santri')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari nama / telepon…" />
        @if (\App\Support\Akses::boleh('wali', 'buat'))
            <a href="{{ route('wali.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Wali</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nama (Kontak Utama)</th><th class="px-4 py-3">Telepon</th><th class="px-4 py-3 text-center">Santri</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol type="blank" /><x-fcol :col="3" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nama }}<div class="text-xs text-gray-400">Kontak: {{ ucfirst($r->kontak_utama) }}</div></td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->telepon }}</td>
                        <td class="px-4 py-3 text-center">{{ $r->santri_count }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('wali', 'ubah'))<a href="{{ route('wali.edit', $r->id) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('wali', 'hapus'))
                                    <form method="POST" action="{{ route('wali.destroy', $r->id) }}" onsubmit="return confirm('Hapus wali {{ $r->nama }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada wali.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
