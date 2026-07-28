@extends('layouts.app')

@section('title', $baru ? 'Tambah Default Unit' : 'Ubah Default ' . $row->sumber_modul)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('unit_default.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route('unit_default.store') : route('unit_default.update', $row) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="sumber_modul" label="Modul Asal" :value="$row->sumber_modul" :options="$sumberOptions" required />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Modul Asal</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->sumber_modul }}</div></div>
            @endif

            <x-field name="kode_unit" label="Unit Bisnis" :value="$row->kode_unit" :options="$unitOptions" required />
            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('unit_default.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
