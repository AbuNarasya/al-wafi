@extends('layouts.app')

@section('title', 'Jurnal Umum')

@section('content')
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">Jurnal Umum</h2>
            <p class="mt-1 text-sm text-gray-500">Seluruh entri jurnal — otomatis dari modul transaksi maupun input manual.</p>
        </div>
        @if (\App\Support\Akses::boleh('journal', 'buat'))
            <a href="{{ route('journal.create') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">+ Jurnal Manual</a>
        @endif
    </div>

    {{-- Filter: rentang tanggal + cari (referensi/akun/keterangan) --}}
    <form method="GET" id="filterJurnal" class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-3">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Dari Tanggal</label>
            <input type="date" name="from" value="{{ $from }}" onchange="this.form.submit()"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Sampai Tanggal</label>
            <input type="date" name="to" value="{{ $to }}" onchange="this.form.submit()"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Cari Referensi / Akun / Keterangan</label>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" name="q" value="{{ $q }}" placeholder="Cari…" data-filter-auto autocomplete="off"
                       class="w-full min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                @if ($q !== '' || $from || $to || array_filter($filter))
                    <a href="{{ route('journal.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                @endif
                <span class="text-xs text-gray-400">{{ $entries->total() }} data</span>
            </div>
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Akun</th>
                    <th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debet</th><th class="px-4 py-3 text-right">Kredit</th>
                    <th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr>
                {{-- Filter kolom ditautkan ke form filter di atas lewat atribut form. --}}
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="sumber" :options="$opsiSumber" :value="$filter['sumber']" form="filterJurnal" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterJurnal" />
                    <x-scol type="blank" />
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $e)
                    @php
                        // Baris debet dulu, lalu kredit (urutan asli sebagai tie-break).
                        $lines = $e->lines->sortByDesc(fn ($l) => (float) $l->debet > 0 ? 1 : 0)->values();
                        $span = max($lines->count(), 1);
                        $voidable = $e->status === 'aktif' && $e->sumber_modul === 'JurnalUmum' && ! $e->reversal_of;
                    @endphp
                    @foreach ($lines as $i => $l)
                        <tr class="{{ $i === 0 ? 'border-t border-gray-200' : '' }} {{ $e->status !== 'aktif' ? 'text-gray-400' : '' }}">
                            @if ($i === 0)
                                <td rowspan="{{ $span }}" class="whitespace-nowrap px-4 py-2 align-top">{{ $e->tanggal->format('d M Y') }}</td>
                                <td rowspan="{{ $span }}" class="px-4 py-2 align-top font-mono text-xs">{{ $e->referensi }}</td>
                            @endif
                            <td class="px-4 py-2">{{ $l->kode_coa }}{{ $l->nama_coa ? ' — '.$l->nama_coa : '' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $l->keterangan ?? $e->keterangan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@if ((float) $l->debet != 0)@rp($l->debet)@endif</td>
                            <td class="px-4 py-2 text-right tabular-nums">@if ((float) $l->kredit != 0)@rp($l->kredit)@endif</td>
                            @if ($i === 0)
                                <td rowspan="{{ $span }}" class="px-4 py-2 align-top"><span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $e->sumber_modul }}</span></td>
                                <td rowspan="{{ $span }}" class="px-4 py-2 align-top"><span class="rounded px-2 py-0.5 text-xs font-medium {{ $e->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-500' }}">{{ $e->status }}</span></td>
                                <td rowspan="{{ $span }}" class="px-4 py-2 text-right align-top">
                                    @if ($voidable && \App\Support\Akses::boleh('journal', 'hapus'))
                                        <form method="POST" action="{{ route('journal.void', $e->id) }}" onsubmit="return confirm('Void jurnal {{ $e->referensi }}? Akan dibuat jurnal pembalik.')">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-red-600 hover:underline">Void</button>
                                        </form>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Tidak ada entri jurnal untuk filter ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>
@endsection
