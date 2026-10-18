@extends('layouts.app')

@section('title', 'Lembar Opname Baru')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('opname_persediaan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ route('opname_persediaan.store') }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <x-field name="tanggal" label="Tanggal Opname" type="date" :value="old('tanggal', now()->toDateString())" required
                     hint="Tanggal jurnal selisihnya kelak." />
            <x-field name="kode_lokasi" label="Lokasi yang Dihitung" :value="old('kode_lokasi', \App\Models\LokasiPersediaan::BAWAAN)" :options="$lokasiOptions" required />
            <x-field name="kode_kategori" label="Kategori" :value="old('kode_kategori')" :options="$kategoriOptions"
                     hint="Opname bergilir: hitung satu kategori per hari tanpa menutup toko." />
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Barang yang dimuat</label>
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="cakupan" value="pernah" @checked(old('cakupan', 'pernah') === 'pernah')> Yang pernah ada di lokasi ini</label>
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="cakupan" value="semua" @checked(old('cakupan') === 'semua')> Semua barang aktif (termasuk yang belum pernah tercatat di sini)</label>
            </div>
            <x-field name="kode_bagian" label="Bagian yang menanggung selisih" :value="old('kode_bagian')" :options="$bagianOptions"
                     hint="Wajib sebelum diajukan bila ada selisih — akun Selisih Persediaan adalah akun beban." />
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('opname_persediaan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Buat Lembar Hitung</button>
            </div>
        </form>
    </div>
@endsection
