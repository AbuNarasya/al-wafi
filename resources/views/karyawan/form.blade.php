@extends('layouts.app')

@section('title', $baru ? 'Tambah Karyawan' : 'Ubah ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('karyawan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('karyawan.store') : route('karyawan.update', $row->kode) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode" label="Kode / NIK" :value="$row->kode" required placeholder="mis. K001" />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode / NIK</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
            @endif

            <x-field name="nama" label="Nama" :value="$row->nama" required />
            <x-field name="jabatan" label="Jabatan" :value="$row->jabatan" />
            <x-field name="kode_bagian" label="Bagian" :value="$row->kode_bagian" :options="$bagianOptions"
                     hint="Menentukan bagian yang memikul beban gaji saat cicilan pinjaman dipotong dari gaji." />
            <x-field name="id_pengguna" label="Akun Login" :value="$row->id_pengguna" :options="$penggunaOptions"
                     hint="Opsional — tidak semua karyawan punya akun aplikasi." />
            <x-field name="status" label="Status" :value="$row->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" required />
            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('karyawan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
