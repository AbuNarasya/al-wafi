@extends('layouts.mitra')

@section('title', 'Riwayat')

@section('content')
<form method="GET" class="mb-3 flex items-center gap-2">
    <input type="date" name="tanggal" value="{{ $tanggal }}" max="{{ now()->toDateString() }}" class="flex-1 rounded-lg border-gray-300 text-sm">
    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Lihat</button>
</form>

@php $berhasil = $rows->where('status', 'berhasil'); @endphp
<div class="mb-3 grid grid-cols-2 gap-2 text-center">
    <div class="rounded-xl bg-white p-3 shadow-sm"><div class="text-xs text-gray-500">Transaksi</div><div class="text-lg font-bold">{{ $berhasil->count() }}</div></div>
    <div class="rounded-xl bg-white p-3 shadow-sm"><div class="text-xs text-gray-500">Penjualan kartu</div><div class="text-lg font-bold tabular-nums">@rp($berhasil->sum('total'))</div></div>
</div>

<div class="space-y-2">
    @forelse ($rows as $r)
        <div class="rounded-xl bg-white p-3 text-sm shadow-sm ring-1 ring-gray-200" x-data="{ batal: false }">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <div class="font-semibold">{{ $r->nama_santri ?? '—' }}</div>
                    <div class="text-xs text-gray-500">{{ $r->waktu->format('H:i') }} · <span class="font-mono">{{ $r->nomor }}</span></div>
                </div>
                <div class="text-right">
                    <div class="font-semibold tabular-nums {{ $r->status === 'batal' ? 'text-gray-400 line-through' : '' }}">@rp($r->total)</div>
                    @if ($r->status === 'batal')
                        <span class="text-xs font-semibold text-red-600">Dibatalkan</span>
                    @elseif ($r->status !== 'berhasil')
                        <span class="text-xs font-semibold text-amber-700">Belum pasti</span>
                    @endif
                </div>
            </div>
            <div class="mt-1 text-xs text-gray-600">
                {{ $r->rincian->map(fn ($d) => $d->nama.' ×'.$d->jumlah)->implode(', ') }}
            </div>
            @if ($r->status === 'batal' && $r->alasan_batal)
                <div class="mt-1 text-xs text-red-600">Alasan batal: {{ $r->alasan_batal }}</div>
            @endif
            @if ($hariIni && $r->status === 'berhasil')
                <button type="button" @click="batal = !batal" class="mt-2 text-xs font-semibold text-red-600">Batalkan</button>
                <form x-show="batal" x-cloak method="POST" action="{{ route('mitra.batal', $r->id) }}" class="mt-2 space-y-2"
                      data-confirm="Batalkan pembayaran ini? Uangnya kembali ke dompet santri.">
                    @csrf
                    <input type="text" name="alasan" required maxlength="255" placeholder="Alasan pembatalan" class="w-full rounded-lg border-gray-300 text-sm">
                    <button class="w-full rounded-lg bg-red-600 py-2 text-sm font-semibold text-white">Batalkan &amp; kembalikan ke santri</button>
                </form>
            @endif
        </div>
    @empty
        <div class="rounded-xl bg-white p-6 text-center text-sm text-gray-400 shadow-sm">Tidak ada transaksi pada tanggal ini.</div>
    @endforelse
</div>
@endsection
