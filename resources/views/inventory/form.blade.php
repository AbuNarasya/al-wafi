@extends('layouts.app')

@section('title', $item->exists ? 'Ubah Persediaan' : 'Tambah Persediaan')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('inventory.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $item->exists ? route('inventory.update', $item->kode_persediaan) : route('inventory.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($item->exists) @method('PUT') @endif

            @if ($item->exists)
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kode Persediaan</label>
                    <div class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $item->kode_persediaan }}</div>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama_persediaan" label="Nama Persediaan" :value="old('nama_persediaan', $item->nama_persediaan)" required />
                <x-field name="satuan" label="Satuan" :value="old('satuan', $item->satuan)" required placeholder="pcs / kg / box" />
            </div>

            <x-field name="harga_perolehan" label="Harga Perolehan Awal" type="number" :value="old('harga_perolehan', $item->harga_perolehan)" required
                     hint="Dipakai sebagai harga acuan saat opname menemukan stok lebih. Harga sesungguhnya mengikuti lapisan FIFO tiap pembelian." />

            <div class="rounded-lg border border-brand/30 bg-brand-soft/40 p-4">
                <div class="mb-1 text-sm font-semibold text-gray-800">Akun Akuntansi</div>
                <p class="mb-3 text-xs text-gray-600">
                    Diisi <b>bagian keuangan</b>, sekali saja. Petugas gudang kelak hanya mencatat pemakaian &amp; opname
                    berikut bagian yang memakainya — akun lawannya diambil dari sini, jadi mereka tak perlu memilih akun sama sekali.
                </p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field name="kode_coa" label="Akun Persediaan" :value="old('kode_coa', $item->kode_coa)" :options="$coaOptions" required
                             hint="Aset — nilai barang di gudang." />
                    <x-field name="kode_coa_beban" label="Akun Beban Pemakaian" :value="old('kode_coa_beban', $item->kode_coa_beban)" :options="$coaOptions" required
                             hint="Didebet saat barang dipakai atau terjual." />
                    <x-field name="kode_coa_selisih" label="Akun Selisih Persediaan" :value="old('kode_coa_selisih', $item->kode_coa_selisih)" :options="$coaOptions" required
                             hint="Dipakai saat opname menemukan selisih." />
                </div>
            </div>

            @unless ($item->exists)
                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Stok awal tidak diisi di sini. Setelah barang tersimpan, catat stok pembukaannya lewat
                    <b>Opname</b> di daftar persediaan — supaya ia punya baris kartu stok dan jurnalnya sendiri,
                    sama seperti pergerakan lainnya.
                </div>
            @endunless

            <x-field name="status" label="Status" :value="old('status', $item->status ?? 'aktif')" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" required />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('inventory.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
