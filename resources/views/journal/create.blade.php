@extends('layouts.app')

@section('title', 'Jurnal Umum Baru')

@php
    $kosong = ['kode_coa' => '', 'keterangan' => '', 'debet' => '', 'kredit' => '', 'kode_bagian' => '', 'kode_persediaan' => '', 'kuantiti' => ''];
    $initRows = array_values(old('lines', [$kosong, $kosong]));
    $opts = ['coa' => $coaOptions, 'bagian' => $bagianOptions, 'inventory' => $inventoryOptions];
@endphp

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('journal.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('journal.store') }}"
              x-data="jurnal(@js($initRows), @js($opts))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <p class="rounded bg-gray-50 px-3 py-2 text-xs text-gray-500">Setiap jurnal manual wajib balance (total debet = total kredit) sebelum disimpan. Baris ber-persediaan menggerakkan stok: debit = stok masuk (harga = nilai debit), kredit = stok keluar.</p>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Unit Bisnis</label>
                    <x-search-select name="kode_unit" :options="['' => '— Default modul —'] + $unitOptions" :value="old('kode_unit')" placeholder="— Default modul —" />
                </div>
                <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />
            </div>

            <div class="space-y-3">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3">
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Akun</label>
                                <x-search-cell name="`lines[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" />
                            </div>
                            <div class="col-span-5 sm:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Debet</label>
                                <input type="number" step="0.01" min="0" :name="`lines[${i}][debet]`" x-model="row.debet" @input="if (row.debet) row.kredit = ''" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            </div>
                            <div class="col-span-5 sm:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Kredit</label>
                                <input type="number" step="0.01" min="0" :name="`lines[${i}][kredit]`" x-model="row.kredit" @input="if (row.kredit) row.debet = ''" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            </div>
                            <div class="col-span-2 sm:col-span-1 pb-1 text-center">
                                <button type="button" @click="hapus(i)" x-show="rows.length > 2" class="text-red-500 hover:text-red-700" title="Hapus baris">&times;</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Persediaan <span class="font-normal text-gray-400">(opsional)</span></label>
                                <x-search-cell name="`lines[${i}][kode_persediaan]`" model="row.kode_persediaan" options="inventoryOpts" placeholder="— tanpa persediaan —" />
                            </div>
                            <div class="col-span-5 sm:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Qty</label>
                                <input type="number" step="0.0001" min="0" :name="`lines[${i}][kuantiti]`" x-model="row.kuantiti" :disabled="!row.kode_persediaan"
                                       class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm focus:border-brand focus:ring-1 focus:ring-brand disabled:bg-gray-100 disabled:text-gray-400">
                            </div>
                            <div class="col-span-3 pb-2 text-center text-[11px] text-gray-400" title="Debit=stok masuk, Kredit=stok keluar">
                                <span x-show="row.kode_persediaan && parseFloat(row.kuantiti) > 0" x-text="parseFloat(row.debet) > 0 ? '↗ masuk' : (parseFloat(row.kredit) > 0 ? '↘ keluar' : '')"></span>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Bagian <span class="font-normal text-gray-400">(wajib untuk akun Beban)</span></label>
                                <x-search-cell name="`lines[${i}][kode_bagian]`" model="row.kode_bagian" options="bagianOpts" placeholder="— tanpa bagian —" />
                            </div>
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Keterangan baris <span class="font-normal text-gray-400">(opsional)</span></label>
                                <input type="text" :name="`lines[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-2">
                <button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah baris</button>
                <div class="flex items-center gap-4 text-sm">
                    <span>Total Debet: <b class="tabular-nums" x-text="fmt(totalDebet)"></b></span>
                    <span>Total Kredit: <b class="tabular-nums" x-text="fmt(totalKredit)"></b></span>
                    <span x-show="balanced" class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">✓ Balance</span>
                    <span x-show="!balanced" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700"
                          x-text="'Selisih: ' + fmt(Math.abs(totalDebet - totalKredit))"></span>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('journal.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" :disabled="!balanced"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50">
                    Posting Jurnal
                </button>
            </div>
        </form>
    </div>

    <script>
        function jurnal(initRows, opts) {
            return {
                rows: initRows.length ? initRows : [{}, {}],
                coaOpts: opts.coa || [],
                bagianOpts: opts.bagian || [],
                inventoryOpts: opts.inventory || [],
                tambah() { this.rows.push({ kode_coa: '', keterangan: '', debet: '', kredit: '', kode_bagian: '', kode_persediaan: '', kuantiti: '' }); },
                hapus(i) { if (this.rows.length > 2) this.rows.splice(i, 1); },
                get totalDebet() { return this.rows.reduce((s, r) => s + (parseFloat(r.debet) || 0), 0); },
                get totalKredit() { return this.rows.reduce((s, r) => s + (parseFloat(r.kredit) || 0), 0); },
                get balanced() { return this.totalDebet > 0 && Math.abs(this.totalDebet - this.totalKredit) < 0.005; },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
