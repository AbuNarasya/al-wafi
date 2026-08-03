@extends('layouts.app')

@section('title', 'Perintah Pembayaran')

@php
    $warna = [
        'draf' => 'bg-gray-100 text-gray-600', 'menunggu' => 'bg-amber-100 text-amber-800',
        'diotorisasi' => 'bg-indigo-100 text-indigo-700', 'sebagian' => 'bg-blue-100 text-blue-700',
        'terbayar' => 'bg-emerald-100 text-emerald-700', 'selesai' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-red-100 text-red-700',
    ];
@endphp

@section('content')
    {{-- Dana bebas ditaruh di kepala daftar: angka inilah yang membatasi seluruh
         perintah, jadi ia perlu terlihat sebelum orang mulai menyusun. --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm" x-data="{ rinci: false }">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-400">Dana yang bisa dipakai</div>
                <div class="text-2xl font-bold tabular-nums text-emerald-700">@rp($dana['dana_bebas'])</div>
            </div>
            <button type="button" @click="rinci = !rinci" class="text-xs text-brand hover:underline"
                    x-text="rinci ? '▾ Sembunyikan rincian' : '▸ Lihat rincian perhitungan'"></button>
        </div>
        <div x-show="rinci" x-cloak class="mt-3 border-t border-gray-100 pt-3">
            <table class="w-full max-w-md text-sm">
                <tr><td class="py-0.5 text-gray-600">Saldo seluruh kas &amp; bank</td><td class="py-0.5 text-right tabular-nums">@rp($dana['saldo_kas'])</td></tr>
                <tr class="text-red-700"><td class="py-0.5">&minus; Titipan ({{ count($dana['rincian_pengurang']) }} akun)</td><td class="py-0.5 text-right tabular-nums">@rp($dana['pengurang'])</td></tr>
                <tr class="text-red-700"><td class="py-0.5">&minus; Perintah diotorisasi belum dibayar ({{ count($dana['rincian_komitmen']) }})</td><td class="py-0.5 text-right tabular-nums">@rp($dana['komitmen'])</td></tr>
                <tr class="border-t border-gray-300 font-semibold"><td class="py-1">Dana bebas dipakai</td><td class="py-1 text-right tabular-nums">@rp($dana['dana_bebas'])</td></tr>
            </table>
            @if (\App\Support\Akses::boleh('pengaturan-dana-bebas', 'lihat'))
                <a href="{{ route('pengaturan_dana_bebas.index') }}" class="mt-2 inline-block text-xs text-brand hover:underline">Atur akun pengurang &rarr;</a>
            @endif
        </div>
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $q }}" placeholder="Cari nomor / keterangan…"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
            <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">Semua status</option>
                @foreach ($opsiStatus as $k => $v)
                    <option value="{{ $k }}" @selected($filter['status'] === $k)>{{ $v }}</option>
                @endforeach
            </select>
            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Cari</button>
            @if ($q !== '' || $filter['status'] !== '')
                <a href="{{ route('perintah_pembayaran.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            @endif
            <span class="text-xs text-gray-500">{{ $rows->total() }} perintah</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('perintah_pembayaran.kepatuhan') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Kepatuhan Realisasi</a>
            @if (\App\Support\Akses::boleh('perintah-pembayaran', 'buat'))
                <a href="{{ route('perintah_pembayaran.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Perintah Baru</a>
            @endif
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Nomor</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">Kewajiban</th>
                    <th class="px-4 py-3 text-right">Diajukan</th>
                    <th class="px-4 py-3 text-right">Diotorisasi</th>
                    <th class="px-4 py-3">Bayar</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <a href="{{ route('perintah_pembayaran.show', $r->kode_transaksi) }}" class="font-mono font-medium text-brand hover:underline">{{ $r->nomor }}</a>
                            <div class="text-xs text-gray-400">{{ $r->penyusun?->nama }}</div>
                        </td>
                        <td class="px-4 py-2">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-gray-700">{{ $r->keterangan }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r->detail_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">@rp($r->total_diajukan)</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">@rp($r->total_diotorisasi)</td>
                        <td class="px-4 py-2 text-gray-600">{{ $r->tanggal_bayar?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$r->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ \App\Models\PerintahPembayaran::STATUS[$r->status] ?? $r->status }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">Belum ada perintah pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
