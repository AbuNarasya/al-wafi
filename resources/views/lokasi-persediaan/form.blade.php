@extends('layouts.app')

@section('title', $row->exists ? 'Ubah Lokasi Persediaan' : 'Tambah Lokasi Persediaan')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('lokasi_persediaan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $row->exists ? route('lokasi_persediaan.update', $row->kode) : route('lokasi_persediaan.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @if ($row->exists) @method('PUT') @endif

            <x-field name="nama" label="Nama Lokasi" :value="old('nama', $row->nama)" required placeholder="Koperasi Banin" />
            <x-field name="jenis" label="Jenis" :value="old('jenis', $row->jenis)" :options="\App\Models\LokasiPersediaan::JENIS" required
                     hint="Gudang = penyimpanan; Toko = tempat barang dijual; Transit = barang dalam perjalanan antar lokasi." />
            <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit', $row->kode_unit)" :options="$unitOptions"
                     hint="Jurnal pemakaian & opname di lokasi ini dibukukan ke unit ini." />
            <div class="rounded-lg border border-brand/30 bg-brand-soft/40 p-4">
                <div class="mb-1 text-sm font-semibold text-gray-800">Akun Kasir <span class="font-normal text-gray-500">(khusus lokasi toko)</span></div>
                <p class="mb-3 text-xs text-gray-600">
                    Diisi bagian keuangan. Saat shift kasir ditutup, satu jurnal ringkas terbit:
                    D Kas Laci / K Pendapatan, D HPP / K Persediaan, dan selisih uang laci ke akun Selisih Kas.
                    Shift pertama ditolak selama isian ini belum lengkap.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="kode_coa_kas" label="Kas Laci" :value="old('kode_coa_kas', $row->kode_coa_kas)" :options="$kasOptions"
                             hint="Harus terdaftar di Kas & Rekening — isi modal awalnya lewat Pindah Buku." />
                    <x-field name="kode_coa_pendapatan" label="Pendapatan Penjualan" :value="old('kode_coa_pendapatan', $row->kode_coa_pendapatan)" :options="$pendapatanOptions" />
                    <x-field name="kode_coa_hpp" label="Harga Pokok Penjualan" :value="old('kode_coa_hpp', $row->kode_coa_hpp)" :options="$bebanOptions" />
                    <x-field name="kode_coa_selisih_kas" label="Selisih Kas" :value="old('kode_coa_selisih_kas', $row->kode_coa_selisih_kas)" :options="$selisihOptions"
                             hint="Uang laci kurang/lebih saat tutup shift." />
                    <x-field name="kode_bagian" label="Bagian Pengelola Toko" :value="old('kode_bagian', $row->kode_bagian)" :options="$bagianOptions"
                             hint="Dimensi wajib untuk baris HPP & selisih kas (akun beban)." />
                </div>
            </div>

            <x-field name="status" label="Status" :value="old('status', $row->status ?? 'aktif')" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" required />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('lokasi_persediaan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
