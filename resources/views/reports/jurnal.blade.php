@extends('layouts.app')

@section('title', 'Jurnal Mentah')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'jurnal'])
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-3 py-2.5">Tanggal</th><th class="px-3 py-2.5">Referensi</th><th class="px-3 py-2.5">Akun</th><th class="px-3 py-2.5">Jenis</th><th class="px-3 py-2.5">Keterangan</th><th class="px-3 py-2.5">Unit</th><th class="px-3 py-2.5 text-right">Debet</th><th class="px-3 py-2.5 text-right">Kredit</th><th class="px-3 py-2.5">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r['tanggal'])->format('d/m/Y') }}</td>
                        <td class="px-3 py-1.5">{{ $r['referensi'] }}</td>
                        <td class="px-3 py-1.5">{{ $r['kode_coa'] }} — {{ $r['nama_coa'] }}</td>
                        <td class="px-3 py-1.5">{{ $r['jenis_transaksi'] }}</td>
                        <td class="px-3 py-1.5 text-gray-600">{{ $r['keterangan'] }}</td>
                        <td class="px-3 py-1.5 text-gray-500">{{ $r['unit_bisnis'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">@rp($r['debet'])</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">@rp($r['kredit'])</td>
                        <td class="px-3 py-1.5">{{ ucfirst($r['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Tidak ada jurnal pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
