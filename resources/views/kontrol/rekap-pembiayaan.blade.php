@extends('layouts.app')

@section('title', 'Rekap Pembiayaan per Bank')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">Ringkasan pembiayaan dikelompokkan per bank: pokok, terbayar, sisa pokok, dan margin dibayar.</p>
        <div class="flex items-center gap-3">
            @include('kontrol._download', ['type' => 'rekap-pembiayaan'])
            <a href="{{ route('kontrol.ringkasan') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Ringkasan</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Bank</th><th class="px-4 py-3 text-right">Jml Pembiayaan</th><th class="px-4 py-3 text-right">Pokok Awal</th><th class="px-4 py-3 text-right">Pokok Terbayar</th><th class="px-4 py-3 text-right">Sisa Pokok</th><th class="px-4 py-3 text-right">Margin Dibayar</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r['nama_bank'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $r['jumlah_pinjaman'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r['pokok_awal'])</td>
                        <td class="px-4 py-3 text-right tabular-nums text-emerald-700">@rp($r['pokok_terbayar'])</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r['sisa_pokok'])</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500">@rp($r['margin_dibayar'])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada pembiayaan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
