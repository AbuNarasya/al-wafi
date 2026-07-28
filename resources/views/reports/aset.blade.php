@extends('layouts.app')

@section('title', 'Laporan Aset')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('reports.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        @include('reports._download', ['type' => 'aset'])
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Aset</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3 text-right">Perolehan</th><th class="px-4 py-3 text-right">Akm. Depresiasi</th><th class="px-4 py-3 text-right">Nilai Buku</th><th class="px-4 py-3 text-right">Depr./bln</th><th class="px-4 py-3">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($data['rows'] as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium text-gray-900">{{ $r['kode_aset'] }}</td>
                        <td class="px-4 py-2">{{ $r['nama_aset'] }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $r['kategori_aset'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['harga_perolehan'])</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['akumulasi_depresiasi'])</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">@rp($r['nilai_buku'])</td>
                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">@rp($r['depresiasi_bulanan'])</td>
                        <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r['status'] === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r['status']) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada aset.</td></tr>
                @endforelse
                <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                    <td class="px-4 py-2.5" colspan="3">Total</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">@rp($data['total_perolehan'])</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">@rp($data['total_akumulasi'])</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">@rp($data['total_nilai_buku'])</td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection
