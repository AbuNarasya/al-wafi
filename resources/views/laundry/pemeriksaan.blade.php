@extends('layouts.app')

@section('title', 'Pemeriksaan Cucian')

@section('content')
    <a href="{{ route('laundry.index') }}" class="text-sm text-brand hover:underline">&larr; Papan Laundry</a>

    {{-- Paket 1 (3 Nov 2026): kantong dibuka di meja pemeriksaan — timbang ulang + jenis & jumlah pakaian. --}}
    <form method="GET" action="{{ route('laundry.pemeriksaan') }}" class="mt-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <label class="block text-sm font-semibold text-gray-900" for="nomor">Pindai label kantong</label>
        <p class="text-xs text-gray-500">Arahkan pemindai ke QR label, atau ketik nomor kantong lalu Enter.</p>
        <div class="mt-2 flex gap-2">
            <input id="nomor" name="nomor" autofocus autocomplete="off" placeholder="LDI-2611-0001"
                   class="w-72 rounded-lg border-gray-300 font-mono text-lg uppercase">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Buka</button>
        </div>
    </form>

    <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <p class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">Antrean pemeriksaan <span class="font-normal text-gray-500">({{ $antrean->count() }} kantong, terlama di atas)</span></p>
        <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2 text-right">Berat terima</th><th class="px-4 py-2">Diterima</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($antrean as $l)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ $l->nomor }}</td>
                        <td class="px-4 py-2">{{ $l->santri?->nama }} <span class="text-xs text-gray-400">{{ $l->santri?->kelas?->nama }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $l->berat }} kg</td>
                        <td class="whitespace-nowrap px-4 py-2 text-xs text-gray-500">{{ $l->diterima_pada?->timezone('Asia/Jakarta')->format('d/m H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            <a href="{{ route('laundry.label', $l->id) }}?cetak=1" target="_blank" class="me-3 text-xs text-gray-500 hover:underline">label</a>
                            <a href="{{ route('laundry.periksa', $l->id) }}" class="text-xs font-semibold text-brand hover:underline">Periksa</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Semua kantong sudah diperiksa.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
