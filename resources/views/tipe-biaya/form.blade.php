@extends('layouts.app')

@section('title', $baru ? 'Tambah Tipe Biaya' : 'Ubah ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('tipe_biaya.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('tipe_biaya.store') : route('tipe_biaya.update', $row->kode) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($row->bawaan)
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    Ini tipe <b>bawaan</b>. Nama, urutan, dan keterangannya bebas diubah, tetapi kode, perilaku, dan statusnya
                    dikunci — alur registrasi, uang pangkal, SPP, dan tagihan lain-lain bersandar padanya.
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($baru)
                    <x-field name="kode" label="Kode" :value="$row->kode" required placeholder="mis. seragam" />
                @else
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode</label><div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
                @endif
                <x-field name="nama" label="Nama" :value="$row->nama" required />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Perilaku <span class="text-red-500">*</span></label>
                <select name="perilaku" required @disabled($row->bawaan)
                        class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand disabled:bg-gray-100">
                    @foreach (\App\Models\TipeBiaya::PERILAKU as $kode => $label)
                        <option value="{{ $kode }}" @selected(old('perilaku', $row->perilaku) === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
                @if ($row->bawaan)<input type="hidden" name="perilaku" value="{{ $row->perilaku }}">@endif
                <p class="mt-1 text-xs text-gray-400">
                    Menentukan alur yang diikuti tipe ini — termasuk modul pembayaran mana yang menanganinya.
                    Tipe baru untuk tagihan seperti seragam atau kegiatan biasanya memilih <b>Lain-lain</b>.
                </p>
                @error('perilaku')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="urutan" label="Urutan Tampil" type="number" :value="$row->urutan ?? 0" />
                @if ($row->bawaan)
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Status</label><div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">Aktif (dikunci)</div><input type="hidden" name="status" value="aktif"></div>
                @else
                    <x-field name="status" label="Status" :value="$row->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" required />
                @endif
            </div>

            <x-field name="keterangan" label="Keterangan" :value="$row->keterangan" textarea />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('tipe_biaya.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
