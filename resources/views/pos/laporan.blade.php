@extends('layouts.pos')

@section('title', 'Laporan Shift '.$shift->nomor)
@section('kepala', 'Laporan Shift — '.$shift->lokasi->nama)
@section('subkepala', 'Shift '.$shift->nomor.' · '.\App\Models\ShiftKasir::STATUS[$shift->status])

@section('aksi')
    <button type="button" onclick="window.print()" class="rounded border border-white/30 px-2 py-1 text-xs hover:bg-white/10">Cetak</button>
    <a href="{{ route('pos.index') }}" class="rounded bg-white px-2 py-1 text-xs font-semibold text-brand-dark hover:bg-white/90">Ke Kasir</a>
@endsection

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $seharusnya = (float) $shift->modal_awal + (float) $shift->total_tunai;
    @endphp
    <div class="mx-auto max-w-md space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 text-sm shadow-sm">
            <dl class="grid grid-cols-2 gap-y-2">
                <dt class="text-gray-500">Kasir</dt><dd class="text-right">{{ $shift->kasir?->nama }}</dd>
                <dt class="text-gray-500">Dibuka</dt><dd class="text-right">{{ $shift->dibuka_pada->format('d/m/Y H:i') }}</dd>
                <dt class="text-gray-500">Ditutup</dt><dd class="text-right">{{ $shift->ditutup_pada?->format('d/m/Y H:i') ?? '—' }}</dd>
                <dt class="text-gray-500">Jumlah struk</dt><dd class="text-right tabular-nums">{{ $shift->jumlah_transaksi }}</dd>
            </dl>
            <div class="my-3 border-t border-dashed border-gray-300"></div>
            <dl class="grid grid-cols-2 gap-y-2 tabular-nums">
                <dt class="text-gray-500">Modal awal</dt><dd class="text-right">{{ $rp($shift->modal_awal) }}</dd>
                <dt class="text-gray-500">Penjualan tunai</dt><dd class="text-right">{{ $rp($shift->total_tunai) }}</dd>
                <dt class="font-medium">Seharusnya di laci</dt><dd class="text-right font-medium">{{ $rp($seharusnya) }}</dd>
                <dt class="font-medium">Uang dihitung</dt><dd class="text-right font-medium">{{ $rp($shift->uang_dihitung) }}</dd>
                <dt class="font-semibold">Selisih</dt>
                <dd class="text-right text-lg font-bold {{ (float) $shift->selisih < 0 ? 'text-red-600' : ((float) $shift->selisih > 0 ? 'text-amber-600' : 'text-emerald-700') }}">
                    {{ (float) $shift->selisih == 0 ? 'Pas' : ((float) $shift->selisih < 0 ? 'Kurang ' : 'Lebih ').$rp(abs((float) $shift->selisih)) }}
                </dd>
            </dl>
            <div class="my-3 border-t border-dashed border-gray-300"></div>
            <dl class="grid grid-cols-2 gap-y-2 tabular-nums">
                <dt class="font-semibold">Disetor ke keuangan</dt><dd class="text-right font-semibold">{{ $rp(max(0, (float) $shift->uang_dihitung - (float) $shift->modal_awal)) }}</dd>
                <dt class="text-gray-500">Tetap di laci (modal)</dt><dd class="text-right">{{ $rp($shift->modal_awal) }}</dd>
            </dl>
            @if ($shift->catatan_tutup)<p class="mt-3 text-xs text-gray-500">Catatan: {{ $shift->catatan_tutup }}</p>@endif
        </div>

        @if ($shift->status === 'diserahkan')
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                Setoran diterima {{ $shift->penerima?->nama }} pada {{ $shift->diterima_pada?->format('d/m/Y H:i') }}.
            </div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                Serahkan uang setoran beserta laporan ini ke bagian keuangan. Setoran baru tercatat setelah <b>penerima mengonfirmasinya</b>.
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 pt-6 text-center text-xs text-gray-500 print:pt-10">
            <div>Kasir<div class="mt-12 border-t border-gray-400 pt-1">{{ $shift->kasir?->nama }}</div></div>
            <div>Penerima<div class="mt-12 border-t border-gray-400 pt-1">{{ $shift->penerima?->nama ?? '—' }}</div></div>
        </div>
    </div>
@endsection
