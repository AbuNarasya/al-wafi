@extends('layouts.app')

@section('title', 'Vendor')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari kode / nama…" />
        @if (\App\Support\Akses::boleh('vendors', 'buat'))
            <a href="{{ route('vendors.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Vendor</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Pembayaran</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" type="select" /><x-fcol :col="3" /><x-fcol :col="4" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($vendors as $v)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $v->kode_vendor }}</td>
                        <td class="px-4 py-3">{{ $v->nama_vendor }}<div class="text-xs text-gray-400">{{ $v->telepon }}</div></td>
                        <td class="px-4 py-3 text-gray-500">{{ $v->jenis?->nama ?? $v->kode_jenis_vendor }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ ucfirst($v->metode_pembayaran) }}{{ $v->metode_pembayaran === 'termin' ? ' ' . $v->termin_hari . ' hari' : '' }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $v->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($v->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('vendors', 'ubah'))<a href="{{ route('vendors.edit', $v) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('vendors', 'hapus'))
                                    <form method="POST" action="{{ route('vendors.destroy', $v) }}" onsubmit="return confirm('Hapus vendor {{ $v->kode_vendor }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada data.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
