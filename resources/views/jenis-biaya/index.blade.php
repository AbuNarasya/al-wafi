@extends('layouts.app')

@section('title', 'Jenis Biaya')

@php $labelTipe = ['registrasi' => 'bg-blue-100 text-blue-700', 'uang_pangkal' => 'bg-purple-100 text-purple-700', 'spp' => 'bg-emerald-100 text-emerald-700', 'lain' => 'bg-gray-100 text-gray-600']; @endphp

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Jenis biaya kesantrian: registrasi, uang pangkal, SPP (berulang), dan lain-lain.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari kode / nama…" />
        @if (\App\Support\Akses::boleh('jenis-biaya', 'buat'))
            <div class="flex items-center gap-2">
                <a href="{{ route('jenis_biaya.duplikat_form') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium hover:bg-gray-50">⧉ Duplikat ke T.A Baru</a>
                <a href="{{ route('jenis_biaya.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Jenis Biaya</a>
            </div>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">T.A</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Berlaku Untuk</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" type="select" /><x-fcol :col="3" type="select" /><x-fcol :col="4" type="select" /><x-fcol :col="5" /><x-fcol :col="6" type="select" /><x-fcol :col="7" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->kode }}</td>
                        <td class="px-4 py-3">{{ $r->nama }}@if ($r->berulang)<span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-brand">berulang</span>@endif</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->tahun_ajaran }}</td>
                        <td class="px-4 py-3"><span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $labelTipe[$r->tipe] ?? '' }}">{{ str_replace('_', ' ', ucfirst($r->tipe)) }}</span></td>
                        {{-- Cakupan baris = kunci tarif mana yang dipakai program; ditampilkan
                             agar dua baris berjenjang sama tak lagi membingungkan. --}}
                        <td class="px-4 py-3 text-gray-600">{{ ($r->kode_jenjang ?: 'Semua jenjang').' · '.($r->kode_jalur ?: 'Semua jalur') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@if (is_null($r->nominal))—@else @rp($r->nominal)@endif</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->kode_unit }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('jenis-biaya', 'ubah'))<a href="{{ route('jenis_biaya.edit', $r->kode) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('jenis-biaya', 'hapus'))
                                    <form method="POST" action="{{ route('jenis_biaya.destroy', $r->kode) }}" onsubmit="return confirm('Hapus {{ $r->kode }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Belum ada jenis biaya.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="9" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
