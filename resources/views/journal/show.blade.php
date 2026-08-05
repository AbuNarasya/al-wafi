@extends('layouts.app')

@section('title', 'Jurnal ' . $entry->referensi)

@php
    $totalDebet = $entry->lines->reduce(fn ($s, $l) => $s + (float) $l->debet, 0);
    $totalKredit = $entry->lines->reduce(fn ($s, $l) => $s + (float) $l->kredit, 0);
@endphp

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('journal.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @php $bisaVoid = $entry->status === 'aktif' && $entry->sumber_modul === 'JurnalUmum' && ! $entry->reversal_of; @endphp
            @if ($bisaVoid && \App\Support\Akses::boleh('journal', 'hapus'))
                <form method="POST" action="{{ route('journal.void', $entry->id) }}" onsubmit="return confirm('Void jurnal {{ $entry->referensi }}? Akan dibuat jurnal pembalik.')">
                    @csrf @method('DELETE')
                    <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Void Jurnal</button>
                </form>
            @elseif ($entry->status === 'aktif' && $entry->sumber_modul !== 'JurnalUmum')
                <span class="text-xs text-gray-400">Jurnal dari modul <b>{{ $entry->sumber_modul }}</b> — void lewat modul asalnya.</span>
            @endif
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Referensi</div><div class="font-semibold text-gray-900">{{ $entry->referensi }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $entry->tanggal->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $entry->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($entry->status) }}</span></div>
            <div><div class="text-xs text-gray-400">Sumber</div><div><span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $entry->sumber_modul }}</span></div></div>
            @if ($entry->keterangan)
                <div class="sm:col-span-4"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $entry->keterangan }}</div></div>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Akun</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Bagian</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3 text-right">Debet</th><th class="px-4 py-3 text-right">Kredit</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($entry->lines as $l)
                        <tr>
                            <td class="px-4 py-2">{{ $l->kode_coa }} — {{ $l->nama_coa }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $l->keterangan }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $l->bagian?->nama_bagian ?? $l->kode_bagian ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $l->unit?->nama_unit ?? $l->kode_unit ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($l->debet)</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($l->kredit)</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2.5" colspan="4">Total</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">@rp($totalDebet)</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">@rp($totalKredit)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
