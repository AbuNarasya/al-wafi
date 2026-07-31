@extends('layouts.app')

@section('title', 'Invoice Vendor Baru')

@php
    $toList = fn ($arr) => collect($arr)->map(fn ($l, $v) => is_array($l) && isset($l['v']) ? $l : ['v' => (string) $v, 'l' => (string) $l])->values()->all();
    $kosong = ['kode_coa' => '', 'keterangan' => '', 'kode_bagian' => '', 'kuantiti' => '1', 'harga_satuan' => '', 'aset_pilih' => ''];
    $initRows = array_values(old('details', [$kosong]));
    $opts = [
        'coa' => $toList($coaOptions), 'bagian' => $toList($bagianOptions),
        'vendor' => $toList($vendorOptions), 'unit' => $toList($unitOptions),
        'hutang' => $toList($hutangOptions), 'po' => $toList($poOptions),
        'aset' => $toList($asetOptions),
    ];
@endphp

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('invoices.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('invoices.store') }}"
              x-data="invoice(@js($initRows), @js($poData), @js($opts), { vendor: @js(old('kode_vendor', '')), unit: @js(old('kode_unit', '')), hutang: @js(old('kode_coa_hutang', '')), tglInvoice: @js(old('tanggal_invoice', now()->toDateString())), tglJatuhTempo: @js(old('tanggal_jatuh_tempo', now()->toDateString())) }, @js($vendorTermin))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            {{-- Referensi Purchase Order (opsional): pilih PO → isi vendor & baris otomatis. --}}
            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-blue-100 bg-blue-50/50 p-3">
                <div class="min-w-[16rem] flex-1">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Referensi Purchase Order</label>
                    <x-search-cell name="'id_po'" model="idPo" options="poOpts" placeholder="— tanpa PO —" />
                </div>
                <button type="button" @click="muatDariPo()" x-show="idPo"
                        class="rounded-lg border border-brand px-3 py-2 text-sm font-medium text-brand hover:bg-brand-soft">Muat dari PO</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="nomor_invoice" label="Nomor Invoice (dari vendor)" :value="old('nomor_invoice')" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tanggal Invoice <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_invoice" x-model="tglInvoice" required
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Jatuh Tempo <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_jatuh_tempo" x-model="tglJatuhTempo" required
                           class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    <p class="mt-1 text-xs text-gray-400" x-show="terminInfo" x-text="terminInfo"></p>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Vendor <span class="text-red-500">*</span></label>
                    <x-search-cell name="'kode_vendor'" model="headerVendor" options="vendorOpts" placeholder="— pilih vendor —" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Unit Bisnis <span class="text-red-500">*</span></label>
                    <x-search-cell name="'kode_unit'" model="headerUnit" options="unitOpts" placeholder="— pilih unit —" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Akun Hutang Usaha <span class="text-red-500">*</span></label>
                    <x-search-cell name="'kode_coa_hutang'" model="headerHutang" options="hutangOpts" placeholder="— pilih akun hutang —" />
                </div>
            </div>
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />

            <div class="border-t border-gray-200 pt-3 text-sm font-medium text-gray-700">Rincian Item (dikredit ke Hutang)</div>

            <div class="space-y-3">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3">
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-4">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Akun (Beban/Aset)</label>
                                <x-search-cell name="`details[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" />
                            </div>
                            <div class="col-span-4 sm:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Qty</label>
                                <input type="number" step="0.01" min="0" :name="`details[${i}][kuantiti]`" x-model="row.kuantiti" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            </div>
                            <div class="col-span-4 sm:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Harga</label>
                                <input type="text" inputmode="numeric" :value="fmtRupiah(row.harga_satuan)" @input="row.harga_satuan = ketikRupiah($event)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand"><input type="hidden" :name="`details[${i}][harga_satuan]`" :value="row.harga_satuan">
                            </div>
                            <div class="col-span-3 sm:col-span-2 pb-2 text-right text-xs text-gray-600 tabular-nums" x-text="fmt((parseFloat(row.kuantiti)||0) * (parseFloat(row.harga_satuan)||0))"></div>
                            <div class="col-span-1">
                                <button type="button" @click="hapus(i)" x-show="rows.length > 1" class="w-full text-center text-red-500 hover:text-red-700" title="Hapus baris">&times;</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Keterangan</label>
                                <input type="text" :name="`details[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                            </div>
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Bagian <span class="font-normal text-gray-400">(wajib untuk akun Beban)</span></label>
                                <x-search-cell name="`details[${i}][kode_bagian]`" model="row.kode_bagian" options="bagianOpts" placeholder="— tanpa bagian —" />
                            </div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Perlakuan Aset <span class="font-normal text-gray-400">(kapitalisasi)</span></label>
                                <x-search-cell name="`details[${i}][aset_pilih]`" model="row.aset_pilih" options="asetOpts" placeholder="— bukan aset —" />
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 pt-2">
                <button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah baris</button>
                <div class="text-sm">Total Invoice: <b class="tabular-nums" x-text="fmt(total)"></b></div>
            </div>

            <p class="text-xs text-gray-400">Baris dengan akun <b>Beban</b> (kelompok 5) <b>wajib</b> mengisi kolom Bagian. Pilih <b>Perlakuan Aset</b> untuk mengkapitalisasi baris menjadi aset (draft baru atau menambah nilai aset yang ada).</p>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('invoices.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" :disabled="total <= 0"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Posting Invoice</button>
            </div>
        </form>
    </div>

    <script>
        function invoice(initRows, poData, opts, headerInit, vendorTermin) {
            return {
                rows: initRows.length ? initRows : [{}],
                poData: poData || [],
                idPo: '',
                coaOpts: opts.coa || [], bagianOpts: opts.bagian || [], vendorOpts: opts.vendor || [],
                unitOpts: opts.unit || [], hutangOpts: opts.hutang || [], poOpts: opts.po || [], asetOpts: opts.aset || [],
                headerVendor: headerInit.vendor || '', headerUnit: headerInit.unit || '', headerHutang: headerInit.hutang || '',
                tglInvoice: headerInit.tglInvoice || '', tglJatuhTempo: headerInit.tglJatuhTempo || '',
                vendorTermin: vendorTermin || {},
                terminInfo: '',
                init() {
                    // Jatuh tempo otomatis = tgl invoice + termin vendor (bila metode termin).
                    this.$watch('headerVendor', () => this.hitungJatuhTempo());
                    this.$watch('tglInvoice', () => this.hitungJatuhTempo());
                },
                hitungJatuhTempo() {
                    if (!this.tglInvoice || !this.headerVendor) { this.terminInfo = ''; return; }
                    const hari = Number(this.vendorTermin[this.headerVendor] ?? 0);
                    // Hitung dalam UTC agar tidak tergeser zona waktu (WIB +7).
                    const d = new Date(this.tglInvoice + 'T00:00:00Z');
                    d.setUTCDate(d.getUTCDate() + hari);
                    this.tglJatuhTempo = d.toISOString().slice(0, 10);
                    this.terminInfo = hari > 0 ? `Otomatis: termin ${hari} hari dari tanggal invoice.` : 'Vendor tunai (jatuh tempo = tanggal invoice).';
                },
                tambah() { this.rows.push({ kode_coa: '', keterangan: '', kode_bagian: '', kuantiti: '1', harga_satuan: '', aset_pilih: '' }); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                muatDariPo() {
                    const po = this.poData.find((p) => String(p.id_po) === String(this.idPo));
                    if (!po) return;
                    this.headerVendor = po.kode_vendor;
                    this.headerUnit = po.kode_unit;
                    this.rows = po.details.length ? po.details.map((d) => ({ ...d })) : [{ kode_coa: '', keterangan: '', kode_bagian: '', kuantiti: '1', harga_satuan: '', aset_pilih: '' }];
                },
                get total() { return this.rows.reduce((s, r) => s + (parseFloat(r.kuantiti) || 0) * (parseFloat(r.harga_satuan) || 0), 0); },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
