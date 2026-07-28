@extends('layouts.app')

@section('title', 'Persetujuan Saya')

@section('content')
    <p class="mb-4 text-sm text-gray-500">Pengajuan yang menunggu persetujuan Anda pada tahap sekarang.</p>

    @if ($items->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-16 text-center text-gray-400">
            Tidak ada pengajuan yang menunggu persetujuan Anda. 🎉
        </div>
    @else
        <div class="space-y-4">
            @foreach ($items as $inst)
                @php $doc = $docs->get((int) $inst->id_dokumen); @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-gray-900">{{ $doc?->nomor ?? $inst->jenis_dokumen . ' #' . $inst->id_dokumen }}</span>
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Tahap {{ $inst->tahap_sekarang }}</span>
                                @if ($inst->overbudget)<span class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">Overbudget</span>@endif
                                @if ($inst->belum_dianggarkan)<span class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">Belum dianggarkan</span>@endif
                            </div>
                            <div class="mt-1 text-sm text-gray-600">{{ $doc?->keterangan }}</div>
                            <div class="mt-1 text-xs text-gray-400">Bagian: {{ $doc?->bagian?->nama_bagian ?? $inst->kode_bagian }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-semibold tabular-nums text-gray-900">@rp($inst->nominal)</div>
                            @if ($doc)<a href="{{ route('pengajuan.show', $doc->id) }}" class="text-xs text-brand hover:underline">Lihat detail</a>@endif
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4">
                        {{-- Tolak --}}
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Tolak</button>
                            <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('approvals.reject', $inst->id) }}"
                                  class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                @csrf
                                <label class="block text-xs font-medium text-gray-600">Alasan penolakan</label>
                                <input type="text" name="alasan" required maxlength="255" class="w-full rounded border-gray-300 text-sm">
                                <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Konfirmasi Tolak</button>
                            </form>
                        </div>
                        {{-- Setujui --}}
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Setujui</button>
                            <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('approvals.approve', $inst->id) }}"
                                  class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                @csrf
                                <label class="block text-xs font-medium text-gray-600">Catatan (opsional)</label>
                                <input type="text" name="catatan" maxlength="255" class="w-full rounded border-gray-300 text-sm">
                                <button class="w-full rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark">Konfirmasi Setuju</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
