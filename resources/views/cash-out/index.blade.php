@extends('layouts.app')

@section('title', 'Kas Keluar')

@php $adaFilter = $q !== '' || array_filter($filter); @endphp

@section('content')
    <form method="GET">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari nomor / keterangan…" :total="$rows->total()"
                         :reset="route('cash_out.index')" :aktif="(bool) $adaFilter" />
        @if (\App\Support\Akses::boleh('cash-out', 'buat'))
            <a href="{{ route('cash_out.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Kas Keluar Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Vendor</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="vendor" :options="$opsiVendor" :value="$filter['vendor']" />
                    <x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" />
                    <x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor_transaksi }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->vendor?->nama_vendor ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->keterangan }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('cash_out.show', $r->kode_transaksi) }}" class="text-brand hover:underline">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada kas keluar.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </form>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
