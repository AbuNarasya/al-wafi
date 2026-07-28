@extends('layouts.app')

@section('title', 'Uang Muka Operasional (Outstanding)')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">Uang muka operasional yang masih outstanding (belum diselesaikan penuh).</p>
        <div class="flex items-center gap-3">
            @include('kontrol._download', ['type' => 'uang-muka-operasional'])
            <a href="{{ route('kontrol.ringkasan') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Ringkasan</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Akun UM</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Sisa</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono">{{ $r->nomor_ref }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->penerima ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->nama_coa_uang_muka }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r->sisa)</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada uang muka operasional outstanding.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
