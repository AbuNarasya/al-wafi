@extends('layouts.app')

@section('title', 'Invoice Vendor')

@php $labelStatus = ['belum_bayar' => 'bg-amber-100 text-amber-700', 'sebagian' => 'bg-blue-100 text-blue-700', 'lunas' => 'bg-emerald-100 text-emerald-700', 'void' => 'bg-gray-100 text-gray-500']; @endphp

@php $adaFilter = $q !== '' || array_filter($filter); @endphp

@section('content')
    <form method="GET">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari nomor invoice…" :total="$rows->total()"
                         :reset="route('invoices.index')" :aktif="(bool) $adaFilter" />
        @if (\App\Support\Akses::boleh('invoices', 'buat'))
            <a href="{{ route('invoices.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Invoice Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Vendor</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Sisa Hutang</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
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
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor_invoice }}<div class="text-xs text-gray-400">{{ $r->nomor_ref_internal }}</div></td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal_invoice->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->vendor?->nama_vendor ?? $r->kode_vendor }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->total)</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r->sisa_hutang)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$r->status] ?? 'bg-gray-100 text-gray-500' }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('invoices.show', $r->id_invoice) }}" class="text-brand hover:underline">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada invoice.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </form>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
