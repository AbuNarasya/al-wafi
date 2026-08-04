@extends('layouts.app')

@section('title', 'Potongan Gelombang')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Potongan uang pangkal early-bird per gelombang pendaftaran &amp; jenjang.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar />
        @if (\App\Support\Akses::boleh('potongan-gelombang', 'buat'))
            <a href="{{ route('potongan_gelombang.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Tahun Ajaran</th><th class="px-4 py-3">Gelombang</th><th class="px-4 py-3">Jenjang</th><th class="px-4 py-3 text-right">Potongan</th><th class="px-4 py-3 text-right">Masa Berlaku</th><th class="px-4 py-3">Periode Gelombang</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" type="select" /><x-fcol :col="1" type="select" /><x-fcol :col="2" type="select" /><x-fcol type="blank" /><x-fcol type="blank" /><x-fcol type="blank" /><x-fcol :col="6" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->tahun_ajaran }}</td>
                        <td class="px-4 py-3">Gelombang {{ $r->gelombang }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->kode_jenjang ? ($r->jenjang?->nama ?? $r->kode_jenjang) : 'Semua' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->potongan)</td>
                        <td class="px-4 py-3 text-right text-gray-500">{{ $r->masa_berlaku_hari }} hari</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->labelPeriode() }}</td>
                        <td class="px-4 py-3">
                            {{-- Status DIHITUNG, bukan dibaca dari kolom `aktif`: potongan yang
                                 periodenya lewat berhenti dipakai tanpa ada yang mematikannya. --}}
                            @php
                                $keadaan = $r->keadaan();
                                [$labelStatus, $warnaStatus] = match ($keadaan) {
                                    'berlaku' => ['Aktif', 'bg-emerald-100 text-emerald-700'],
                                    'belum_mulai' => ['Belum Mulai', 'bg-blue-100 text-blue-700'],
                                    'kedaluwarsa' => ['Kedaluwarsa', 'bg-amber-100 text-amber-800'],
                                    default => ['Arsip', 'bg-gray-100 text-gray-500'],
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $warnaStatus }}">{{ $labelStatus }}</span>
                            @if ($keadaan === 'kedaluwarsa')
                                <span class="mt-0.5 block text-[11px] text-gray-400">periode lewat — perpanjang untuk memberlakukan lagi</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                @if (\App\Support\Akses::boleh('potongan-gelombang', 'ubah'))
                                    <a href="{{ route('potongan_gelombang.edit', $r->id) }}" class="text-brand hover:underline">Sunting</a>
                                @endif
                                @if (\App\Support\Akses::boleh('potongan-gelombang', 'hapus'))
                                    <form method="POST" action="{{ route('potongan_gelombang.destroy', $r->id) }}" onsubmit="return confirm('Hapus potongan ini?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada potongan gelombang.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="8" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
