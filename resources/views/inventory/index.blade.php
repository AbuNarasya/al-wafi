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
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Satuan</th><th class="px-4 py-3 text-right">Harga Perolehan</th><th class="px-4 py-3 text-right">Stok</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php $stok = (float) $r->stok_masuk - (float) $r->stok_keluar; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ $r->kode_persediaan }}</td>
                        <td class="px-4 py-3">{{ $r->nama_persediaan }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->satuan }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->harga_perolehan)</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $stok <= 0 ? 'text-red-600' : 'font-medium' }}">{{ rtrim(rtrim(number_format($stok, 4, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('inventory', 'ubah'))
                                    <div x-data="{ open: false }" class="relative inline-block text-left">
                                        <button @click="open = !open" class="text-indigo-600 hover:underline">Mutasi</button>
                                        <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('inventory.mutasi', $r->kode_persediaan) }}"
                                              class="absolute right-0 z-10 mt-2 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                            @csrf
                                            <label class="block text-xs font-medium text-gray-600">Arah</label>
                                            <select name="arah" class="w-full rounded border-gray-300 text-sm"><option value="masuk">Stok Masuk (+)</option><option value="keluar">Stok Keluar (−)</option></select>
                                            <label class="block text-xs font-medium text-gray-600">Jumlah</label>
                                            <input type="number" step="0.0001" min="0" name="jumlah" required class="w-full rounded border-gray-300 text-sm">
                                            <input type="text" name="keterangan" placeholder="Keterangan (opsional)" class="w-full rounded border-gray-300 text-sm">
                                            <button class="w-full rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Simpan Mutasi</button>
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
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Belum ada persediaan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
