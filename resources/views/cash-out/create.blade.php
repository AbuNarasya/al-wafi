@extends('layouts.app')

@section('title', 'Payment Voucher (PV) — Kas Keluar Baru')

@php
    $baris = ['tipe' => 'lainnya', 'kode_coa' => '', 'id_invoice' => '', 'id_pengajuan' => '', 'kode_persediaan' => '', 'kuantiti' => '', 'harga_satuan' => '', 'nominal' => '', 'keterangan' => '', 'kode_bagian' => '', 'aset_pilih' => ''];
    $initRows = array_values(old('details', [$baris]));
    $vendorList = collect($vendorOptions)->map(fn ($l, $v) => ['v' => (string) $v, 'l' => (string) $l])->values()->all();
    $opts = [
        'coa' => $coaOptions, 'bagian' => $bagianOptions, 'vendor' => $vendorList,
        'invoice' => $invoiceData, 'inventory' => $inventoryOptions,
        'pengajuan' => $pengajuanData, 'loan' => $loanData, 'aset' => $asetOptions,
    ];
@endphp

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('cash_out.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ route('cash_out.store') }}"
              x-data="kasKeluar(@js($initRows), @js($opts), { vendor: @js(old('kode_vendor', '')) })"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">No. PV (otomatis)</label>
                    <div class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $nomorPreview }}</div>
                </div>
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Unit Bisnis <span x-show="perluUnitHeader" class="text-red-500">*</span></label>
                    <x-search-select name="kode_unit" :options="$unitOptions" :value="old('kode_unit')" placeholder="— pilih unit —" />
                    <p class="mt-1 text-xs text-gray-400" x-show="!perluUnitHeader" x-cloak>Tak wajib: semua baris pelunasan pengajuan membawa unitnya sendiri.</p>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kas/Rekening Sumber <span class="text-red-500">*</span></label>
                    <x-search-select name="kode_rekening" :options="$rekeningOptions" :value="old('kode_rekening')" placeholder="— pilih rekening —" required />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Vendor (opsional)</label>
                    <x-search-cell name="'kode_vendor'" model="kodeVendor" options="vendorOpts" placeholder="— tanpa vendor —" />
                </div>
                <x-field name="referensi" label="Referensi Eksternal" :value="old('referensi')" />
            </div>
            <x-field name="keterangan" label="Keterangan Voucher" :value="old('keterangan')" required />

            {{-- Angsuran Pembiayaan (opsional): pilih pinjaman → prefill baris pokok + margin --}}
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Angsuran Pembiayaan (opsional)</label>
                <select name="id_bank_loan" x-model="idBankLoan" @change="muatAngsuran()"
                        class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    @foreach ($loanOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                </select>
            </div>

            {{-- Rincian baris (multi-tipe) --}}
            <div class="text-sm font-medium text-gray-700">Rincian Baris</div>
            <div class="space-y-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="rounded-lg border border-gray-200 bg-gray-50/40 p-3">
                        <div class="grid grid-cols-12 items-start gap-2">
                            {{-- Jenis --}}
                            <div class="col-span-12 sm:col-span-3">
                                <label class="mb-1 block text-[11px] font-medium text-gray-500">Jenis</label>
                                <select :name="`details[${i}][tipe]`" x-model="row.tipe" @change="gantiTipe(i)"
                                        class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                                    <option value="lainnya">Beban / Akun Lainnya</option>
                                    <option value="invoice">Pembayaran Invoice</option>
                                    <option value="pengajuan">Pelunasan Pengajuan Pembayaran</option>
                                    <option value="uang_muka">Pembayaran Pengajuan Uang Muka</option>
                                    <option value="inventory">Pembelian Persediaan</option>
                                </select>
                            </div>

                            {{-- ===== LAINNYA ===== --}}
                            <template x-if="row.tipe === 'lainnya'">
                                <div class="col-span-12 grid grid-cols-12 gap-2 sm:col-span-9">
                                    <div class="col-span-12 sm:col-span-5"><label class="mb-1 block text-[11px] text-gray-500">Akun (Debit)</label>
                                        <x-search-cell name="`details[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" /></div>
                                    <div class="col-span-6 sm:col-span-3"><label class="mb-1 block text-[11px] text-gray-500">Bagian</label>
                                        <x-search-cell name="`details[${i}][kode_bagian]`" model="row.kode_bagian" options="bagianOpts" placeholder="— tanpa —" /></div>
                                    <div class="col-span-6 sm:col-span-4"><label class="mb-1 block text-[11px] text-gray-500">Nominal</label>
                                        <input type="text" inputmode="numeric" :value="fmtRupiah(row.nominal)" @input="row.nominal = ketikRupiah($event)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums"><input type="hidden" :name="`details[${i}][nominal]`" :value="row.nominal"></div>
                                    <div class="col-span-12"><label class="mb-1 block text-[11px] text-gray-500">Perlakuan Aset <span class="text-gray-400">(kapitalisasi)</span></label>
                                        <x-search-cell name="`details[${i}][aset_pilih]`" model="row.aset_pilih" options="asetOpts" placeholder="— bukan aset —" /></div>
                                </div>
                            </template>

                            {{-- ===== INVOICE ===== --}}
                            <template x-if="row.tipe === 'invoice'">
                                <div class="col-span-12 grid grid-cols-12 gap-2 sm:col-span-9">
                                    <div class="col-span-12 sm:col-span-7"><label class="mb-1 block text-[11px] text-gray-500">Invoice</label>
                                        <select :name="`details[${i}][id_invoice]`" x-model="row.id_invoice" @change="pilihInvoice(i)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm">
                                            <option value="">— pilih invoice —</option>
                                            <template x-for="inv in invoiceFor()" :key="inv.id"><option :value="inv.id" x-text="inv.nomor + ' — ' + inv.vendor + ' · sisa ' + fmt(inv.sisa)"></option></template>
                                        </select>
                                    </div>
                                    <div class="col-span-12 sm:col-span-5"><label class="mb-1 block text-[11px] text-gray-500">Nominal Bayar</label>
                                        <input type="text" inputmode="numeric" :value="fmtRupiah(row.nominal)" @input="row.nominal = ketikRupiah($event)" placeholder="≤ sisa hutang" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums"><input type="hidden" :name="`details[${i}][nominal]`" :value="row.nominal"></div>
                                </div>
                            </template>

                            {{-- ===== PENGAJUAN PEMBAYARAN ===== --}}
                            <template x-if="row.tipe === 'pengajuan'">
                                <div class="col-span-12 grid grid-cols-12 gap-2 sm:col-span-9">
                                    <div class="col-span-12 sm:col-span-8"><label class="mb-1 block text-[11px] text-gray-500">Pengajuan Pembayaran</label>
                                        <select :name="`details[${i}][id_pengajuan]`" x-model="row.id_pengajuan" @change="pilihPengajuan(i)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm">
                                            <option value="">— pilih pengajuan —</option>
                                            <template x-for="p in pengajuanBayar()" :key="p.id"><option :value="p.id" x-text="p.nomor + ' · sisa ' + fmt(p.sisa)"></option></template>
                                        </select>
                                    </div>
                                    <div class="col-span-12 sm:col-span-4"><label class="mb-1 block text-[11px] text-gray-500">Nominal (sisa)</label>
                                        <input type="number" :name="`details[${i}][nominal]`" x-model="row.nominal" readonly class="w-full rounded border border-gray-400 bg-gray-100 px-2 py-1.5 text-right text-sm text-gray-600"></div>
                                </div>
                            </template>

                            {{-- ===== UANG MUKA ===== --}}
                            <template x-if="row.tipe === 'uang_muka'">
                                <div class="col-span-12 grid grid-cols-12 gap-2 sm:col-span-9">
                                    <div class="col-span-12 sm:col-span-8"><label class="mb-1 block text-[11px] text-gray-500">Pengajuan Uang Muka</label>
                                        <select :name="`details[${i}][id_pengajuan]`" x-model="row.id_pengajuan" @change="pilihUangMuka(i)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm">
                                            <option value="">— pilih uang muka —</option>
                                            <template x-for="p in pengajuanUM()" :key="p.id"><option :value="p.id" x-text="p.nomor + ' · ' + fmt(p.nominal)"></option></template>
                                        </select>
                                    </div>
                                    <div class="col-span-12 sm:col-span-4"><label class="mb-1 block text-[11px] text-gray-500">Nominal (penuh)</label>
                                        <input type="number" :name="`details[${i}][nominal]`" x-model="row.nominal" readonly class="w-full rounded border border-gray-400 bg-gray-100 px-2 py-1.5 text-right text-sm text-gray-600"></div>
                                </div>
                            </template>

                            {{-- ===== PERSEDIAAN ===== --}}
                            <template x-if="row.tipe === 'inventory'">
                                <div class="col-span-12 grid grid-cols-12 gap-2 sm:col-span-9">
                                    <div class="col-span-12 sm:col-span-5"><label class="mb-1 block text-[11px] text-gray-500">Item Persediaan</label>
                                        <x-search-cell name="`details[${i}][kode_persediaan]`" model="row.kode_persediaan" options="inventoryOpts" placeholder="— pilih item —" /></div>
                                    <div class="col-span-4 sm:col-span-2"><label class="mb-1 block text-[11px] text-gray-500">Qty</label>
                                        <input type="number" step="0.0001" min="0" :name="`details[${i}][kuantiti]`" x-model="row.kuantiti" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm"></div>
                                    <div class="col-span-8 sm:col-span-3"><label class="mb-1 block text-[11px] text-gray-500">Harga Satuan</label>
                                        <input type="text" inputmode="numeric" :value="fmtRupiah(row.harga_satuan)" @input="row.harga_satuan = ketikRupiah($event)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums"><input type="hidden" :name="`details[${i}][harga_satuan]`" :value="row.harga_satuan"></div>
                                    <div class="col-span-12 sm:col-span-2 sm:pt-5 text-right text-sm font-medium tabular-nums" x-text="fmt(lineNominal(row))"></div>
                                </div>
                            </template>
                        </div>

                        {{-- Keterangan baris + hapus --}}
                        <div class="mt-2 flex items-center gap-2">
                            <input type="text" :name="`details[${i}][keterangan]`" x-model="row.keterangan" placeholder="Keterangan baris (opsional)" class="flex-1 rounded border border-gray-400 px-2 py-1.5 text-sm">
                            <div class="text-right text-sm tabular-nums text-gray-600" x-show="row.tipe !== 'inventory'">= <span x-text="fmt(lineNominal(row))"></span></div>
                            <button type="button" @click="hapus(i)" x-show="rows.length > 1" class="shrink-0 rounded px-2 py-1 text-red-500 hover:bg-red-50">&times;</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                <button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah baris</button>
                <div class="text-sm">Total Voucher: <b class="tabular-nums" x-text="fmt(total)"></b></div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('cash_out.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" :disabled="total <= 0"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Posting Kas Keluar</button>
            </div>
        </form>
    </div>

    <script>
        function kasKeluar(initRows, opts, headerInit) {
            const kosong = () => ({ tipe: 'lainnya', kode_coa: '', id_invoice: '', id_pengajuan: '', kode_persediaan: '', kuantiti: '', harga_satuan: '', nominal: '', keterangan: '', kode_bagian: '', aset_pilih: '' });
            return {
                rows: initRows.length ? initRows : [kosong()],
                coaOpts: opts.coa || [], bagianOpts: opts.bagian || [], inventoryOpts: opts.inventory || [], vendorOpts: opts.vendor || [], asetOpts: opts.aset || [],
                invoiceData: opts.invoice || [], pengajuanData: opts.pengajuan || [], loanData: opts.loan || [],
                kodeVendor: headerInit.vendor || '', idBankLoan: '',
                tambah() { this.rows.push(kosong()); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                gantiTipe(i) { const r = this.rows[i]; Object.assign(r, { kode_coa: '', id_invoice: '', id_pengajuan: '', kode_persediaan: '', kuantiti: '', harga_satuan: '', nominal: '', aset_pilih: '' }); },
                invoiceFor() { return this.kodeVendor ? this.invoiceData.filter((x) => x.vendor === this.kodeVendor) : this.invoiceData; },
                pengajuanBayar() { return this.pengajuanData.filter((p) => p.jenis !== 'uang_muka'); },
                pengajuanUM() { return this.pengajuanData.filter((p) => p.jenis === 'uang_muka'); },
                pilihInvoice(i) { const inv = this.invoiceData.find((x) => String(x.id) === String(this.rows[i].id_invoice)); if (inv && !this.rows[i].nominal) this.rows[i].nominal = inv.sisa; },
                pilihPengajuan(i) { const p = this.pengajuanData.find((x) => String(x.id) === String(this.rows[i].id_pengajuan)); this.rows[i].nominal = p ? p.sisa : ''; },
                pilihUangMuka(i) { const p = this.pengajuanData.find((x) => String(x.id) === String(this.rows[i].id_pengajuan)); this.rows[i].nominal = p ? p.nominal : ''; },
                muatAngsuran() {
                    const loan = this.loanData.find((l) => String(l.id) === String(this.idBankLoan));
                    if (!loan) return;
                    const baris = [{ ...kosong(), tipe: 'lainnya', kode_coa: loan.kode_coa_hutang, keterangan: 'Pembayaran pokok' }];
                    if (loan.kode_coa_beban) baris.push({ ...kosong(), tipe: 'lainnya', kode_coa: loan.kode_coa_beban, keterangan: 'Margin' });
                    this.rows = baris;
                },
                lineNominal(r) { return r.tipe === 'inventory' ? (parseFloat(r.kuantiti) || 0) * (parseFloat(r.harga_satuan) || 0) : (parseFloat(r.nominal) || 0); },
                get total() { return this.rows.reduce((s, r) => s + this.lineNominal(r), 0); },
                get perluUnitHeader() { return this.rows.some((r) => r.tipe !== 'pengajuan' && r.tipe !== 'uang_muka'); },
                fmt(n) { return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
