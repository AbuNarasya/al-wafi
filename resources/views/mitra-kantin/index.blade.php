@extends('layouts.app')

@section('title', 'Mitra Kantin')

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">Mitra Kantin</h2>
            <p class="mt-1 text-sm text-gray-600">Penyewa lahan di area Wafi Mart yang menerima kartu santri. Penjualan kartunya <b>bukan</b> pendapatan Wafi Mart — menjadi saldo mitra yang ditarik lewat pengajuan penarikan.</p>
        </div>
        <div class="flex gap-2">
            @php $menungguTarik = \App\Models\PenarikanMitra::where('status', 'diajukan')->count(); @endphp
            <a href="{{ route('mitra_kantin.penarikan') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                Penarikan Saldo
                @if ($menungguTarik > 0)
                    <span class="ml-1 rounded-full bg-red-600 px-1.5 text-xs font-bold text-white">{{ $menungguTarik }}</span>
                @endif
            </a>
            <a href="{{ route('mitra_kantin.laporan') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">Laporan</a>
            @if (\App\Support\Akses::boleh('mitra-kantin', 'buat'))
                <a href="{{ route('mitra_kantin.create') }}" class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">+ Mitra</a>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Mitra</th>
                    <th class="px-4 py-3">Masa sewa</th>
                    <th class="px-4 py-3 text-right">Menu aktif</th>
                    <th class="px-4 py-3 text-right">Hari ini</th>
                    <th class="px-4 py-3 text-right">Bulan ini</th>
                    <th class="px-4 py-3 text-right">Saldo mitra</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $m)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <a href="{{ route('mitra_kantin.show', $m) }}" class="font-medium text-brand hover:underline">{{ $m->nama }}</a>
                            <div class="text-xs text-gray-400">{{ $m->kode }}{{ $m->pemilik ? ' · '.$m->pemilik : '' }}</div>
                            @if (! $m->aktif())<span class="text-xs font-semibold text-red-600">Nonaktif</span>@endif
                        </td>
                        <td class="px-4 py-2 text-gray-600">
                            {{ $m->sewa_mulai?->format('d/m/Y') ?? '—' }} – {{ $m->sewa_sampai?->format('d/m/Y') ?? '—' }}
                            @if ($m->sewa_sampai && $m->sewa_sampai->isPast())<div class="text-xs font-semibold text-amber-700">Masa sewa lewat</div>@endif
                        </td>
                        <td class="px-4 py-2 text-right">{{ $m->menu_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($hariIni[$m->id]->total ?? 0)</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($bulanIni[$m->id]->total ?? 0)</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($saldo[$m->id]['saldo'] ?? 0)</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Belum ada mitra.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
