@extends('layouts.app')

@section('title', $aset->exists ? 'Ubah Aset' : 'Tambah Aset Tetap')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('assets.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $aset->exists ? route('assets.update', $aset->kode_aset) : route('assets.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($aset->exists) @method('PUT') @endif

            @if ($aset->exists)
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kode Aset</label>
                    <div class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $aset->kode_aset }}</div>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama_aset" label="Nama Aset" :value="old('nama_aset', $aset->nama_aset)" required />
                <x-field name="kategori_aset" label="Kategori" :value="old('kategori_aset', $aset->kategori_aset)" :options="$kategoriOptions" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="harga_perolehan" label="Harga Perolehan" type="number" :value="old('harga_perolehan', $aset->harga_perolehan)" required />
                <x-field name="tanggal_perolehan" label="Tanggal Perolehan" type="date" :value="old('tanggal_perolehan', optional($aset->tanggal_perolehan)->format('Y-m-d'))" required />
                <x-field name="kuantiti" label="Kuantiti" type="number" :value="old('kuantiti', $aset->kuantiti ?? 1)" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="umur_manfaat" label="Umur Manfaat (bulan)" type="number" :value="old('umur_manfaat', $aset->umur_manfaat)" required />
                <x-field name="metode_depresiasi" label="Metode Depresiasi" :value="old('metode_depresiasi', $aset->metode_depresiasi)" :options="['garis_lurus' => 'Garis Lurus', 'saldo_menurun' => 'Saldo Menurun']" required />
                <x-field name="nilai_residu" label="Nilai Residu" type="number" :value="old('nilai_residu', $aset->nilai_residu ?? 0)" />
            </div>

            @unless ($aset->exists)
                <x-field name="akumulasi_depresiasi" label="Akumulasi Depresiasi Awal" type="number" :value="old('akumulasi_depresiasi', 0)" hint="Isi bila aset sudah berjalan; setelahnya bertambah otomatis via Jalankan Depresiasi." />
            @endunless

            <x-field name="kode_coa" label="Akun COA Aset" :value="old('kode_coa', $aset->kode_coa)" :options="$coaOptions"
                     hint="Opsional. Akun aset tetap di Chart of Account." />

            <x-field name="status" label="Status" :value="old('status', $aset->status ?? 'aktif')"
                     :options="['draft' => 'Draft (perlu dilengkapi)', 'aktif' => 'Aktif', 'dilepas' => 'Dilepas']" required />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('assets.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
