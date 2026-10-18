@extends('layouts.app')

@section('title', 'Transfer Antar Lokasi')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex items-center gap-2">
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua status</option>
                @foreach (\App\Models\TransferPersediaan::STATUS as $k => $l)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>
                @endforeach
            </select>
            @if ($menunggu)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ $menunggu }} masih di jalan / menunggu putusan</span>
            @endif
        </form>
        @if (\App\Support\Akses::boleh('transfer-persediaan', 'buat'))
            <a href="{{ route('transfer_persediaan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Kirim Barang</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Kirim</th><th class="px-4 py-3">Dari → Ke</th><th class="px-4 py-3 text-right">Barang</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><a href="{{ route('transfer_persediaan.show', $r->id_transfer) }}" class="font-mono font-medium text-brand hover:underline">{{ $r->nomor }}</a></td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal_kirim->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">{{ $r->asal?->nama }} → {{ $r->tujuan?->nama }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $r->details->count() }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ match ($r->status) { 'dikirim' => 'bg-blue-100 text-blue-700', 'selisih' => 'bg-amber-100 text-amber-800', 'selesai' => 'bg-emerald-100 text-emerald-700', default => 'bg-gray-100 text-gray-500' } }}">{{ \App\Models\TransferPersediaan::STATUS[$r->status] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada transfer.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
