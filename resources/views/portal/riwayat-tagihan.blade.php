@extends('layouts.portal')

@section('title', 'Riwayat tagihan')

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $warna = ['belum' => 'bg-red-50 text-red-700', 'sebagian' => 'bg-blue-50 text-brand', 'lunas' => 'bg-emerald-50 text-emerald-700'];
    $label = ['belum' => 'Belum', 'sebagian' => 'Sebagian', 'lunas' => 'Lunas'];
@endphp

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#b8956a] text-white shadow-sm"><x-portal.ikon nama="riwayat" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">Riwayat tagihan</h1>
            <p class="truncate text-sm text-slate-500">Seluruh anak, termasuk yang sudah lunas</p>
        </div>
    </div>

    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total tagihan keluarga</p>
        <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">{{ $rp($total['nominal']) }}</p>
        <div class="mt-3 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-sm">
            <div><p class="text-xs text-slate-500">Sudah dibayar</p><p class="font-bold tabular-nums text-emerald-700">{{ $rp($total['terbayar']) }}</p></div>
            <div><p class="text-xs text-slate-500">Sisa</p><p class="font-bold tabular-nums text-red-700">{{ $rp($total['sisa']) }}</p></div>
        </div>
        @if ($total['menunggu'] > 0)
            <p class="mt-2 text-xs font-medium text-amber-700">{{ $rp($total['menunggu']) }} sedang menunggu verifikasi.</p>
        @endif
    </div>

    <div class="mt-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
        <a href="{{ route('portal.tagihan.riwayat') }}" @class(['rounded-xl py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === 'tagihan', 'text-slate-600' => $tab !== 'tagihan'])>Tagihan ({{ $tagihan->count() }})</a>
        <a href="{{ route('portal.tagihan.riwayat', ['tab' => 'bayar']) }}" @class(['rounded-xl py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === 'bayar', 'text-slate-600' => $tab !== 'bayar'])>Pembayaran ({{ $bayar->count() }})</a>
    </div>

    <div class="mt-4 divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
        @if ($tab === 'tagihan')
            @forelse ($tagihan as $t)
                <div class="flex items-start justify-between gap-2 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-900">{{ $t['nama'] }}</p>
                        @if ($t['keterangan'])<p class="text-xs text-slate-600">{{ $t['keterangan'] }}</p>@endif
                        <p class="text-xs text-slate-500">{{ $t['anak'] }} &middot; terbit {{ $t['terbit']?->format('d/m/Y') }}</p>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $warna[$t['status']] }}">{{ $label[$t['status']] }}</span>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-bold tabular-nums text-slate-900">{{ $rp($t['nominal']) }}</p>
                        @if ($t['status'] !== 'lunas')
                            <p class="text-xs tabular-nums text-red-700">sisa {{ $rp($t['sisa']) }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="p-6 text-center text-sm text-slate-500">Belum ada tagihan.</p>
            @endforelse
        @else
            @forelse ($bayar as $p)
                <div class="flex items-start justify-between gap-2 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-900">{{ $p['tagihan'] }}</p>
                        @if ($p['keterangan'])<p class="text-xs text-slate-600">{{ $p['keterangan'] }}</p>@endif
                        <p class="text-xs text-slate-500">{{ $p['anak'] }} &middot; {{ $p['tanggal']->format('d/m/Y') }} &middot; {{ $p['sumber'] }}</p>
                        @if ($p['menunggu'])<p class="text-xs font-medium text-amber-700">Menunggu verifikasi</p>
                        @else<a href="{{ route('portal.receipt', $p['id']) }}" class="mt-1 inline-block text-xs font-semibold text-brand">Unduh receipt (PDF)</a>@endif
                    </div>
                    <p class="shrink-0 text-sm font-bold tabular-nums text-slate-900">{{ $rp($p['nominal']) }}</p>
                </div>
            @empty
                <p class="p-6 text-center text-sm text-slate-500">Belum ada pembayaran.</p>
            @endforelse
        @endif
    </div>
@endsection
