@extends('layouts.app')

@section('title', 'Receivable Voucher (RV) — Kas Masuk Baru')

@php
    $kosong = ['kode_coa' => '', 'jenis_kas_masuk' => 'pendapatan', 'keterangan' => '', 'kode_bagian' => '', 'nominal' => '', 'kode_persediaan' => '', 'kuantiti' => ''];
    $initRows = array_values(old('details', [$kosong]));
    $jenisOpts = [
        ['v' => 'pendapatan', 'l' => 'Pendapatan'],
        ['v' => 'pelunasan', 'l' => 'Pelunasan'],
        ['v' => 'uang_muka', 'l' => 'Pendapatan Diterima Dimuka'],
        ['v' => 'lain', 'l' => 'Lain-lain'],
    ];
    $opts = ['coa' => $coaOptions, 'bagian' => $bagianOptions, 'jenis' => $jenisOpts, 'inventory' => $inventoryOptions];
@endphp

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('cash_in.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('cash_in.store') }}" x-data="kasMasuk(@js($initRows), @js($opts))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">No. RV (otomatis)</label>
                    <div class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $nomorPreview }}</div>
                    <p class="mt-1 text-xs text-gray-400">Nomor final ditetapkan saat posting.</p>
                </div>
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kas/Rekening Tujuan <span class="text-red-500">*</span></label>
                    <x-search-select name="kode_rekening" :options="$rekeningOptions" :value="old('kode_rekening')" placeholder="— pilih rekening —" required />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Unit Bisnis <span class="text-red-500">*</span></label>
                    <x-search-select name="kode_unit" :options="$unitOptions" :value="old('kode_unit')" placeholder="— pilih unit —" required />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Customer</label>
                    <x-search-select name="kode_customer" :options="$customerOptions" :value="old('kode_customer')" placeholder="— tanpa customer —" />
                </div>
                <x-field name="referensi" label="Referensi" :value="old('referensi')" />
            </div>
            <x-field name="keterangan" label="Keterangan Voucher" :value="old('keterangan')" required />

            <div class="border-t border-gray-200 pt-3 text-sm font-medium text-gray-700">Rincian Akun (dikredit)</div>

            <div class="space-y-3">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3">
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-4">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Akun</label>
                                <x-search-cell name="`details[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" />
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Jenis</label>
                                <x-search-cell name="`details[${i}][jenis_kas_masuk]`" model="row.jenis_kas_masuk" options="jenisOpts" placeholder="— jenis —" />
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Nominal</label>
                                <input type="text" inputmode="numeric" :value="fmtRupiah(row.nominal)" @input="row.nominal = ketikRupiah($event)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand"><input type="hidden" :name="`details[${i}][nominal]`" :value="row.nominal">
                            </div>
                            <div class="col-span-12 sm:col-span-2">
                                <button type="button" @click="hapus(i)" x-show="rows.length > 1" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-red-600 hover:bg-red-50">Hapus</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Keterangan</label>
                                <input type="text" :name="`details[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            </div>
                        </div>
                        {{-- Persediaan terjual jarang dipakai — hanya bila kas masuknya
                             memang menjual barang. Dua isian yang hampir selalu kosong
                             membuat baris rincian tampak lebih rumit daripada
                             pekerjaannya, jadi keduanya disembunyikan sampai diminta.
                             Muncul sendiri bila barisnya sudah berisi persediaan
                             (mis. isian lama setelah validasi gagal). --}}
                        <div x-show="!row.pakaiPersediaan">
                            <button type="button" @click="row.pakaiPersediaan = true"
                                    class="text-xs font-medium text-brand hover:underline">+ Persediaan terjual</button>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2" x-show="row.pakaiPersediaan" x-cloak>
                            <div class="col-span-12 sm:col-span-7">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Persediaan Terjual <span class="font-normal text-gray-400">(&rarr; stok keluar)</span></label>
                                <x-search-cell name="`details[${i}][kode_persediaan]`" model="row.kode_persediaan" options="inventoryOpts" placeholder="— tanpa persediaan —" />
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Qty Keluar</label>
                                <input type="number" step="0.0001" min="0" :name="`details[${i}][kuantiti]`" x-model="row.kuantiti" :disabled="!row.kode_persediaan"
                                       class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm focus:border-brand focus:ring-1 focus:ring-brand disabled:bg-gray-100 disabled:text-gray-400">
                                <p class="mt-1 text-xs text-gray-400" x-show="row.kode_persediaan && parseFloat(row.kuantiti) > 0">&darr; stok keluar</p>
                            </div>
                            <div class="col-span-6 sm:col-span-2 pb-2 text-right">
                                <button type="button" @click="lepasPersediaan(row)"
                                        class="text-xs text-gray-400 hover:text-red-600">Hapus</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-7">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Bagian <span class="font-normal text-gray-400">(dimensi anggaran)</span></label>
                                <x-search-cell name="`details[${i}][kode_bagian]`" model="row.kode_bagian" options="bagianOpts" placeholder="— tanpa bagian —" />
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 pt-2">
                <button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah baris</button>
                <div class="text-sm">Total Voucher: <b class="tabular-nums" x-text="fmt(total)"></b></div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('cash_in.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" :disabled="total <= 0"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Posting Kas Masuk</button>
            </div>
        </form>
    </div>

    <script>
        function kasMasuk(initRows, opts) {
            // `pakaiPersediaan` hanya menyetel tampilan (tak ikut terkirim). Dihidupkan
            // untuk baris yang SUDAH berisi persediaan supaya isian lama — yang kembali
            // setelah validasi gagal — tidak tersembunyi tanpa jejak.
            const siapkan = (r) => ({ kode_coa: '', jenis_kas_masuk: 'pendapatan', keterangan: '', kode_bagian: '', nominal: '', kode_persediaan: '', kuantiti: '', ...r, pakaiPersediaan: !!r.kode_persediaan });

            return {
                rows: (initRows.length ? initRows : [{}]).map(siapkan),
                coaOpts: opts.coa || [],
                bagianOpts: opts.bagian || [],
                jenisOpts: opts.jenis || [],
                inventoryOpts: opts.inventory || [],
                tambah() { this.rows.push(siapkan({})); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                /** Tutup blok persediaan SEKALIGUS mengosongkan isinya — kalau tidak,
                    persediaan yang tak terlihat lagi tetap ikut terkirim & mengurangi stok. */
                lepasPersediaan(row) { row.kode_persediaan = ''; row.kuantiti = ''; row.pakaiPersediaan = false; },
                get total() { return this.rows.reduce((s, r) => s + (parseFloat(r.nominal) || 0), 0); },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
