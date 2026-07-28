@extends('layouts.app')

@php $baru = ! $rek->exists; @endphp

@section('title', $baru ? 'Tambah Rekening' : 'Ubah Rekening ' . $rek->kode_coa)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('bank_accounts.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route('bank_accounts.store') : route('bank_accounts.update', $rek) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode_coa" label="Akun COA (Kas/Bank)" :value="$rek->kode_coa" :options="$coaOptions" required />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Akun COA</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $rek->kode_coa }} — {{ $rek->coa?->nama_coa }}</div></div>
            @endif

            <x-field name="nama_rekening" label="Nama Rekening" :value="$rek->nama_rekening" required />
            <x-field name="jenis_rekening" label="Jenis" :value="$rek->jenis_rekening"
                     :options="['tunai' => 'Tunai (Kas)', 'bank' => 'Bank']" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama_bank" label="Nama Bank" :value="$rek->nama_bank" />
                <x-field name="no_rekening" label="No. Rekening" :value="$rek->no_rekening" />
            </div>
            <x-field name="status" label="Status" :value="$rek->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('bank_accounts.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
