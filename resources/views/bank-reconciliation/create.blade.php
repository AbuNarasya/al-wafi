@extends('layouts.app')

@section('title', 'Rekonsiliasi Bank Baru')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('bank_reconciliation.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('bank_reconciliation.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <x-field name="kode_coa" label="Rekening Bank" :value="old('kode_coa')" :options="$rekeningOptions" required />
            <x-field name="tanggal" label="Tanggal (cut-off)" type="date" :value="old('tanggal', now()->toDateString())" required
                     hint="Transaksi buku besar s/d tanggal ini dimuat sebagai item." />
            <x-field name="saldo_bank" label="Saldo Rekening Koran" type="number" :value="old('saldo_bank')" required />
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('bank_reconciliation.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Buat Draft</button>
            </div>
        </form>
    </div>
@endsection
