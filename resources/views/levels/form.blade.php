@extends('layouts.app')

@php $baru = ! $level->exists; @endphp

@section('title', $baru ? 'Tambah Level' : 'Ubah Level ' . $level->kode_level)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('levels.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST"
              action="{{ $baru ? route('levels.store') : route('levels.update', $level) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode_level" label="Kode Level" :value="$level->kode_level" required
                         placeholder="mis. L1" />
            @else
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kode Level</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $level->kode_level }}</div>
                </div>
            @endif

            <x-field name="nama_level" label="Nama Level" :value="$level->nama_level" required
                     placeholder="mis. Direktur" />

            <x-field name="max_transaksi" label="Maksimal Transaksi" type="number" :value="$level->max_transaksi"
                     hint="Kosongkan untuk tidak terbatas (level tertinggi)." />

            <x-field name="status" label="Status" :value="$level->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <x-field name="keterangan" label="Keterangan" :value="$level->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('levels.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    {{ $baru ? 'Simpan' : 'Perbarui' }}
                </button>
            </div>
        </form>
    </div>
@endsection
