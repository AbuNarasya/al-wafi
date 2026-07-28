@extends('layouts.app')

@section('title', 'Uang Muka Operasional')

@php $labelStatus = ['outstanding' => 'bg-amber-100 text-amber-700', 'selesai' => 'bg-emerald-100 text-emerald-700', 'void' => 'bg-gray-100 text-gray-500']; @endphp

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Uang muka belanja operasional. Diselesaikan lewat menu Penyelesaian Uang Muka.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari nomor / penerima…" />
        @if (\App\Support\Akses::boleh('operational-advance', 'buat'))
            <a href="{{ route('operational_advance.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Uang Muka Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Akun UM</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" /><x-fcol :col="3" /><x-fcol type="blank" /><x-fcol type="blank" /><x-fcol :col="6" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor_ref }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->penerima ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->nama_coa_uang_muka }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r->sisa)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            @if ($r->status === 'outstanding' && \App\Support\Akses::boleh('operational-advance', 'hapus'))
                                <div x-data="{ open: false }" class="relative inline-block text-left">
                                    <button @click="open = !open" class="text-red-600 hover:underline">Void</button>
                                    <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('operational_advance.void', $r->id) }}"
                                          onsubmit="return confirm('Void {{ $r->nomor_ref }}?')"
                                          class="absolute right-0 z-10 mt-2 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                        @csrf @method('DELETE')
                                        <label class="block text-xs font-medium text-gray-600">Alasan void</label>
                                        <input type="text" name="alasan" required maxlength="255" placeholder="mis. salah input" class="w-full rounded border-gray-300 text-sm">
                                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Konfirmasi Void</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Belum ada uang muka operasional.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="8" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
