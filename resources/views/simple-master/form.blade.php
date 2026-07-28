@extends('layouts.app')

@section('title', ($baru ? 'Tambah ' : 'Ubah ') . $c['label'])

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route($c['route'] . '.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <form method="POST" action="{{ $baru ? route($c['route'] . '.store') : route($c['route'] . '.update', $row->{$c['pk']}) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <x-field :name="$c['pk']" label="Kode" :value="$row->{$c['pk']}" required />
            @else
                <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode</label>
                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->{$c['pk']} }}</div></div>
            @endif

            <x-field name="nama" label="Nama" :value="$row->nama" required />
            @if ($c['keterangan'])
                <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />
            @endif
            <x-field name="status" label="Status" :value="$row->status ?? 'aktif'"
                     :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route($c['route'] . '.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
