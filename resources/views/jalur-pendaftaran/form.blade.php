@extends('layouts.app')

@section('title', $baru ? 'Tambah Jalur Pendaftaran' : 'Ubah Jalur ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('jalur_pendaftaran.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('jalur_pendaftaran.store') : route('jalur_pendaftaran.update', $row->kode) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode" label="Kode" :value="$row->kode" required placeholder="mis. reguler" hint="Huruf/angka/underscore. Dipakai sebagai nilai jalur santri." />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
            @endif

            <x-field name="nama" label="Nama Jalur" :value="$row->nama" required placeholder="mis. Reguler"
                     hint="Jalur berlaku untuk SEMUA tahun ajaran. Tarif yang berbeda tiap tahun diatur di Jenis Biaya, bukan di sini." />
            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />
            <x-field name="status" label="Status" :value="$row->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('jalur_pendaftaran.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
