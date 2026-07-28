@extends('layouts.app')

@section('title', 'Terbitkan Tagihan Lain')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('tagihan_lain.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if ($santriAktif->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">Belum ada santri aktif.</div>
        @else
            <form method="POST" action="{{ route('tagihan_lain.store') }}" x-data="{ all: false }"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf

                <x-field name="kode_jenis" label="Jenis Biaya (tipe Lain-lain)" :value="old('kode_jenis')" :options="$jenisOptions" required />

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field name="nominal" label="Nominal per Santri" type="number" :value="old('nominal')" required />
                    <x-field name="periode" label="Periode (opsional)" :value="old('periode')" placeholder="2026-07" />
                    <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700">Santri ({{ $santriAktif->count() }} aktif)</label>
                        <label class="flex items-center gap-1 text-xs text-gray-500"><input type="checkbox" x-model="all" @change="document.querySelectorAll('.santri-cb').forEach(c => c.checked = all)" class="rounded border-gray-300"> Pilih semua</label>
                    </div>
                    <div class="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-3">
                        @foreach ($santriAktif as $s)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" class="santri-cb rounded border-gray-300 text-brand" name="id_santri[]" value="{{ $s->id }}">
                                {{ $s->nama }} <span class="text-xs text-gray-400">{{ $s->kode_jenjang }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('id_santri')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route('tagihan_lain.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terbitkan</button>
                </div>
            </form>
        @endif
    </div>
@endsection
