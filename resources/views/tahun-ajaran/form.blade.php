@extends('layouts.app')

@section('title', $baru ? 'Tambah Tahun Ajaran' : 'Ubah Tahun Ajaran ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('tahun_ajaran.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('tahun_ajaran.store') : route('tahun_ajaran.update', $row->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field name="kode" label="Tahun Ajaran" :value="old('kode', $row->kode)" required placeholder="mis. 2026/2027"
                         hint="Format YYYY/YYYY. Menjadi rujukan master lain — tidak bisa diubah setelah disimpan." />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Tahun Ajaran</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="tanggal_mulai" label="Tanggal Mulai" type="date" :value="old('tanggal_mulai', optional($row->tanggal_mulai)->format('Y-m-d'))" />
                <x-field name="tanggal_selesai" label="Tanggal Selesai" type="date" :value="old('tanggal_selesai', optional($row->tanggal_selesai)->format('Y-m-d'))" />
            </div>

            <x-field name="status" label="Status" :value="old('status', $row->status ?? 'aktif')" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="default_pendaftaran" value="0">
                <input type="checkbox" name="default_pendaftaran" value="1"
                       @checked(old('default_pendaftaran', $row->default_pendaftaran))
                       class="rounded border-gray-300 text-brand focus:ring-brand">
                Jadikan default form pendaftaran calon santri
            </label>
            <p class="-mt-2 text-xs text-gray-400">Hanya satu tahun ajaran yang bisa menjadi default; default lama otomatis dilepas.</p>

            <x-field name="keterangan" label="Keterangan" :value="old('keterangan', $row->keterangan)" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('tahun_ajaran.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
