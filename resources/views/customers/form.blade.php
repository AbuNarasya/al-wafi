@extends('layouts.app')

@php $baru = ! $customer->exists; @endphp

@section('title', $baru ? 'Tambah Customer' : 'Ubah Customer ' . $customer->kode_customer)

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('customers.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route('customers.store') : route('customers.update', $customer) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($baru)
                    <x-field name="kode_customer" label="Kode Customer" :value="$customer->kode_customer" required />
                @else
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode Customer</label>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $customer->kode_customer }}</div></div>
                @endif
                <x-field name="nama_customer" label="Nama Customer" :value="$customer->nama_customer" required />
            </div>

            <x-field name="kode_jenis_customer" label="Jenis Customer" :value="$customer->kode_jenis_customer" :options="$jenisOptions" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_coa_pendapatan" label="Akun Pendapatan" :value="$customer->kode_coa_pendapatan" :options="$coaOptions" />
                <x-field name="kode_coa_piutang" label="Akun Piutang" :value="$customer->kode_coa_piutang" :options="$coaOptions" />
            </div>

            <x-field name="alamat" label="Alamat" :value="$customer->alamat" textarea />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="telepon" label="Telepon" :value="$customer->telepon" />
                <x-field name="email" label="Email" type="email" :value="$customer->email" />
            </div>

            <x-field name="status" label="Status" :value="$customer->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('customers.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
