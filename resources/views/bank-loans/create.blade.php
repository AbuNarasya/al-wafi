@extends('layouts.app')

@section('title', 'Pembiayaan Baru')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('bank_loans.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('bank_loans.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama_bank" label="Nama Bank / Lembaga" :value="old('nama_bank')" required />
                <x-field name="nomor_kontrak" label="Nomor Kontrak" :value="old('nomor_kontrak')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="jenis_akad" label="Jenis Akad" :value="old('jenis_akad', 'murabahah')" :options="$akadOptions" required />
                <x-field name="pokok_awal" label="Pokok Pembiayaan" type="number" :value="old('pokok_awal')" required />
                <x-field name="margin" label="Margin/Bagi Hasil" type="number" :value="old('margin')" hint="Opsional." />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tenor_bulan" label="Tenor (bulan)" type="number" :value="old('tenor_bulan')" />
                <x-field name="tanggal_mulai" label="Tanggal Mulai" type="date" :value="old('tanggal_mulai', now()->toDateString())" required />
                <x-field name="tanggal_jatuh_tempo" label="Jatuh Tempo" type="date" :value="old('tanggal_jatuh_tempo')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_coa_hutang" label="Akun Hutang Pembiayaan" :value="old('kode_coa_hutang')" :options="['' => '— pilih —'] + $coaOptions" required />
                <x-field name="kode_coa_beban_bunga" label="Akun Beban Margin" :value="old('kode_coa_beban_bunga')" :options="['' => '— (opsional) —'] + $coaOptions" />
            </div>

            <x-field name="kode_rekening" label="Rekening Pencairan" :value="old('kode_rekening')" :options="['' => '— pilih —'] + $rekeningOptions" required />
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" textarea />

            <label class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                <input type="hidden" name="posting_pencairan" value="0">
                <input type="checkbox" name="posting_pencairan" value="1" @checked(old('posting_pencairan', true))
                       class="rounded border-gray-300 text-brand focus:ring-brand">
                Posting jurnal pencairan sekarang (Debit Kas/Bank, Kredit hutang pembiayaan sebesar pokok)
            </label>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('bank_loans.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Pembiayaan</button>
            </div>
        </form>
    </div>
@endsection
