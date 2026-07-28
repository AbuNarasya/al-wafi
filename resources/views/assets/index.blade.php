@extends('layouts.app')

@section('title', 'Aset Tetap')

@section('content')
    <div x-data="{ depr: false }">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <form method="GET" id="filterAset"></form>
            <x-filter-server placeholder="Cari kode / nama…" :total="$rows->count()"
                             :reset="route('assets.index')" :aktif="$q !== ''" form="filterAset" />
            <div class="flex items-center gap-2">
                @if (\App\Support\Akses::boleh('assets', 'ubah'))
                    <button type="button" @click="depr = !depr" class="rounded-lg border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50">Jalankan Depresiasi</button>
                @endif
                @if (\App\Support\Akses::boleh('assets', 'buat'))
                    <a href="{{ route('assets.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Aset</a>
                @endif
            </div>
        </div>

        {{-- Form jalankan depresiasi bulanan --}}
        <div x-show="depr" x-cloak class="mb-4 rounded-xl border border-amber-200 bg-amber-50/60 p-4">
            <h3 class="mb-2 text-sm font-semibold text-gray-800">Jalankan Depresiasi Bulanan</h3>
            <form method="POST" action="{{ route('assets.run_depreciation') }}"
                  onsubmit="return confirm('Posting depresiasi bulanan untuk semua aset aktif?')"
                  class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-field name="kode_coa_beban" label="Akun Beban Depresiasi" :options="$coaOptions" required />
                <x-field name="kode_coa_akumulasi" label="Akun Akumulasi Depresiasi" :options="$coaOptions" required />
                <x-field name="kode_unit" label="Unit Bisnis" :options="$unitOptions" />
                <div class="flex items-end">
                    <button class="w-full rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Posting Depresiasi</button>
                </div>
            </form>
            <p class="mt-2 text-xs text-gray-500">Menjurnal 1 bulan depresiasi (Debit beban, Kredit akumulasi) untuk semua aset aktif, dan menambah akumulasi tiap aset.</p>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3 text-right">Harga Perolehan</th><th class="px-4 py-3 text-right">Akumulasi</th><th class="px-4 py-3 text-right">Nilai Buku</th><th class="px-4 py-3">Metode</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        @php $buku = (float) $r->harga_perolehan - (float) $r->akumulasi_depresiasi; @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono font-medium text-gray-900">{{ $r->kode_aset }}</td>
                            <td class="px-4 py-3">{{ $r->nama_aset }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $r->kategori_aset }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">@rp($r->harga_perolehan)</td>
                            <td class="px-4 py-3 text-right tabular-nums text-gray-500">@rp($r->akumulasi_depresiasi)</td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($buku)</td>
                            <td class="px-4 py-3 text-gray-500">{{ $r->metode_depresiasi === 'garis_lurus' ? 'Garis Lurus' : 'Saldo Menurun' }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if (\App\Support\Akses::boleh('assets', 'ubah'))<a href="{{ route('assets.edit', $r->kode_aset) }}" class="text-brand hover:underline">Ubah</a>@endif
                                    @if (\App\Support\Akses::boleh('assets', 'hapus'))
                                        <form method="POST" action="{{ route('assets.destroy', $r->kode_aset) }}" onsubmit="return confirm('Hapus {{ $r->kode_aset }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Belum ada aset tetap.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
