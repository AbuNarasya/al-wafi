@extends('layouts.app')

@php
    $baru = ! $bagian->exists;
    $opsiInduk = ['' => '— (Teratas / Yayasan) —'] + $indukOptions;
@endphp

@section('title', $baru ? 'Tambah Bagian' : 'Ubah Bagian ' . $bagian->kode_bagian)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('bagian.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST"
              action="{{ $baru ? route('bagian.store') : route('bagian.update', $bagian) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode_bagian" label="Kode Bagian" :value="$bagian->kode_bagian" required placeholder="mis. KEU" />
            @else
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kode Bagian</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $bagian->kode_bagian }}</div>
                </div>
            @endif

            <x-field name="nama_bagian" label="Nama Bagian" :value="$bagian->nama_bagian" required />

            <x-field name="kode_induk" label="Bagian Induk" :value="$bagian->kode_induk"
                     :options="$opsiInduk"
                     hint="Tingkat dihitung otomatis dari induk (maks. 3: Yayasan → Bidang → Bagian)." />

            <x-field name="status" label="Status" :value="$bagian->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <x-field name="keterangan" label="Keterangan" :value="$bagian->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('bagian.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    {{ $baru ? 'Simpan' : 'Perbarui' }}
                </button>
            </div>
        </form>
    </div>
@endsection
