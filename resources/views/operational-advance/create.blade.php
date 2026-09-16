@extends('layouts.app')

@section('title', 'Uang Muka Operasional Baru')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('operational_advance.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('operational_advance.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <x-field name="penerima" label="Penerima" :value="old('penerima')" hint="Nama penerima uang muka." />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_coa_uang_muka" label="Akun Uang Muka" :value="old('kode_coa_uang_muka')" :options="$coaOptions" required />
                <x-field name="kode_rekening" label="Kas/Rekening Sumber" :value="old('kode_rekening')" :options="$rekeningOptions" required />
            </div>

            <x-field name="nominal" label="Nominal" type="number" :value="old('nominal')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="$unitOptions" />
                <x-field name="kode_bagian" label="Bagian" :value="old('kode_bagian')" :options="$bagianOptions" />
            </div>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" textarea required />

            {{-- Idiom yang sama dengan Pembiayaan Bank, Pinjaman Karyawan, dan
                 Accrue: pertanyaannya positif, melepasnya berarti saldo awal. --}}
            <div x-data="{ posting: {{ old('posting_jurnal', true) ? 'true' : 'false' }} }" class="space-y-2">
                <label class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    <input type="hidden" name="posting_jurnal" value="0">
                    <input type="checkbox" name="posting_jurnal" value="1" x-model="posting"
                           class="rounded border-gray-300 text-brand focus:ring-brand">
                    Posting jurnal pengeluaran sekarang (Debit uang muka, Kredit kas/rekening)
                </label>
                <p x-show="! posting" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800">
                    <b>Saldo awal pindahan sistem.</b> Uangnya sudah diserahkan sebelum aplikasi ini dipakai, jadi
                    kasnya <b>tidak</b> berkurang lagi sekarang dan <b>tidak ada jurnal yang terbit</b>. Barisnya
                    tetap masuk pool uang muka sehingga bisa dipertanggungjawabkan lewat Penyelesaian Uang Muka,
                    dan nilainya otomatis ikut sebagai <b>baris turunan</b> di menu <b>Saldo Awal</b>.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('operational_advance.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Posting Uang Muka</button>
            </div>
        </form>
    </div>
@endsection
