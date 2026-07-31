@extends('layouts.app')

@section('title', 'Target Santri')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Target jumlah santri per tahun ajaran &amp; jenjang.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar />
        @if (\App\Support\Akses::boleh('target-santri', 'buat'))
            <a href="{{ route('target_santri.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Tahun Ajaran</th><th class="px-4 py-3">Jenjang</th><th class="px-4 py-3 text-right">Target</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" type="select" /><x-fcol :col="1" type="select" /><x-fcol type="blank" /><x-fcol :col="3" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->tahun_ajaran }}</td>
                        {{-- Nama di depan, kodenya jadi keterangan kecil: yang dicari
                             pembaca adalah "SMP", bukan "J002". --}}
                        <td class="px-4 py-3">{{ $r->jenjang?->nama ?? $r->kode_jenjang }}<div class="text-xs text-gray-400">{{ $r->kode_jenjang }}</div></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($r->target, 0, ',', '.') }}
                            <div class="text-[11px] font-normal text-gray-400">
                                @if ($r->target_l !== null || $r->target_p !== null)
                                    L {{ (int) $r->target_l }} · P {{ (int) $r->target_p }}
                                @else
                                    belum dirinci L/P
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->keterangan }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('target-santri', 'ubah'))<a href="{{ route('target_santri.edit', $r->id) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('target-santri', 'hapus'))
                                    <form method="POST" action="{{ route('target_santri.destroy', $r->id) }}" onsubmit="return confirm('Hapus target ini?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada target santri.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
