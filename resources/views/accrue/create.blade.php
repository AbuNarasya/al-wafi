@extends('layouts.app')

@section('title', 'Accrue Baru')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('accrue.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('accrue.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <x-field name="periode" label="Periode (YYYY-MM)" :value="old('periode', now()->format('Y-m'))"
                         hint="Dipakai Reversal Awal Bulan untuk menentukan accrue periode lalu." />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_coa_debet" label="Akun Debet" :value="old('kode_coa_debet')" :options="$coaOptions" required />
                <x-field name="kode_coa_kredit" label="Akun Kredit" :value="old('kode_coa_kredit')" :options="$coaOptions" required />
            </div>

            <x-field name="nominal" label="Nominal" type="number" :value="old('nominal')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="$unitOptions" />
                <x-field name="kode_bagian" label="Bagian" :value="old('kode_bagian')" :options="$bagianOptions"
                         hint="Wajib bila akun debet adalah Beban (kelompok 5)." />
            </div>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('accrue.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Posting Accrue</button>
            </div>
        </form>
    </div>
@endsection
