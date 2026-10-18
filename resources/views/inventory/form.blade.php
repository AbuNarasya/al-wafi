@extends('layouts.app')

@section('title', $item->exists ? 'Ubah Persediaan' : 'Tambah Persediaan')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('inventory.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $item->exists ? route('inventory.update', $item->kode_persediaan) : route('inventory.store') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($item->exists) @method('PUT') @endif

            @if ($item->exists)
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Kode Persediaan</label>
                    <div class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $item->kode_persediaan }}</div>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama_persediaan" label="Nama Persediaan" :value="old('nama_persediaan', $item->nama_persediaan)" required />
                <x-field name="satuan" label="Satuan Dasar" :value="old('satuan', $item->satuan)" required placeholder="pcs / kg / botol"
                         hint="Satuan terkecil — stok selalu dicatat dalam satuan ini." />
                <x-field name="kode_kategori" label="Kategori" :value="old('kode_kategori', $item->kode_kategori)" :options="$kategoriOptions" />
                <x-field name="kode_merk" label="Merk" :value="old('kode_merk', $item->kode_merk)" :options="$merkOptions" />
            </div>

            <x-field name="harga_perolehan" label="Harga Perolehan Awal" type="number" :value="old('harga_perolehan', $item->harga_perolehan)" required
                     hint="Dipakai sebagai harga acuan saat opname menemukan stok lebih. Harga sesungguhnya mengikuti lapisan FIFO tiap pembelian." />

            @php
                $satuanAwal = old('satuan_lain', $item->exists
                    ? $item->satuanLain->map(fn ($s) => ['satuan' => $s->satuan, 'isi' => rtrim(rtrim((string) $s->isi, '0'), '.'), 'harga_jual' => $s->harga_jual === null ? '' : (string) (int) $s->harga_jual])->all()
                    : []);
                $barcodeAwal = old('barcode', $item->exists
                    ? $item->barcode->map(fn ($b) => ['barcode' => $b->barcode, 'satuan' => $b->satuan ?? ''])->all()
                    : []);
            @endphp
            <div x-data="{
                    toko: @js((bool) old('dijual_di_toko', $item->dijual_di_toko)),
                    satuanLain: @js(array_values($satuanAwal)),
                    barcode: @js(array_values($barcodeAwal)),
                    dasar: @js(old('satuan', $item->satuan) ?? ''),
                }" x-init="document.querySelector('[name=satuan]')?.addEventListener('input', e => dasar = e.target.value)"
                 class="space-y-4 rounded-lg border border-gray-200 p-4">
                <div>
                    <div class="text-sm font-semibold text-gray-800">Data Toko</div>
                    <p class="text-xs text-gray-500">Untuk barang yang dijual di koperasi / Wafi Mart. Barang gudang biasa boleh dilewati.</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="dijual_di_toko" value="0">
                    <input type="checkbox" name="dijual_di_toko" value="1" x-model="toko" class="rounded border-gray-300">
                    Dijual di toko
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Harga Jual <span class="text-gray-400" x-text="dasar ? '/ ' + dasar : ''"></span> <span x-show="toko" class="text-red-500">*</span></label>
                        <x-input-rupiah name="harga_jual" :value="old('harga_jual', $item->harga_jual)" placeholder="mis. 3500" />
                        @error('harga_jual')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-field name="stok_minimum" label="Stok Minimum" type="number" :value="old('stok_minimum', $item->stok_minimum === null ? null : rtrim(rtrim((string) $item->stok_minimum, '0'), '.'))"
                             hint="Di bawah angka ini barang ditandai perlu dibeli lagi." />
                </div>

                {{-- Satuan konversi --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700">Satuan Konversi</label>
                        <button type="button" @click="satuanLain.push({ satuan: '', isi: '', harga_jual: '' })" class="text-xs font-medium text-brand hover:underline">+ Tambah satuan</button>
                    </div>
                    <p class="mb-2 text-xs text-gray-500">Mis. 1 DUS = 24 <span x-text="dasar || 'pcs'"></span>. Harga jual kosong = isi × harga jual satuan dasar.</p>
                    <template x-for="(s, i) in satuanLain" :key="i">
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-gray-500">1</span>
                            <input type="text" :name="`satuan_lain[${i}][satuan]`" x-model="s.satuan" placeholder="DUS" class="w-24 rounded border-gray-300 text-sm">
                            <span class="text-gray-500">=</span>
                            <input type="number" step="0.0001" min="0" :name="`satuan_lain[${i}][isi]`" x-model="s.isi" placeholder="24" class="w-20 rounded border-gray-300 text-sm">
                            <span class="text-gray-500" x-text="dasar || 'satuan dasar'"></span>
                            <span class="ml-2 text-gray-500">harga</span>
                            <input type="text" inputmode="numeric" :value="fmtRupiah(s.harga_jual)" @input="s.harga_jual = ketikRupiah($event)" placeholder="otomatis" class="w-28 rounded border-gray-300 text-sm tabular-nums">
                            <input type="hidden" :name="`satuan_lain[${i}][harga_jual]`" :value="s.harga_jual">
                            <button type="button" @click="satuanLain.splice(i, 1)" class="text-red-600 hover:underline">Hapus</button>
                        </div>
                    </template>
                    @error('satuan_lain')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @foreach ($errors->get('satuan_lain.*') as $pesan)<p class="text-xs text-red-600">{{ $pesan[0] }}</p>@endforeach
                </div>

                {{-- Barcode --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700">Barcode</label>
                        <button type="button" @click="barcode.push({ barcode: '', satuan: '' })" class="text-xs font-medium text-brand hover:underline">+ Tambah barcode</button>
                    </div>
                    <p class="mb-2 text-xs text-gray-500">Satu barang boleh punya beberapa barcode (mis. kemasan lama &amp; baru, atau barcode dus). Pindai langsung ke kolomnya.</p>
                    <template x-for="(b, i) in barcode" :key="i">
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                            <input type="text" :name="`barcode[${i}][barcode]`" x-model="b.barcode" placeholder="8991234567890"
                                   @keydown.enter.prevent class="w-48 rounded border-gray-300 font-mono text-sm">
                            <select :name="`barcode[${i}][satuan]`" x-model="b.satuan" class="rounded border-gray-300 text-sm">
                                <option value="" x-text="dasar || 'satuan dasar'"></option>
                                <template x-for="s in satuanLain.filter(s => s.satuan)" :key="s.satuan">
                                    <option :value="s.satuan" x-text="s.satuan" :selected="b.satuan === s.satuan"></option>
                                </template>
                            </select>
                            <button type="button" @click="barcode.splice(i, 1)" class="text-red-600 hover:underline">Hapus</button>
                        </div>
                    </template>
                    @error('barcode')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @foreach ($errors->get('barcode.*') as $pesan)<p class="text-xs text-red-600">{{ $pesan[0] }}</p>@endforeach
                </div>
            </div>

            <div class="rounded-lg border border-brand/30 bg-brand-soft/40 p-4">
                <div class="mb-1 text-sm font-semibold text-gray-800">Akun Akuntansi</div>
                <p class="mb-3 text-xs text-gray-600">
                    Diisi <b>bagian keuangan</b>, sekali saja. Petugas gudang kelak hanya mencatat pemakaian &amp; opname
                    berikut bagian yang memakainya — akun lawannya diambil dari sini, jadi mereka tak perlu memilih akun sama sekali.
                </p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field name="kode_coa" label="Akun Persediaan" :value="old('kode_coa', $item->kode_coa)" :options="$coaOptions" required
                             hint="Aset — nilai barang di gudang." />
                    <x-field name="kode_coa_beban" label="Akun Beban Pemakaian" :value="old('kode_coa_beban', $item->kode_coa_beban)" :options="$coaOptions" required
                             hint="Didebet saat barang dipakai atau terjual." />
                    <x-field name="kode_coa_selisih" label="Akun Selisih Persediaan" :value="old('kode_coa_selisih', $item->kode_coa_selisih)" :options="$coaOptions" required
                             hint="Dipakai saat opname menemukan selisih." />
                </div>
            </div>

            @unless ($item->exists)
                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Stok awal tidak diisi di sini. Setelah barang tersimpan, catat stok pembukaannya lewat
                    <b>Opname</b> di daftar persediaan — supaya ia punya baris kartu stok dan jurnalnya sendiri,
                    sama seperti pergerakan lainnya.
                </div>
            @endunless

            <x-field name="status" label="Status" :value="old('status', $item->status ?? 'aktif')" :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" required />

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('inventory.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
