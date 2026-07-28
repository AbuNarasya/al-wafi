@extends('layouts.app')

@section('title', 'Penyelesaian Uang Muka')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Menyelesaikan uang muka operasional: Kredit akun uang muka, Debit akun realisasi, selisih via kas.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari referensi / akun…" />
        @if (\App\Support\Akses::boleh('advance-settlement', 'buat'))
            <a href="{{ route('advance_settlement.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Penyelesaian Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Akun UM → Realisasi</th><th class="px-4 py-3 text-right">Uang Muka</th><th class="px-4 py-3 text-right">Realisasi</th><th class="px-4 py-3">Status</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" /><x-fcol type="blank" /><x-fcol type="blank" /><x-fcol :col="5" type="select" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor_referensi }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            <span class="text-gray-500">{{ $r->nama_coa_uang_muka }}</span>
                            <span class="mx-1 text-gray-400">&rarr;</span>
                            <span>{{ $r->nama_coa_realisasi }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal_uang_muka)</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal_realisasi)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada penyelesaian uang muka.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
