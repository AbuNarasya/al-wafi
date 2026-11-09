@extends('layouts.app')

@section('title', 'Laporan Mitra Kantin')

@section('content')
<div class="mx-auto max-w-4xl space-y-3">
    <a href="{{ route('mitra_kantin.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Mitra Kantin</a>
    <h2 class="text-xl font-semibold text-gray-900">Laporan Penjualan Kartu Mitra</h2>
    <form method="GET" class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-500">Mitra
            <select name="mitra" class="mt-1 block rounded-lg border-gray-300 text-sm">
                <option value="">— semua mitra —</option>
                @foreach ($mitraOptions as $id => $nama)
                    <option value="{{ $id }}" @selected($idMitra === $id)>{{ $nama }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-gray-500">Dari<input type="date" name="dari" value="{{ $dari }}" class="mt-1 block rounded-lg border-gray-300 text-sm"></label>
        <label class="text-xs text-gray-500">Sampai<input type="date" name="sampai" value="{{ $sampai }}" class="mt-1 block rounded-lg border-gray-300 text-sm"></label>
        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Tampilkan</button>
    </form>
    @include('mitra._laporan')

    <div class="overflow-x-auto rounded-xl bg-white p-3 shadow-sm">
        <div class="mb-2 text-sm font-semibold">Transaksi</div>
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs text-gray-500">
                <tr><th class="py-1 pr-3">Waktu</th><th class="py-1 pr-3">Mitra</th><th class="py-1 pr-3">Santri</th><th class="py-1 pr-3">Menu</th><th class="py-1 text-right">Nilai</th><th class="py-1 pl-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($lap['transaksi'] as $t)
                    <tr>
                        <td class="whitespace-nowrap py-1 pr-3">{{ $t->waktu->format('d/m/Y H:i') }}<div class="font-mono text-[11px] text-gray-400">{{ $t->nomor }}</div></td>
                        <td class="py-1 pr-3">{{ $t->mitra?->nama }}</td>
                        <td class="py-1 pr-3">{{ $t->nama_santri ?? '—' }}</td>
                        <td class="py-1 pr-3 text-xs">{{ $t->rincian->map(fn ($d) => $d->nama.' ×'.$d->jumlah)->implode(', ') }}</td>
                        <td class="py-1 text-right tabular-nums">@rp($t->total)</td>
                        <td class="py-1 pl-3 text-xs">
                            {{ ['berhasil' => 'Berhasil', 'batal' => 'Dibatalkan', 'belum_pasti' => 'Belum pasti', 'menunggu' => 'Menunggu'][$t->status] ?? $t->status }}
                            @if ($t->alasan_batal)
                                <div class="text-gray-400">{{ $t->alasan_batal }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-center text-gray-400">Tidak ada transaksi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
