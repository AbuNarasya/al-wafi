@extends('layouts.app')

@section('title', 'Terima Barang')

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('penerimaan_barang.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @unless ($akunSiap)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Akun <b>Barang Diterima Belum Ditagih</b> belum ditetapkan di Pengaturan Perusahaan — penyimpanan akan ditolak.
            </div>
        @endunless

        <form method="POST" action="{{ route('penerimaan_barang.store') }}" x-data="terima(@js(array_values($initRows)), @js($itemOpts))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-blue-100 bg-blue-50/50 p-3">
                <div class="min-w-[18rem] flex-1">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Dari Purchase Order</label>
                    <select class="w-full rounded-lg border-gray-300 text-sm" data-alamat="{{ route('penerimaan_barang.create') }}"
                            onchange="window.location = this.dataset.alamat + (this.value ? '?po=' + this.value : '')">
                        @foreach ($poOptions as $id => $label)
                            <option value="{{ $id }}" @selected((string) $po?->id_po === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-gray-500">Memilih PO memuat barang yang belum datang. Ubah jumlahnya sesuai yang tiba; isi 0 untuk yang belum datang.</p>
            </div>
            @if ($po)<input type="hidden" name="id_po" value="{{ $po->id_po }}">@endif

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tanggal" label="Tanggal Terima" type="date" :value="old('tanggal', now()->toDateString())" required />
                @if ($po)
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Vendor</label>
                        <div class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm">{{ $po->vendor?->nama_vendor }}</div>
                        <input type="hidden" name="kode_vendor" value="{{ $po->kode_vendor }}">
                    </div>
                @else
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Vendor <span class="text-red-500">*</span></label>
                        <x-search-select name="kode_vendor" :options="$vendorOptions" :value="old('kode_vendor')" placeholder="— pilih vendor —" required />
                    </div>
                @endif
                <x-field name="kode_lokasi" label="Diterima di Lokasi" :value="old('kode_lokasi', \App\Models\LokasiPersediaan::BAWAAN)" :options="$lokasiOptions" required />
                <x-field name="nomor_surat_jalan" label="No. Surat Jalan / Nota" :value="old('nomor_surat_jalan')" />
                <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="$unitOptions" />
                <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-2 py-2">Barang</th><th class="px-2 py-2">Satuan</th><th class="px-2 py-2 text-right">Jumlah Tiba</th><th class="px-2 py-2 text-right">Harga / Satuan</th><th class="px-2 py-2 text-right">Subtotal</th><th></th></tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="i">
                            <tr class="border-t border-gray-100 align-top">
                                <td class="w-[34%] px-2 py-1.5">
                                    <input type="hidden" :name="`details[${i}][id_po_detail]`" :value="row.id_po_detail || ''">
                                    <template x-if="row.id_po_detail">
                                        <div>
                                            <div class="py-1.5 font-medium text-gray-800" x-text="label(row.kode_persediaan)"></div>
                                            <input type="hidden" :name="`details[${i}][kode_persediaan]`" :value="row.kode_persediaan">
                                        </div>
                                    </template>
                                    <template x-if="!row.id_po_detail">
                                        <x-search-cell name="`details[${i}][kode_persediaan]`" model="row.kode_persediaan" options="items" placeholder="— pilih barang —" />
                                    </template>
                                    <div class="text-[11px] text-gray-500" x-show="row.sisa">Sisa PO: <span x-text="row.sisa"></span> <span x-text="dasar(row)"></span></div>
                                </td>
                                <td class="px-2 py-1.5">
                                    <select :name="`details[${i}][satuan]`" x-model="row.satuan" @change="gantiSatuan(row)" class="w-full rounded border-gray-300 text-sm">
                                        <template x-for="s in satuanDari(row)" :key="s.s">
                                            <option :value="nilaiSatuan(row, s)" x-text="teksSatuan(row, s)" :selected="(row.satuan || '') === nilaiSatuan(row, s)"></option>
                                        </template>
                                    </select>
                                    <div class="mt-0.5 text-[11px] text-gray-500" x-show="isi(row) > 1" x-text="'= ' + ((parseFloat(row.kuantiti) || 0) * isi(row)) + ' ' + dasar(row)"></div>
                                </td>
                                <td class="w-28 px-2 py-1.5"><input type="number" step="0.0001" min="0" :name="`details[${i}][kuantiti]`" x-model="row.kuantiti" class="w-full rounded border-gray-300 text-right text-sm"></td>
                                <td class="w-36 px-2 py-1.5"><input type="text" inputmode="numeric" :value="fmtRupiah(row.harga_satuan)" @input="row.harga_satuan = ketikRupiah($event)" class="w-full rounded border-gray-300 text-right text-sm tabular-nums"><input type="hidden" :name="`details[${i}][harga_satuan]`" :value="row.harga_satuan"></td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-gray-600" x-text="fmt((parseFloat(row.kuantiti) || 0) * (parseFloat(row.harga_satuan) || 0))"></td>
                                <td class="px-1 py-1.5 text-center"><button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1" class="text-red-500 hover:text-red-700">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="border-t border-gray-200 font-medium">
                            <td class="px-2 py-2" colspan="4"><button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah barang di luar PO</button></td>
                            <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(total)"></td><td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="text-xs text-gray-500">
                Disimpan = stok bertambah di lokasi yang dipilih, dijurnal <b>D Persediaan / K Barang Diterima Belum Ditagih</b>.
                Hutang ke vendor baru tercatat saat invoice-nya diinput lewat <b>Invoice Vendor → Muat dari Penerimaan</b>.
            </p>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('penerimaan_barang.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Penerimaan</button>
            </div>
        </form>
    </div>

    <script>
        function terima(initRows, items) {
            return {
                rows: initRows.map(r => ({ ...r, harga_dasar: r.harga_satuan, _kode: r.kode_persediaan })),
                items,
                item(kode) { return this.items.find(o => o.v === kode) || null; },
                label(kode) { return (this.item(kode) || {}).l || kode; },
                dasar(row) { const it = this.item(row.kode_persediaan); return it ? it.satuan[0].s : ''; },
                satuanDari(row) { const it = this.item(row.kode_persediaan); return it ? it.satuan : []; },
                // Satuan dasar dikirim kosong — satu bentuk saja untuk "satuan dasar".
                nilaiSatuan(row, s) { return s.s === this.dasar(row) ? '' : s.s; },
                teksSatuan(row, s) { return s.s === this.dasar(row) ? s.s : s.s + ' (' + s.isi + ' ' + this.dasar(row) + ')'; },
                isi(row) {
                    const s = this.satuanDari(row).find(x => x.s === row.satuan);
                    return s ? parseFloat(s.isi) : 1;
                },
                // Ganti satuan → harga ikut dikalikan isinya (harga PO selalu per satuan dasar).
                gantiSatuan(row) {
                    if (row.harga_dasar === undefined || row.harga_dasar === '') {
                        const it = this.item(row.kode_persediaan);
                        row.harga_dasar = it ? it.harga : '';
                    }
                    if (row.harga_dasar !== '') row.harga_satuan = String(Math.round(parseFloat(row.harga_dasar) * this.isi(row)));
                },
                tambah() { this.rows.push({ id_po_detail: '', kode_persediaan: '', satuan: '', kuantiti: '1', sisa: '', harga_satuan: '', harga_dasar: '', keterangan: '', _kode: '' }); },
                get total() { return this.rows.reduce((s, r) => s + (parseFloat(r.kuantiti) || 0) * (parseFloat(r.harga_satuan) || 0), 0); },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
                init() {
                    // Barang baru dipilih → harga awal = harga perolehan rata-ratanya (boleh ditimpa).
                    this.$watch('rows', () => this.rows.forEach(r => {
                        if (!r.id_po_detail && r.kode_persediaan && r._kode !== r.kode_persediaan) {
                            r._kode = r.kode_persediaan; r.satuan = ''; r.harga_dasar = ''; this.gantiSatuan(r);
                        }
                    }));
                },
            };
        }
    </script>
@endsection
