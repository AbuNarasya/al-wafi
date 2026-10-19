@extends('layouts.app')

@section('title', 'Laporan Toko')

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $pakaiPeriode = in_array($tab, ['barang', 'kategori'], true);
    @endphp

    <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 text-sm">
        @foreach ($tabs as $k => $label)
            <a href="{{ route('laporan_toko.index', [...$filter, 'tab' => $k]) }}"
               class="-mb-px border-b-2 px-3 py-2 {{ $tab === $k ? 'border-brand font-semibold text-brand' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if ($pakaiPeriode)
            <div><label class="mb-1 block text-xs text-gray-500">Dari</label><input type="date" name="dari" value="{{ $filter['dari'] }}" class="rounded-lg border-gray-300 text-sm"></div>
            <div><label class="mb-1 block text-xs text-gray-500">Sampai</label><input type="date" name="sampai" value="{{ $filter['sampai'] }}" class="rounded-lg border-gray-300 text-sm"></div>
        @else
            <div><label class="mb-1 block text-xs text-gray-500">{{ $tab === 'beli' ? 'Rata-rata laku selama' : 'Tak terjual sejak' }}</label>
                <select name="hari" class="rounded-lg border-gray-300 text-sm">
                    @foreach ([7, 14, 30, 60, 90] as $h)<option value="{{ $h }}" @selected($filter['hari'] === $h)>{{ $h }} hari</option>@endforeach
                </select></div>
        @endif
        <div><label class="mb-1 block text-xs text-gray-500">Toko</label>
            <select name="lokasi" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua toko</option>
                @foreach ($lokasiOptions as $k => $n)<option value="{{ $k }}" @selected($filter['lokasi'] === $k)>{{ $n }}</option>@endforeach
            </select></div>
        <div><label class="mb-1 block text-xs text-gray-500">Kategori</label>
            <select name="kategori" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua kategori</option>
                @foreach ($kategoriOptions as $k => $n)<option value="{{ $k }}" @selected($filter['kategori'] === $k)>{{ $n }}</option>@endforeach
            </select></div>
        <button class="rounded-lg bg-brand px-3 py-2 font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
    </form>

    @if ($tab === 'barang' || $tab === 'kategori')
        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            @foreach (['Omzet bersih' => $rows->sum('omzet'), 'HPP' => $rows->sum('hpp'), 'Laba kotor' => $rows->sum('laba')] as $label => $nilai)
                <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm"><div class="text-xs text-gray-500">{{ $label }}</div><div class="text-lg font-semibold tabular-nums">{{ $rp($nilai) }}</div></div>
            @endforeach
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm"><div class="text-xs text-gray-500">Margin</div>
                <div class="text-lg font-semibold tabular-nums">{{ $rows->sum('omzet') > 0 ? $angka($rows->sum('laba') / $rows->sum('omzet') * 100).'%' : '—' }}</div></div>
        </div>
        <p class="mb-2 text-xs text-gray-500">Bersih retur menurut tanggal returnya — cocok dengan akun Pendapatan & HPP toko pada periode yang sama.</p>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                @switch($tab)
                    @case('barang')
                        <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Barang</th><th class="px-4 py-2 text-right">Terjual</th><th class="px-4 py-2 text-right">Omzet</th><th class="px-4 py-2 text-right">HPP</th><th class="px-4 py-2 text-right">Laba Kotor</th><th class="px-4 py-2 text-right">Margin</th></tr>
                        @break
                    @case('kategori')
                        <tr><th class="px-4 py-2">Kategori</th><th class="px-4 py-2 text-right">Barang</th><th class="px-4 py-2 text-right">Omzet</th><th class="px-4 py-2 text-right">HPP</th><th class="px-4 py-2 text-right">Laba Kotor</th><th class="px-4 py-2 text-right">Margin</th></tr>
                        @break
                    @case('beli')
                        <tr><th class="px-4 py-2">Barang</th><th class="px-4 py-2 text-right">Stok</th><th class="px-4 py-2 text-right">Minimum</th><th class="px-4 py-2 text-right">Laku / hari</th><th class="px-4 py-2 text-right">Habis dalam</th><th class="px-4 py-2 text-right">Saran beli</th></tr>
                        @break
                    @default
                        <tr><th class="px-4 py-2">Barang</th><th class="px-4 py-2 text-right">Stok</th><th class="px-4 py-2 text-right">Nilai</th><th class="px-4 py-2">Terakhir terjual</th><th class="px-4 py-2 text-right">Umur stok tertua</th></tr>
                @endswitch
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $i => $r)
                    <tr class="hover:bg-gray-50">
                        @switch($tab)
                            @case('barang')
                                <td class="px-4 py-2 text-gray-400">{{ $i + 1 }}</td>
                                <td class="px-4 py-2">{{ $r->nama }} @if ($r->kategori)<span class="text-xs text-gray-400">· {{ $r->kategori }}</span>@endif</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $angka($r->qty) }} {{ $r->satuan }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->omzet) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $rp($r->hpp) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $r->laba < 0 ? 'text-red-600' : '' }}">{{ $rp($r->laba) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $r->margin === null ? '—' : $angka($r->margin).'%' }}</td>
                                @break
                            @case('kategori')
                                <td class="px-4 py-2">{{ $r->nama }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $r->barang }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->omzet) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $rp($r->hpp) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->laba) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $r->margin === null ? '—' : $angka($r->margin).'%' }}</td>
                                @break
                            @case('beli')
                                <td class="px-4 py-2">{{ $r->nama }} @if ($r->kategori)<span class="text-xs text-gray-400">· {{ $r->kategori }}</span>@endif</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $r->stok <= 0 ? 'font-semibold text-red-600' : '' }}">{{ $angka($r->stok) }} {{ $r->satuan }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $angka($r->minimum) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $angka($r->per_hari) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $r->hari_habis === null ? '—' : $r->hari_habis.' hari' }}</td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $r->saran }} {{ $r->satuan }}</td>
                                @break
                            @default
                                <td class="px-4 py-2">{{ $r->nama }} @if ($r->kategori)<span class="text-xs text-gray-400">· {{ $r->kategori }}</span>@endif</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $angka($r->stok) }} {{ $r->satuan }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->nilai) }}</td>
                                <td class="px-4 py-2">{{ $r->terakhir?->format('d/m/Y') ?? 'belum pernah' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $r->umur }} hari</td>
                        @endswitch
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">
                        {{ match ($tab) { 'beli' => 'Tidak ada barang di bawah stok minimum.', 'tidak-laku' => 'Semua barang berstok masih laku.', default => 'Belum ada penjualan pada periode ini.' } }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($tab === 'beli')
        <p class="mt-2 text-xs text-gray-500">Saran beli = cukup untuk {{ $filter['hari'] }} hari ke depan menurut rata-rata laku {{ $filter['hari'] }} hari terakhir, minimal kembali ke stok minimum. Hanya barang yang stok minimumnya diisi.</p>
    @endif
@endsection
