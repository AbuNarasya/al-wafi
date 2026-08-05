@extends('layouts.app')

@section('title', $baru ? 'Tambah Sumber Informasi' : 'Ubah ' . $row->kode)

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('sumber_informasi.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('sumber_informasi.store') : route('sumber_informasi.update', $row->kode) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @unless ($baru) @method('PUT') @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($baru)
                    <x-field name="kode" label="Kode" :value="$row->kode" required placeholder="mis. brosur" />
                @else
                    <div><label class="mb-1 block text-sm font-medium text-gray-700">Kode</label><div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600">{{ $row->kode }}</div></div>
                @endif
                <x-field name="nama" label="Nama" :value="$row->nama" required placeholder="mis. Brosur / Spanduk" />
            </div>

            {{-- Urutan tampil diatur dengan menyeret baris di halaman daftar. --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="status" label="Status" :value="$row->status ?? 'aktif'" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" required />
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="hidden" name="butuh_keterangan" value="0">
                <input type="checkbox" name="butuh_keterangan" value="1" @checked(old('butuh_keterangan', $row->butuh_keterangan))
                       class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                <span>
                    Minta keterangan tambahan
                    <span class="block text-xs text-gray-400">Formulir pendaftaran menampilkan isian teks bebas bila pilihan ini dipilih (seperti &ldquo;Lainnya&rdquo;).</span>
                </span>
            </label>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('sumber_informasi.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">{{ $baru ? 'Simpan' : 'Perbarui' }}</button>
            </div>
        </form>
    </div>
@endsection
