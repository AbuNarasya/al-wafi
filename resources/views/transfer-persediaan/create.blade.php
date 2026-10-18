@extends('layouts.app')

@section('title', 'Kirim Barang Antar Lokasi')

@section('content')
    @php
        $kosong = ['kode_persediaan' => '', 'satuan' => '', 'kuantiti' => '1', 'keterangan' => ''];
        $asalAwal = old('kode_lokasi_asal', \App\Models\LokasiPersediaan::BAWAAN);
    @endphp
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('transfer_persediaan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if (count($lokasiOptions) < 2)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Baru ada satu lokasi. Tambahkan lokasi toko/gudang lain di
                <a href="{{ route('lokasi_persediaan.index') }}" class="font-medium underline">Lokasi Persediaan</a> sebelum memindahkan barang.
            </div>
        @endif

        <form method="POST" action="{{ route('transfer_persediaan.store') }}"
              x-data="kirim(@js(array_values(old('details', [$kosong]))), @js($itemOpts), @js((object) $stok), @js($asalAwal))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tanggal_kirim" label="Tanggal Kirim" type="date" :value="old('tanggal_kirim', now()->toDateString())" required />
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Dari Lokasi <span class="text-red-500">*</span></label>
                    <select name="kode_lokasi_asal" x-model="asal" class="w-full rounded-lg border-gray-300 text-sm">
                        @foreach ($lokasiOptions as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Ke Lokasi <span class="text-red-500">*</span></label>
                    <select name="kode_lokasi_tujuan" class="w-full rounded-lg border-gray-300 text-sm">
                        @foreach ($lokasiOptions as $k => $n)
                            <option value="{{ $k }}" @selected(old('kode_lokasi_tujuan') === $k)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />

            <div class="space-y-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-12 items-start gap-2 rounded-lg border border-gray-200 p-2 text-sm">
                        <div class="col-span-12 sm:col-span-6">
                            <x-search-cell name="`details[${i}][kode_persediaan]`" model="row.kode_persediaan" options="items" placeholder="— pilih barang —" />
                            <div class="mt-0.5 text-[11px]" :class="kurang(row) ? 'text-red-600' : 'text-gray-500'" x-show="row.kode_persediaan"
                                 x-text="'Stok di lokasi asal: ' + stokAsal(row) + ' ' + dasar(row)"></div>
                        </div>
                        <div class="col-span-4 sm:col-span-2">
                            <input type="number" step="0.0001" min="0" :name="`details[${i}][kuantiti]`" x-model="row.kuantiti" class="w-full rounded border-gray-300 text-right text-sm">
                        </div>
                        <div class="col-span-6 sm:col-span-3">
                            <select :name="`details[${i}][satuan]`" x-model="row.satuan" class="w-full rounded border-gray-300 text-sm">
                                <template x-for="s in satuanDari(row)" :key="s.s">
                                    <option :value="s.s" x-text="s.isi === '1' ? s.s : s.s + ' (' + s.isi + ' ' + dasar(row) + ')'" :selected="row.satuan === s.s"></option>
                                </template>
                            </select>
                        </div>
                        <div class="col-span-2 sm:col-span-1 text-center">
                            <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1" class="text-red-500 hover:text-red-700">&times;</button>
                        </div>
                    </div>
                </template>
                <button type="button" @click="rows.push({ kode_persediaan: '', satuan: '', kuantiti: '1', keterangan: '' })" class="text-sm text-brand hover:underline">+ Tambah barang</button>
            </div>

            <p class="text-xs text-gray-500">
                Setelah dikirim, barang tercatat <b>Dalam Perjalanan</b> — tidak lagi di lokasi asal, belum di tujuan —
                sampai petugas tujuan mencatat jumlah yang benar-benar tiba.
            </p>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('transfer_persediaan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Kirim</button>
            </div>
        </form>
    </div>

    <script>
        function kirim(initRows, items, stok, asal) {
            return {
                rows: initRows, items, stok, asal,
                item(kode) { return this.items.find(o => o.v === kode) || null; },
                dasar(row) { const it = this.item(row.kode_persediaan); return it ? it.satuan[0].s : ''; },
                satuanDari(row) { const it = this.item(row.kode_persediaan); return it ? it.satuan : []; },
                isi(row) { const s = this.satuanDari(row).find(x => x.s === row.satuan); return s ? parseFloat(s.isi) : 1; },
                stokAsal(row) { return parseFloat(((this.stok[row.kode_persediaan] || {})[this.asal]) || 0); },
                kurang(row) { return (parseFloat(row.kuantiti) || 0) * this.isi(row) > this.stokAsal(row); },
            };
        }
    </script>
@endsection
