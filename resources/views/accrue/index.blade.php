@extends('layouts.app')

@section('title', 'Accrue & Prepaid')

@php $adaFilter = $q !== '' || array_filter($filter); @endphp

@section('content')
    {{-- Form filter berdiri sendiri: halaman ini punya tombol POST (Reversal
         Awal Bulan), jadi form GET tak boleh membungkusnya. Kontrol filter
         ditautkan lewat atribut form="filterAccrue". --}}
    <form method="GET" id="filterAccrue"></form>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari referensi / keterangan…" :total="$rows->total()"
                         :reset="route('accrue.index')" :aktif="(bool) $adaFilter" form="filterAccrue" />
        <div class="flex items-center gap-2">
            @if (\App\Support\Akses::boleh('accrue', 'buat'))
                <form method="POST" action="{{ route('accrue.run_reversal') }}" onsubmit="return confirm('Balik semua accrue aktif dari periode sebelum bulan ini?')">
                    @csrf
                    <button class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100">Reversal Awal Bulan</button>
                </form>
                <a href="{{ route('accrue.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Accrue Baru</a>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Periode</th><th class="px-4 py-3">Debit → Kredit</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Status</th></tr>
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterAccrue" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor_referensi }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->periode ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            <span class="text-gray-500">{{ $r->nama_coa_debet }}</span>
                            <span class="mx-1 text-gray-400">&rarr;</span>
                            <span>{{ $r->nama_coa_kredit }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada accrue.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
