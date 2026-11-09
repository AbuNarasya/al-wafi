@extends('layouts.app')

@section('title', $mitra->exists ? 'Ubah Mitra' : 'Mitra Baru')

@section('content')
<div class="mx-auto max-w-3xl">
    <a href="{{ $mitra->exists ? route('mitra_kantin.show', $mitra) : route('mitra_kantin.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
    <form method="POST" action="{{ $mitra->exists ? route('mitra_kantin.update', $mitra) : route('mitra_kantin.store') }}"
          class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        @csrf
        @if ($mitra->exists)
            @method('PUT')
        @endif
        <h2 class="text-base font-semibold text-gray-900">{{ $mitra->exists ? 'Ubah '.$mitra->nama : 'Mitra Kantin Baru' }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="kode" label="Kode mitra" :value="old('kode', $mitra->kode)" required placeholder="mis. KB01" />
            <x-field name="nama" label="Nama usaha" :value="old('nama', $mitra->nama)" required placeholder="mis. Kantin Basah Bu Ani" />
            <x-field name="pemilik" label="Nama pemilik" :value="old('pemilik', $mitra->pemilik)" />
            <x-field name="telepon" label="Telepon" :value="old('telepon', $mitra->telepon)" />
        </div>
        <div>
            <div class="mb-2 text-sm font-semibold text-gray-800">Rekening tujuan penarikan</div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="bank_nama" label="Bank" :value="old('bank_nama', $mitra->bank_nama)" />
                <x-field name="bank_rekening" label="Nomor rekening" :value="old('bank_rekening', $mitra->bank_rekening)" />
                <x-field name="bank_atas_nama" label="Atas nama" :value="old('bank_atas_nama', $mitra->bank_atas_nama)" />
            </div>
        </div>
        <div>
            <div class="mb-2 text-sm font-semibold text-gray-800">Masa sewa <span class="font-normal text-gray-400">(keterangan; sewa tahunan tidak ditagih sistem)</span></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="sewa_mulai" label="Mulai" type="date" :value="old('sewa_mulai', $mitra->sewa_mulai?->toDateString())" />
                <x-field name="sewa_sampai" label="Sampai" type="date" :value="old('sewa_sampai', $mitra->sewa_sampai?->toDateString())" />
            </div>
            <label class="mt-3 block text-sm font-medium text-gray-700">Keterangan sewa</label>
            <textarea name="keterangan_sewa" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">{{ old('keterangan_sewa', $mitra->keterangan_sewa) }}</textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <select name="status" class="mt-1 rounded-lg border-gray-300 text-sm">
                <option value="aktif" @selected(old('status', $mitra->status) === 'aktif')>Aktif</option>
                <option value="nonaktif" @selected(old('status', $mitra->status) === 'nonaktif')>Nonaktif (kasir &amp; akunnya tertutup)</option>
            </select>
        </div>
        <div class="flex justify-end">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan</button>
        </div>
    </form>
</div>
@endsection
