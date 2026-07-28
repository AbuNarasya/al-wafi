@extends('layouts.app')

@section('title', 'Ubah Level Pengajuan (Peringkat ' . $row->peringkat . ')')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('level_pengajuan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('level_pengajuan.update', $row) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @method('PUT')

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Peringkat</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->peringkat }} (1 = tertinggi)</div>
            </div>

            <x-field name="nama" label="Nama" :value="$row->nama" required />

            <x-field name="status" label="Status" :value="$row->status"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('level_pengajuan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Perbarui</button>
            </div>
        </form>
    </div>
@endsection
