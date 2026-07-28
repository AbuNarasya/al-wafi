@extends('layouts.app')

@section('title', 'Accrue & Prepaid (Aktif)')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">Jurnal akrual/prabayar yang masih aktif (belum di-reversal).</p>
        <div class="flex items-center gap-3">
            @include('kontrol._download', ['type' => 'accrue-prepaid'])
            <a href="{{ route('kontrol.ringkasan') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Ringkasan</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Periode</th><th class="px-4 py-3">Debet → Kredit</th><th class="px-4 py-3 text-right">Nominal</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono">{{ $r->nomor_referensi }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->periode }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            <span class="text-gray-500">{{ $r->nama_coa_debet }}</span>
                            <span class="mx-1 text-gray-400">&rarr;</span>
                            <span>{{ $r->nama_coa_kredit }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r->nominal)</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada accrue/prepaid aktif.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
