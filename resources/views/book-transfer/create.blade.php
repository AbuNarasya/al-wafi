@extends('layouts.app')

@section('title', 'Pindah Buku Baru')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ route('book_transfer.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @php
            $rekList = collect($rekeningOptions)->map(fn ($l, $v) => ['v' => (string) $v, 'l' => (string) $l])->values()->all();
            $inputCls = 'w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand';
        @endphp
        <form method="POST" action="{{ route('book_transfer.store') }}"
              x-data="pindahBuku(@js(old('kode_rekening_asal', '')), @js(old('kode_rekening_tujuan', '')))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Rekening Asal (dana keluar) <span class="text-red-500">*</span></label>
                <select name="kode_rekening_asal" x-model="asal" required class="{{ $inputCls }}">
                    <option value="">— pilih —</option>
                    @foreach ($rekList as $r)<option value="{{ $r['v'] }}">{{ $r['l'] }}</option>@endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Rekening Tujuan (dana masuk) <span class="text-red-500">*</span></label>
                <select name="kode_rekening_tujuan" x-model="tujuan" required class="{{ $inputCls }}">
                    <option value="">— pilih —</option>
                    <template x-for="r in tujuanOpts" :key="r.v"><option :value="r.v" x-text="r.l"></option></template>
                </select>
                <p class="mt-1 text-xs text-gray-400">Pilihan tujuan otomatis mengecualikan rekening asal.</p>
            </div>

            <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="['' => '— opsional —'] + $unitOptions"
                     hint="Opsional — bila kosong, jurnal memakai Default Unit modul." />
            <x-field name="nominal" label="Nominal" type="number" :value="old('nominal')" required />
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" required />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('book_transfer.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Posting Pindah Buku</button>
            </div>
        </form>
    </div>

    <script>
        function pindahBuku(asal, tujuan) {
            return {
                rek: @js($rekList),
                asal, tujuan,
                get tujuanOpts() { return this.rek.filter((r) => String(r.v) !== String(this.asal)); },
                init() { this.$watch('asal', () => { if (this.tujuan && String(this.tujuan) === String(this.asal)) this.tujuan = ''; }); },
            };
        }
    </script>
@endsection
