@extends('layouts.app')

@php $baru = ! $vendor->exists; @endphp

@section('title', $baru ? 'Tambah Vendor' : 'Ubah Vendor ' . $vendor->kode_vendor)

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('vendors.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route('vendors.store') : route('vendors.update', $vendor) }}"
              x-data="{ metode: '{{ old('metode_pembayaran', $vendor->metode_pembayaran ?? 'tunai') }}' }"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($baru)
                    <x-field name="kode_vendor" label="Kode Vendor" :value="$vendor->kode_vendor" required />
                @else
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode Vendor</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $vendor->kode_vendor }}</div></div>
                @endif
                <x-field name="nama_vendor" label="Nama Vendor" :value="$vendor->nama_vendor" required />
            </div>

            <x-field name="kode_jenis_vendor" label="Jenis Vendor" :value="$vendor->kode_jenis_vendor" :options="$jenisOptions" required />
            <x-field name="alamat" label="Alamat" :value="$vendor->alamat" textarea />
            <x-field name="telepon" label="Telepon" :value="$vendor->telepon" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="metode_pembayaran" label="Metode Pembayaran" :value="$vendor->metode_pembayaran ?? 'tunai'"
                         :options="['tunai' => 'Tunai', 'termin' => 'Termin']" x-model="metode" />
                <div x-show="metode === 'termin'" x-cloak>
                    <x-field name="termin_hari" label="Termin (hari)" type="number" :value="$vendor->termin_hari" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="bank" label="Bank" :value="$vendor->bank" />
                <x-field name="no_rekening" label="No. Rekening" :value="$vendor->no_rekening" />
                <x-field name="atas_nama" label="Atas Nama" :value="$vendor->atas_nama" />
            </div>

            <x-field name="status" label="Status" :value="$vendor->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('vendors.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
