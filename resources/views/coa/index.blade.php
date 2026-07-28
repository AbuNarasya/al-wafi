@extends('layouts.app')

@section('title', 'Chart of Account')

@php
    $roots = $groups->where('level', 1)->sortBy('kode_grup');
    $bolehGrupBuat = \App\Support\Akses::boleh('coa-groups', 'buat');
    $bolehGrupUbah = \App\Support\Akses::boleh('coa-groups', 'ubah');
    $bolehDetailBuat = \App\Support\Akses::boleh('coa-detail', 'buat');
    $bolehDetailUbah = \App\Support\Akses::boleh('coa-detail', 'ubah');
@endphp

@section('content')
    <div x-data="{ tab: 'tree' }">
        {{-- Tab header --}}
        <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
            @foreach (['tree' => 'Struktur Pohon', 'groups' => 'Grup COA', 'detail' => 'Detail COA'] as $id => $label)
                <button type="button" @click="tab = '{{ $id }}'"
                        :class="tab === '{{ $id }}' ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="border-b-2 px-4 py-2 text-sm font-medium">{{ $label }}</button>
            @endforeach
        </div>

        {{-- TAB: Struktur Pohon --}}
        <div x-show="tab === 'tree'" x-cloak>
            @if ($groups->isEmpty())
                <div class="rounded-xl border border-gray-200 bg-white p-10 text-center shadow-sm">
                    <div class="text-4xl">🌳</div>
                    <h4 class="mt-2 font-semibold text-gray-700">Struktur COA masih kosong</h4>
                    <p class="mt-1 text-sm text-gray-500">Tambahkan Grup COA (Level 2 &amp; 3) dan Akun Detail untuk melihat pohon di sini.</p>
                </div>
            @else
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    @foreach ($roots as $g)
                        @include('coa._node', ['group' => $g])
                    @endforeach
                </div>
            @endif
        </div>

        {{-- TAB: Grup COA --}}
        <div x-show="tab === 'groups'" x-cloak>
            <div class="mb-3 flex justify-end">
                @if ($bolehGrupBuat)
                    <a href="{{ route('coa_groups.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Grup</a>
                @endif
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Grup</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Induk</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($groups->sortBy('kode_grup') as $g)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-mono font-medium text-gray-900"><span style="padding-left: {{ ($g->level - 1) * 16 }}px">{{ $g->kode_grup }}</span></td>
                                <td class="px-4 py-3">{{ $g->nama_grup }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $g->level }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $g->kode_induk ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($bolehGrupUbah)<a href="{{ route('coa_groups.edit', $g) }}" class="text-brand hover:underline">Ubah</a>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- TAB: Detail COA --}}
        <div x-show="tab === 'detail'" x-cloak>
            <div class="mb-3 flex justify-end">
                @if ($bolehDetailBuat)
                    <a href="{{ route('coa_detail.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Akun</a>
                @endif
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Akun</th><th class="px-4 py-3">Grup</th><th class="px-4 py-3">Saldo Normal</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($details as $a)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ $a->kode_coa }}</td>
                                <td class="px-4 py-3">{{ $a->nama_coa }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $a->kode_grup }}</td>
                                <td class="px-4 py-3"><span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $a->jenis_saldo === 'debet' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($a->jenis_saldo) }}</span></td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $a->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($a->status) }}</span></td>
                                <td class="px-4 py-3 text-right">
                                    @if ($bolehDetailUbah)<a href="{{ route('coa_detail.edit', $a) }}" class="text-brand hover:underline">Ubah</a>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
