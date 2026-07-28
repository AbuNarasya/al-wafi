@extends('layouts.app')

@php $baru = ! $unit->exists; @endphp

@section('title', $baru ? 'Tambah Unit Bisnis' : 'Ubah Unit ' . $unit->kode_unit)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('business_units.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST"
              action="{{ $baru ? route('business_units.store') : route('business_units.update', $unit) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode_unit" label="Kode Unit" :value="$unit->kode_unit" required placeholder="mis. U007" />
            @else
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kode Unit</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $unit->kode_unit }}</div>
                </div>
            @endif

            <x-field name="nama_unit" label="Nama Unit" :value="$unit->nama_unit" required placeholder="mis. Koperasi" />

            <x-field name="status" label="Status" :value="$unit->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('business_units.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    {{ $baru ? 'Simpan' : 'Perbarui' }}
                </button>
            </div>
        </form>
    </div>
@endsection
