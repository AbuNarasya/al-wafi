@extends('layouts.app')

@section('title', 'Persediaan')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" id="filterPersediaan"></form>
        <x-filter-server placeholder="Cari kode / nama…" :total="$rows->count()"
                         :reset="route('inventory.index')" :aktif="$q !== ''" form="filterPersediaan" />
        @if (\App\Support\Akses::boleh('inventory', 'buat'))
            <a href="{{ route('inventory.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Persediaan</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Satuan</th><th class="px-4 py-3 text-right">Harga Rata-rata</th><th class="px-4 py-3 text-right">Stok</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php
                        $stok = (float) $r->stok_masuk - (float) $r->stok_keluar;
                        $siapJurnal = $r->kode_coa && $r->kode_coa_beban && $r->kode_coa_selisih;
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ $r->kode_persediaan }}</td>
                        <td class="px-4 py-3">
                            {{ $r->nama_persediaan }}
                            @unless ($siapJurnal)
                                {{-- Tanpa akun lengkap, pemakaian & opname akan ditolak. Dikatakan
                                     di daftar supaya ketahuan sebelum petugas gudang mencobanya. --}}
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">akun belum lengkap</span>
                            @endunless
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->satuan }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->harga_perolehan)</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $stok <= 0 ? 'text-red-600' : 'font-medium' }}">{{ rtrim(rtrim(number_format($stok, 4, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nilai_persediaan)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('inventory.kartu', $r->kode_persediaan) }}" class="text-gray-600 hover:underline">Kartu Stok</a>
                                @if (\App\Support\Akses::boleh('inventory', 'ubah'))
                                    <div x-data="{ open: false, jenis: 'pemakaian' }" class="relative inline-block text-left">
                                        <button @click="open = !open" class="text-indigo-600 hover:underline">Mutasi</button>
                                        <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('inventory.mutasi', $r->kode_persediaan) }}"
                                              class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                            @csrf
                                            <label class="block text-xs font-medium text-gray-600">Jenis</label>
                                            <select name="jenis" x-model="jenis" class="w-full rounded border-gray-300 text-sm">
                                                <option value="pemakaian">Pemakaian Barang</option>
                                                <option value="opname">Penyesuaian Opname</option>
                                            </select>

                                            <label class="block text-xs font-medium text-gray-600">Tanggal</label>
                                            <input type="date" name="tanggal" value="{{ now()->toDateString() }}" required class="w-full rounded border-gray-300 text-sm">

                                            <template x-if="jenis === 'pemakaian'">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-600">Jumlah dipakai ({{ $r->satuan }})</label>
                                                    <input type="number" step="0.0001" min="0" name="jumlah" class="w-full rounded border-gray-300 text-sm">
                                                </div>
                                            </template>
                                            <template x-if="jenis === 'opname'">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-600">Stok hasil hitung ({{ $r->satuan }})</label>
                                                    <input type="number" step="0.0001" min="0" name="stok_fisik" class="w-full rounded border-gray-300 text-sm">
                                                    <p class="mt-1 text-[11px] text-gray-500">Tercatat sekarang: {{ rtrim(rtrim(number_format($stok, 4, '.', ''), '0'), '.') }}. Selisihnya dihitung sistem.</p>
                                                </div>
                                            </template>

                                            {{-- Bagian dipilih DI SINI, bukan di master: barang yang sama
                                                 bisa dipakai bagian mana saja, dan memakunya di master
                                                 membebankan pemakaian ke bagian yang salah tiap kali
                                                 peminjamnya berbeda. --}}
                                            <label class="block text-xs font-medium text-gray-600">Bagian pemakai</label>
                                            <select name="kode_bagian" required class="w-full rounded border-gray-300 text-sm">
                                                @foreach ($bagianOptions as $kode => $nama)
                                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                                @endforeach
                                            </select>

                                            <input type="text" name="keterangan" placeholder="Keterangan (opsional)" class="w-full rounded border-gray-300 text-sm">
                                            <button class="w-full rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Simpan &amp; Jurnalkan</button>
                                        </form>
                                    </div>
                                @endif
                                @if (\App\Support\Akses::boleh('inventory', 'ubah'))<a href="{{ route('inventory.edit', $r->kode_persediaan) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('inventory', 'hapus'))
                                    <form method="POST" action="{{ route('inventory.destroy', $r->kode_persediaan) }}" onsubmit="return confirm('Hapus {{ $r->kode_persediaan }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada persediaan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
