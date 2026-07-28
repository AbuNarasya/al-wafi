@extends('layouts.app')

@php
    $advData = $outstanding->map(fn ($a) => [
        'id' => $a->id, 'ref' => $a->nomor_ref, 'sisa' => (float) $a->sisa, 'akun' => $a->nama_coa_uang_muka,
    ])->values();
@endphp

@section('title', 'Penyelesaian Uang Muka Baru')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('advance_settlement.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if ($outstanding->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">
                Tidak ada uang muka operasional yang outstanding. Buat dulu di menu <a href="{{ route('operational_advance.create') }}" class="text-brand hover:underline">Uang Muka Operasional</a>.
            </div>
        @else
            <form method="POST" action="{{ route('advance_settlement.store') }}" x-data="settle(@js($advData), @js((int) old('id_uang_muka')))"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf

                <div>
                    <label for="id_uang_muka" class="mb-1 block text-sm font-medium text-gray-700">Uang Muka <span class="text-red-500">*</span></label>
                    <select id="id_uang_muka" name="id_uang_muka" x-model.number="selectedId" required class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm">
                        <option value="">— pilih uang muka outstanding —</option>
                        @foreach ($outstanding as $a)
                            <option value="{{ $a->id }}">{{ $a->nomor_ref }} — {{ $a->nama_coa_uang_muka }} (sisa Rp {{ number_format((float) $a->sisa, 0, ',', '.') }})</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-brand" x-show="selected" x-cloak>
                        Sisa outstanding: <span x-text="fmt(selected?.sisa)"></span>
                    </p>
                    @error('id_uang_muka')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Nominal Uang Muka Diselesaikan <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" min="0" name="nominal_uang_muka" x-model="nominalUm" required
                               :max="selected?.sisa" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm">
                        @error('nominal_uang_muka')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="kode_coa_realisasi" label="Akun Realisasi (beban/aset)" :value="old('kode_coa_realisasi')" :options="$coaOptions" required />
                    <x-field name="nominal_realisasi" label="Nominal Realisasi (aktual)" type="number" :value="old('nominal_realisasi')" required />
                </div>

                <x-field name="kode_rekening" label="Kas/Rekening (untuk selisih)" :value="old('kode_rekening')" :options="$rekeningOptions" required
                         hint="Dipakai bila realisasi ≠ uang muka: selisih dibayar/dikembalikan lewat rekening ini." />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="$unitOptions" />
                    <x-field name="kode_bagian" label="Bagian" :value="old('kode_bagian')" :options="$bagianOptions" />
                </div>

                <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" textarea required />

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route('advance_settlement.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Posting Penyelesaian</button>
                </div>
            </form>
        @endif
    </div>

    <script>
        function settle(advances, initId) {
            return {
                advances,
                selectedId: initId || '',
                nominalUm: '',
                get selected() { return this.advances.find(a => a.id === this.selectedId) || null; },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
                init() {
                    this.$watch('selectedId', () => { if (this.selected && !this.nominalUm) this.nominalUm = this.selected.sisa; });
                },
            };
        }
    </script>
@endsection
