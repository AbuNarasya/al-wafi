@extends('layouts.app')

@section('title', 'Pemeriksaan Cucian')

@section('content')
    {{-- Sesudah memeriksa: label berisi DAFTAR CUCIAN untuk cek saat packing (10 Nov 2026) — dicetak hanya bila ditekan. --}}
    @if (session('cetak_label'))
        <a href="{{ route('laundry.label', session('cetak_label')) }}?cetak=1" target="_blank"
           class="mb-3 inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a1 1 0 011-1h16a1 1 0 011 1v6a1 1 0 01-1 1h-2M6 14h12v7H6z"/></svg>
            Cetak label + daftar cucian
        </a>
    @endif
    <a href="{{ route('laundry.index') }}" class="block text-sm text-brand hover:underline">&larr; Papan Laundry</a>

    {{-- Paket 1 (3 Nov 2026): kantong dibuka di meja pemeriksaan — timbang ulang + jenis & jumlah pakaian. --}}
    <form method="GET" action="{{ route('laundry.pemeriksaan') }}" class="mt-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <label class="block text-sm font-semibold text-gray-900" for="nomor">Pindai label kantong — atau cari nama santri</label>
        <p class="text-xs text-gray-500">Arahkan pemindai ke QR label, ketik nomor kantong, atau — bila tak ada pemindai / label tak tercetak — ketik <b>nama atau NIS santri</b>, lalu Enter.</p>
        <div class="mt-2 flex flex-wrap gap-2">
            <input id="nomor" name="nomor" value="{{ $cari ?? '' }}" autofocus autocomplete="off" placeholder="LDI-2611-0001 / nama / NIS"
                   class="w-80 rounded-lg border-gray-300 text-lg">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Buka</button>
            @if (! empty($cari))<a href="{{ route('laundry.pemeriksaan') }}" class="self-center text-sm text-gray-500 hover:underline">tampilkan semua</a>@endif
        </div>
    </form>

    <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <p class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">Antrean pemeriksaan <span class="font-normal text-gray-500">({{ $antrean->count() }} kantong{{ ! empty($cari) ? ' cocok dengan "'.$cari.'"' : '' }}, terlama di atas)</span></p>
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
