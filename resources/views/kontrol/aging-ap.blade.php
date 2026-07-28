@extends('layouts.app')

@section('title', 'Aging AP — Hutang Vendor')

@php
    $tone = ['belum_jatuh_tempo' => 'bg-gray-100 text-gray-600', '1-30' => 'bg-amber-100 text-amber-700', '31-60' => 'bg-orange-100 text-orange-700', '61-90' => 'bg-red-100 text-red-700', '>90' => 'bg-red-200 text-red-800'];
    // Total outstanding per vendor + grand total (port AgingApPage dev).
    $perVendor = [];
    $grandTotal = 0.0;
    foreach ($rows as $r) {
        $k = $r['kode_vendor'] ?? '';
        $perVendor[$k] ??= ['nama' => $r['nama_vendor'] ?? $k, 'total' => 0.0];
        $perVendor[$k]['total'] += (float) $r['sisa_hutang'];
        $grandTotal += (float) $r['sisa_hutang'];
    }
@endphp

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">Hutang vendor belum lunas, dikelompokkan menurut umur jatuh tempo.</p>
        <div class="flex items-center gap-3">
            @include('kontrol._download', ['type' => 'aging-ap'])
            <a href="{{ route('kontrol.ringkasan') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Ringkasan</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Vendor</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3 text-right">Hari Lewat</th><th class="px-4 py-3">Aging</th><th class="px-4 py-3 text-right">Sisa Hutang</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono">{{ $r['nomor_invoice'] }}</td>
                        <td class="px-4 py-3">{{ $r['nama_vendor'] ?? $r['kode_vendor'] }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['nama_unit'] ?? $r['kode_unit'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r['tanggal_jatuh_tempo'])->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $r['hari_lewat'] > 0 ? $r['hari_lewat'] : '—' }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tone[$r['aging']] ?? '' }}">{{ str_replace('_', ' ', $r['aging']) }}</span></td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r['sisa_hutang'])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Tidak ada hutang vendor outstanding. 🎉</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50">
                <tr class="border-t-2 border-gray-200 font-semibold">
                    <td class="px-4 py-2.5" colspan="6">Total Outstanding</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">@rp($grandTotal)</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-4 py-2 text-sm font-semibold text-gray-800">Total Outstanding per Vendor</div>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                @forelse ($perVendor as $v)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-gray-700">{{ $v['nama'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">@rp($v['total'])</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-3 text-gray-400" colspan="2">—</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
