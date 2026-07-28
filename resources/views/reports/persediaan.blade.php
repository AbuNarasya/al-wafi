@extends('layouts.app')

@section('title', 'Laporan Persediaan')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('reports.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        @include('reports._download', ['type' => 'persediaan'])
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Satuan</th><th class="px-4 py-3 text-right">Masuk</th><th class="px-4 py-3 text-right">Keluar</th><th class="px-4 py-3 text-right">Stok</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Nilai</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($data['rows'] as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium text-gray-900">{{ $r['kode_persediaan'] }}</td>
                        <td class="px-4 py-2">{{ $r['nama_persediaan'] }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $r['satuan'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r['stok_masuk'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r['stok_keluar'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">{{ $r['stok'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['harga_perolehan'])</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['nilai_total'])</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada persediaan.</td></tr>
                @endforelse
                <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                    <td class="px-4 py-2.5" colspan="7">Total Nilai Persediaan</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">@rp($data['total_nilai'])</td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection
