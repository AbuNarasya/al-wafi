@extends('layouts.app')

@section('title', 'Lokasi Persediaan')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-2xl text-sm text-gray-500">
            Tempat stok disimpan — gudang, toko, atau transit. Stok & harga pokok (FIFO) dihitung per lokasi.
            Pembelian, penjualan, dan jurnal yang tidak menyebut lokasi masuk ke lokasi bawaan.
        </p>
        @if (\App\Support\Akses::boleh('lokasi-persediaan', 'buat'))
            <a href="{{ route('lokasi_persediaan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Lokasi</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Unit Bisnis</th><th class="px-4 py-3 text-right">Barang Berstok</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{ $r->nama }}
                            @if ($r->kode === \App\Models\LokasiPersediaan::BAWAAN)
                                <span class="ml-1 rounded bg-brand-soft px-1.5 py-0.5 text-[10px] font-medium text-brand">bawaan</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ \App\Models\LokasiPersediaan::JENIS[$r->jenis] ?? $r->jenis }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->unit?->nama_unit ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ (int) ($jumlahBarang[$r->kode] ?? 0) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('lokasi-persediaan', 'ubah'))<a href="{{ route('lokasi_persediaan.edit', $r->kode) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('lokasi-persediaan', 'hapus') && $r->kode !== \App\Models\LokasiPersediaan::BAWAAN)
                                    <form method="POST" action="{{ route('lokasi_persediaan.destroy', $r->kode) }}" onsubmit="return confirm('Hapus lokasi {{ $r->nama }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
