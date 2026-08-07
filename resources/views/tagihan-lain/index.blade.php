@extends('layouts.app')

@section('title', 'Tagihan Lain-lain')

@php $adaFilter = $q !== '' || array_filter($filter); @endphp

@section('content')
    <form method="GET" id="filterTagihanLain"></form>

    <p class="mb-3 text-sm text-gray-500">Tagihan lain-lain (seragam, kegiatan, denda) untuk santri aktif.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari santri / keterangan…" :total="$rows->total()"
                         :reset="route('tagihan_lain.index')" :aktif="(bool) $adaFilter" form="filterTagihanLain" />
        @if (\App\Support\Akses::boleh('tagihan-lain', 'buat'))
            <a href="{{ route('tagihan_lain.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Terbitkan Tagihan</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Santri</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Periode</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-scol type="blank" />
                    <x-scol name="jenis" :options="$opsiJenis" :value="$filter['jenis']" form="filterTagihanLain" />
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterTagihanLain" />
                    <x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->santri?->nama }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->jenis?->nama ?? $r->kode_jenis }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->periode ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->sisa)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'lunas' ? 'bg-emerald-100 text-emerald-700' : (! $r->berlaku() ? 'bg-gray-100 text-gray-500' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            @if (in_array($r->status, ['belum_bayar'], true) && \App\Support\Akses::boleh('tagihan-lain', 'hapus'))
                                <form method="POST" action="{{ route('tagihan_lain.batalkan', $r->id) }}" onsubmit="return confirm('Batalkan tagihan ini?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Batalkan</button></form>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada tagihan lain-lain.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
