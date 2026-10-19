@extends('layouts.app')

@section('title', 'Persetujuan Tarik Tunai Kegiatan')

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $boleh = \App\Support\Akses::boleh('penarikan-tunai', 'ubah');
    @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Pengajuan wali untuk membawa uang saku tunai ke kegiatan. Setujui sesuai pengajuan, atau isi nominal yang
        <b>lebih kecil</b> (kelipatan Rp 50.000). Pengajuan yang belum diputus saat batas persetujuan lewat akan
        <b>gugur</b> dan cadangan uang sakunya dilepas.
    </p>

    @forelse ($sesi as $s)
        @php $daftar = $menunggu->get($s->id_sesi, collect()); @endphp
        <div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 px-4 py-2.5">
                <div class="text-sm font-semibold text-gray-800">{{ $s->nama }} <span class="font-mono text-xs font-normal text-gray-500">{{ $s->nomor }}</span></div>
                <div class="text-xs text-gray-500">batas persetujuan <b>{{ $s->batas_persetujuan->format('d/m/Y H:i') }}</b> · maks {{ $rp($s->maks_per_santri) }}/santri · {{ $daftar->count() }} menunggu</div>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($daftar as $p)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                        <div class="min-w-[14rem]">
                            <div class="font-medium text-gray-900">{{ $p->santri->nama }} <span class="text-xs text-gray-500">{{ $p->santri->nis }}</span></div>
                            <div class="text-xs text-gray-500">{{ $p->nomor }} · diajukan {{ $p->namaPengaju() }} · {{ $p->updated_at->format('d/m/Y H:i') }}</div>
                        </div>
                        <div class="text-lg font-bold tabular-nums">{{ $rp($p->nominal) }}</div>
                        @if ($boleh)
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('persetujuan_tarik_tunai.setujui', $p->id) }}" class="flex items-center gap-1">
                                    @csrf
                                    <input type="number" name="nominal" step="50000" min="50000" max="{{ (int) $p->nominal }}" value="{{ (int) $p->nominal }}" class="w-32 rounded-lg border-gray-300 text-right text-sm" aria-label="Nominal disetujui">
                                    <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Setujui</button>
                                </form>
                                <form method="POST" action="{{ route('persetujuan_tarik_tunai.tolak', $p->id) }}" x-data="{ panel: false }" class="relative">
                                    @csrf
                                    <button type="button" @click="panel = !panel" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">Tolak</button>
                                    <div x-show="panel" x-cloak @click.outside="panel = false" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                                        <input type="text" name="alasan" required placeholder="Alasan (dikirim ke wali)" class="w-full rounded border-gray-300 text-sm">
                                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Tolak pengajuan</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="px-4 py-6 text-center text-sm text-gray-400">Tidak ada pengajuan yang menunggu.</div>
                @endforelse
            </div>
        </div>
    @empty
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-400">Tidak ada sesi yang sedang menunggu persetujuan.</div>
    @endforelse

    @if ($diputus->isNotEmpty())
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Baru diputus</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Kegiatan</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2 text-right">Diajukan</th><th class="px-4 py-2 text-right">Disetujui</th><th class="px-4 py-2">Putusan</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($diputus as $p)
                        <tr>
                            <td class="px-4 py-2 font-mono">{{ $p->nomor }}</td>
                            <td class="px-4 py-2">{{ $p->sesi->nama }}</td>
                            <td class="px-4 py-2">{{ $p->santri->nama }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $rp($p->nominal) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $p->nominal_disetujui !== null ? $rp($p->nominal_disetujui) : '—' }}</td>
                            <td class="px-4 py-2 text-xs">
                                <span class="{{ $p->status === 'disetujui' ? 'text-emerald-700' : 'text-red-700' }}">{{ $p->labelStatus() }}</span>
                                · {{ $p->pemutus?->nama }} {{ $p->diputus_pada?->format('d/m H:i') }}
                                @if ($p->status === 'ditolak')<div class="text-red-700">{{ $p->alasan_tolak }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
