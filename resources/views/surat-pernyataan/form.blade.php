@extends('layouts.app')

@section('title', $baru ? 'Surat Pernyataan — Versi Baru' : 'Ubah Surat Pernyataan Versi '.$row->versi)

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('surat_pernyataan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('surat_pernyataan.store') : route('surat_pernyataan.update', $row->id) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            @if ($baru)
                <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                    Nomor versi diberikan otomatis. Isinya disalin dari versi yang sedang aktif supaya tinggal direvisi.
                    Versi baru <b>belum aktif</b> sampai Anda menekan &ldquo;Jadikan Aktif&rdquo; di daftar &mdash;
                    kecuali bila ini versi pertama.
                </p>
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-field name="judul" label="Judul" :value="$row->judul" required />
                </div>
                <x-field name="berlaku_mulai" label="Berlaku mulai" type="date" :value="$row->berlaku_mulai?->toDateString()" required />
            </div>

            <div>
                <label for="isi" class="mb-1 block text-sm font-medium text-gray-700">Isi <span class="text-red-500">*</span></label>
                <textarea id="isi" name="isi" rows="20" required
                          @class(['w-full rounded-lg border px-3 py-2 text-sm leading-relaxed focus:border-brand focus:ring-brand', 'border-red-400' => $errors->has('isi'), 'border-gray-300' => ! $errors->has('isi')])>{{ old('isi', $row->isi) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">
                    Teks biasa. Baris baru dan penomoran (1. 2. 3.) tampil apa adanya kepada wali.
                </p>
                @error('isi')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('surat_pernyataan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
