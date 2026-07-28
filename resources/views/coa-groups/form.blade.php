@extends('layouts.app')

@php $baru = ! $group->exists; $opsi = ['' => '— (Induk teratas 1–5) —'] + $indukOptions; @endphp

@section('title', $baru ? 'Tambah Grup COA' : 'Ubah Grup ' . $group->kode_grup)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('coa_groups.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route('coa_groups.store') : route('coa_groups.update', $group) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode_grup" label="Kode Grup" :value="$group->kode_grup" required placeholder="mis. 1.1.03" />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode Grup</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $group->kode_grup }}</div></div>
            @endif

            <x-field name="nama_grup" label="Nama Grup" :value="$group->nama_grup" required />
            <x-field name="kode_induk" label="Grup Induk" :value="$group->kode_induk" :options="$opsi"
                     hint="Level dihitung otomatis (maks 3 tingkat; akun detail = level 4)." />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('coa_groups.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
