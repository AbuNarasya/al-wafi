@extends('layouts.app')

@section('title', 'Rekonsiliasi Bank')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Cocokkan saldo buku besar dengan rekening koran; tandai transaksi cleared, buat penyesuaian, lalu finalkan.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari rekening…" />
        @if (\App\Support\Akses::boleh('bank-reconciliation', 'buat'))
            <a href="{{ route('bank_reconciliation.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Rekonsiliasi Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Rekening</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3 text-right">Saldo Bank</th><th class="px-4 py-3 text-right">Saldo Buku</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" type="select" /><x-fcol :col="1" /><x-fcol type="blank" /><x-fcol type="blank" /><x-fcol :col="4" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $namaRek[$r->kode_coa] ?? $r->kode_coa }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->saldo_bank)</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->saldo_buku)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'selesai' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('bank_reconciliation.show', $r->id) }}" class="text-brand hover:underline">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada rekonsiliasi.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
