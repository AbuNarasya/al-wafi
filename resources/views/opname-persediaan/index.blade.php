@extends('layouts.app')

@section('title', 'Stok Opname')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET">
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua status</option>
                @foreach (\App\Models\OpnamePersediaan::STATUS as $k => $l)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </form>
        @if (\App\Support\Akses::boleh('opname-persediaan', 'buat'))
            <a href="{{ route('opname_persediaan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Lembar Opname Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Lokasi</th><th class="px-4 py-3 text-right">Dihitung</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><a href="{{ route('opname_persediaan.show', $r->id_opname) }}" class="font-mono font-medium text-brand hover:underline">{{ $r->nomor }}</a></td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">{{ $r->lokasi?->nama }}@if ($r->kategori) <span class="text-gray-500">· {{ $r->kategori->nama }}</span>@endif</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $r->dihitung_count }} / {{ $r->details_count }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ match ($r->status) { 'draft' => 'bg-gray-100 text-gray-700', 'diajukan' => 'bg-amber-100 text-amber-800', 'disetujui' => 'bg-emerald-100 text-emerald-700', default => 'bg-gray-100 text-gray-400' } }}">{{ \App\Models\OpnamePersediaan::STATUS[$r->status] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada lembar opname.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
