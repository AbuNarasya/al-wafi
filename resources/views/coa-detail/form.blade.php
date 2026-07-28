@extends('layouts.app')

@php $baru = ! $akun->exists; @endphp

@section('title', $baru ? 'Tambah Akun' : 'Ubah Akun ' . $akun->kode_coa)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('coa_detail.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route('coa_detail.store') : route('coa_detail.update', $akun) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode_coa" label="Kode Akun" :value="$akun->kode_coa" required placeholder="mis. 5.1.01.001" />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode Akun</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $akun->kode_coa }}</div></div>
            @endif

            <x-field name="nama_coa" label="Nama Akun" :value="$akun->nama_coa" required />
            <x-field name="kode_grup" label="Grup COA" :value="$akun->kode_grup" :options="$grupOptions" required />
            <x-field name="jenis_saldo" label="Saldo Normal" :value="$akun->jenis_saldo"
                     :options="['debet' => 'Debet', 'kredit' => 'Kredit']" required />
            <x-field name="status" label="Status" :value="$akun->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />
            <x-field name="keterangan" label="Keterangan" :value="$akun->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('coa_detail.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
