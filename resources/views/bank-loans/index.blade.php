@extends('layouts.app')

@section('title', 'Pembiayaan')

@php $labelStatus = ['aktif' => 'bg-emerald-100 text-emerald-700', 'lunas' => 'bg-blue-100 text-blue-700', 'void' => 'bg-gray-100 text-gray-500']; @endphp

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Pembiayaan/pinjaman bank (syariah). Angsuran dibayar lewat Kas Keluar.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari bank / kontrak…" />
        @if (\App\Support\Akses::boleh('bank-loans', 'buat'))
            <a href="{{ route('bank_loans.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Pembiayaan Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Bank / Kontrak</th><th class="px-4 py-3">Akad</th><th class="px-4 py-3 text-right">Pokok</th><th class="px-4 py-3 text-right">Sisa Pokok</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" type="select" /><x-fcol type="blank" /><x-fcol type="blank" /><x-fcol :col="4" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nama_bank }}<div class="text-xs text-gray-400">{{ $r->nomor_kontrak ?? '—' }}</div></td>
                        <td class="px-4 py-3 text-gray-500">{{ str_replace('_', ' ', ucfirst($r->jenis_akad)) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->pokok_awal)</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r->sisa_pokok)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('bank_loans.show', $r->id) }}" class="text-brand hover:underline">Lihat</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada pembiayaan.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
