@extends('layouts.app')

@section('title', 'Perubahan Modal')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'perubahan-modal'])
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Akun Ekuitas</th><th class="px-4 py-3 text-right">Saldo Awal</th><th class="px-4 py-3 text-right">Mutasi</th><th class="px-4 py-3 text-right">Saldo Akhir</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($data['rows'] as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">{{ $r['kode_coa'] }} — {{ $r['nama_coa'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['saldo_awal'])</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['mutasi'])</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['saldo_akhir'])</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Tidak ada akun ekuitas.</td></tr>
                @endforelse
                <tr class="bg-gray-50 font-medium"><td class="px-4 py-2">Subtotal Ekuitas</td>
                    <td class="px-4 py-2 text-right tabular-nums">@rp($data['total_awal'])</td>
                    <td class="px-4 py-2 text-right tabular-nums">@rp($data['total_mutasi'])</td>
                    <td class="px-4 py-2 text-right tabular-nums">@rp($data['total_sebelum_laba'])</td></tr>
                <tr><td class="px-4 py-2 text-gray-600" colspan="3">Laba/Rugi Tahun Berjalan</td>
                    <td class="px-4 py-2 text-right tabular-nums">@rp($data['laba_berjalan'])</td></tr>
                <tr class="border-t-2 border-gray-200 bg-emerald-50 font-semibold text-emerald-900">
                    <td class="px-4 py-2.5" colspan="3">Total Ekuitas Akhir</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">@rp($data['total_ekuitas_akhir'])</td></tr>
            </tbody>
        </table>
    </div>
@endsection
