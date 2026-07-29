@extends('layouts.app')

@section('title', 'Pinjaman Karyawan Baru')

@section('content')
    <div class="mx-auto max-w-2xl" x-data="{ termin: [{ nominal: '', jatuh_tempo: '', keterangan: '' }], cairkan: true }">
        <a href="{{ route('pinjaman_karyawan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

        <form method="POST" action="{{ route('pinjaman_karyawan.store') }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <x-field name="kode_karyawan" label="Karyawan" :value="old('kode_karyawan')" :options="$karyawanOptions" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tanggal" label="Tanggal Akad" type="date" :value="old('tanggal', now()->toDateString())" required />
                <x-field name="pokok" label="Pokok Pinjaman" type="number" :value="old('pokok')" required />
            </div>

            <x-field name="kode_coa_piutang" label="Akun Piutang Karyawan" :value="old('kode_coa_piutang')" :options="$piutangOptions" required
                     hint="Akun aset tempat hutang karyawan dicatat." />

            <label class="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                <input type="hidden" name="posting_pencairan" value="0">
                <input type="checkbox" name="posting_pencairan" value="1" x-model="cairkan" class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                <span>
                    <b>Catat pencairan uangnya sekarang</b> — menerbitkan jurnal Debit Piutang Karyawan / Kredit kas.
                    <span class="block text-xs text-gray-500">Matikan bila pinjamannya sudah cair sebelum aplikasi ini dipakai; saldonya masuk lewat jurnal pembuka.</span>
                </span>
            </label>

            <div x-show="cairkan" x-cloak>
                <x-field name="kode_rekening" label="Kas/Rekening Pencairan" :value="old('kode_rekening')" :options="$rekeningOptions" />
            </div>

            {{-- Jadwal termin: kesepakatan cicilan. Jumlahnya wajib sama dengan pokok. --}}
            <fieldset class="rounded-lg border border-gray-200 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-700">Jadwal Termin (opsional)</legend>
                <p class="mb-2 text-xs text-gray-500">
                    Bila diisi, <b>jumlah seluruh termin harus sama persis dengan pokok</b>. Kosongkan bila jadwalnya disusun belakangan.
                </p>
                <template x-for="(t, i) in termin" :key="i">
                    <div class="mb-2 grid grid-cols-12 gap-2">
                        <input type="number" :name="`termin[${i}][nominal]`" x-model="t.nominal" placeholder="Nominal"
                               class="col-span-4 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                        <input type="date" :name="`termin[${i}][jatuh_tempo]`" x-model="t.jatuh_tempo"
                               class="col-span-4 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                        <input type="text" :name="`termin[${i}][keterangan]`" x-model="t.keterangan" placeholder="Keterangan"
                               class="col-span-3 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                        <button type="button" @click="termin.splice(i, 1)" class="col-span-1 text-red-500 hover:text-red-700">&times;</button>
                    </div>
                </template>
                <button type="button" @click="termin.push({ nominal: '', jatuh_tempo: '', keterangan: '' })"
                        class="rounded-lg border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50">+ Tambah Termin</button>
            </fieldset>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('pinjaman_karyawan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
