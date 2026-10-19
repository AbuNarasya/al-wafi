@extends('layouts.app')

@section('title', 'Rekap '.$sesi->nomor)

@section('content')
    @php $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3 print:hidden">
        <a href="{{ route('tarik_tunai.show', $sesi->id_sesi) }}" class="text-sm text-gray-500 hover:underline">← {{ $sesi->nama }}</a>
        <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">Cetak</button>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <h1 class="text-lg font-bold text-gray-900">Rekap Penarikan Tunai — {{ $sesi->nama }}</h1>
        <p class="text-sm text-gray-500">{{ $sesi->nomor }} · ambil {{ $sesi->tanggal_ambil->format('d/m/Y') }} dari {{ $sesi->bank?->nama_rekening }} · distribusi {{ $sesi->tanggal_distribusi->format('d/m/Y') }}</p>

        <div class="my-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Total ditarik</div><div class="text-xl font-bold tabular-nums">{{ $rp($total) }}</div><div class="text-xs text-gray-500">{{ $baris->count() }} santri</div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Pecahan Rp 100.000</div><div class="text-xl font-bold tabular-nums">{{ $lembar['100000'] }} lembar</div><div class="text-xs text-gray-500">{{ $rp($lembar['100000'] * 100000) }}</div></div>
            <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Pecahan Rp 50.000</div><div class="text-xl font-bold tabular-nums">{{ $lembar['50000'] }} lembar</div><div class="text-xs text-gray-500">{{ $rp($lembar['50000'] * 50000) }}</div></div>
        </div>

        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-3 py-2">#</th><th class="px-3 py-2">Santri</th><th class="px-3 py-2 text-right">Nominal</th><th class="px-3 py-2 text-right">100rb</th><th class="px-3 py-2 text-right">50rb</th><th class="px-3 py-2">Paraf penerima</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($baris as $i => $p)
                    @php $pc = $p->pecahan(); @endphp
                    <tr>
                        <td class="px-3 py-1.5 text-gray-500">{{ $i + 1 }}</td>
                        <td class="px-3 py-1.5">{{ $p->santri->nama }} <span class="text-xs text-gray-500">{{ $p->santri->nis }}</span></td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $rp($p->nominalBerlaku()) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $pc['100000'] ?: '' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $pc['50000'] ?: '' }}</td>
                        <td class="px-3 py-1.5 text-xs text-gray-500">{{ $p->status === 'diserahkan' ? 'Diserahkan '.$p->diserahkan_pada->format('d/m H:i') : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada pengajuan yang disetujui.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
