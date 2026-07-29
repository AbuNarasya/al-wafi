@extends('layouts.app')

@section('title', 'Pinjaman Karyawan')

@php
    $warna = ['aktif' => 'bg-amber-100 text-amber-700', 'lunas' => 'bg-emerald-100 text-emerald-700', 'void' => 'bg-gray-100 text-gray-500'];
    $adaFilter = $q !== '' || array_filter($filter);
@endphp

@section('content')
    <form method="GET" id="filterPinjaman"></form>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari nomor / nama karyawan…" :total="$rows->total()"
                         :reset="route('pinjaman_karyawan.index')" :aktif="(bool) $adaFilter" form="filterPinjaman" />
        @if (\App\Support\Akses::boleh('pinjaman-karyawan', 'buat'))
            <a href="{{ route('pinjaman_karyawan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Pinjaman Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Karyawan</th>
                    <th class="px-4 py-3 text-right">Pokok</th><th class="px-4 py-3 text-right">Terbayar</th>
                    <th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr>
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterPinjaman" />
                    <x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ $r->tanggal->format('d M Y') }}</td>
                        <td class="px-4 py-3">{{ $r->karyawan?->nama ?? $r->kode_karyawan }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->pokok)</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500">@rp($r->terbayar)</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold">@rp($r->sisa)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('pinjaman_karyawan.show', $r->id) }}" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada pinjaman karyawan.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
