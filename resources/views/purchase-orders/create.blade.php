@extends('layouts.app')

@section('title', 'Purchase Order Baru')

@php
    $kosong = ['kode_coa' => '', 'keterangan' => '', 'kuantiti' => '1', 'harga_satuan' => ''];
    $initRows = array_values(old('details', [$kosong]));
    $opts = ['coa' => $coaOptions];
@endphp

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('purchase_orders.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('purchase_orders.store') }}" x-data="po(@js($initRows), @js($opts))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="max-w-xs">
                <label class="mb-1 block text-sm font-medium text-gray-700">No. PO (otomatis)</label>
                <div class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $nomorPreview }}</div>
                <p class="mt-1 text-xs text-gray-400">Nomor final ditetapkan saat simpan.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tanggal_po" label="Tanggal PO" type="date" :value="old('tanggal_po', now()->toDateString())" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Vendor <span class="text-red-500">*</span></label>
                    <x-search-select name="kode_vendor" :options="$vendorOptions" :value="old('kode_vendor')" placeholder="— pilih vendor —" required />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Unit Bisnis <span class="text-red-500">*</span></label>
                    <x-search-select name="kode_unit" :options="$unitOptions" :value="old('kode_unit')" placeholder="— pilih unit —" required />
                </div>
            </div>
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="w-full min-w-[48rem] text-sm">
                    <colgroup><col style="width:34%"><col style="width:28%"><col style="width:11%"><col style="width:13%"><col style="width:12%"><col style="width:2%"></colgroup>
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-2 py-2">Akun / Item</th><th class="px-2 py-2">Keterangan</th><th class="px-2 py-2 text-right">Qty</th><th class="px-2 py-2 text-right">Harga</th><th class="px-2 py-2 text-right">Subtotal</th><th></th></tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="i">
                            <tr class="border-t border-gray-100 align-top">
                                <td class="px-2 py-1.5"><x-search-cell name="`details[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" /></td>
                                <td class="px-2 py-1.5"><input type="text" :name="`details[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand"></td>
                                <td class="px-2 py-1.5"><input type="number" step="0.01" min="0" :name="`details[${i}][kuantiti]`" x-model="row.kuantiti" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm focus:border-brand focus:ring-1 focus:ring-brand"></td>
                                <td class="px-2 py-1.5"><input type="text" inputmode="numeric" :value="fmtRupiah(row.harga_satuan)" @input="row.harga_satuan = ketikRupiah($event)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand"><input type="hidden" :name="`details[${i}][harga_satuan]`" :value="row.harga_satuan"></td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-gray-600" x-text="fmt((parseFloat(row.kuantiti)||0) * (parseFloat(row.harga_satuan)||0))"></td>
                                <td class="px-1 py-1.5 text-center"><button type="button" @click="hapus(i)" x-show="rows.length > 1" class="text-red-500 hover:text-red-700">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="border-t border-gray-200 font-medium">
                            <td class="px-2 py-2" colspan="4"><button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah baris</button></td>
                            <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(total)"></td><td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('purchase_orders.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" :disabled="total <= 0"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Simpan PO</button>
            </div>
        </form>
    </div>

    <script>
        function po(initRows, opts) {
            return {
                rows: initRows.length ? initRows : [{}],
                coaOpts: opts.coa || [],
                tambah() { this.rows.push({ kode_coa: '', keterangan: '', kuantiti: '1', harga_satuan: '' }); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                get total() { return this.rows.reduce((s, r) => s + (parseFloat(r.kuantiti) || 0) * (parseFloat(r.harga_satuan) || 0), 0); },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
