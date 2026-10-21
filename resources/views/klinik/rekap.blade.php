@extends('layouts.app')

@section('title', 'Rekap Klinik')

@section('content')
    <a href="{{ route('klinik.index') }}" class="text-sm text-brand hover:underline">&larr; Klinik</a>
    <form method="GET" class="mb-4 mt-2 flex flex-wrap items-center gap-2 text-sm">
        <input type="date" name="dari" value="{{ $dari->toDateString() }}" class="rounded-lg border-gray-300 text-sm"> s/d
        <input type="date" name="sampai" value="{{ $sampai->toDateString() }}" class="rounded-lg border-gray-300 text-sm">
        <button class="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white">Tampilkan</button>
    </form>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Kunjungan</p><p class="text-2xl font-bold">{{ $jumlah }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Santri berbeda</p><p class="text-2xl font-bold">{{ $santri }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Dirujuk ke RS</p><p class="text-2xl font-bold">{{ $tindakLanjut['dirujuk'] ?? 0 }}</p></div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
            <p class="mb-2 font-semibold">Tindak lanjut</p>
            @foreach (\App\Models\KunjunganKlinik::TINDAK_LANJUT as $k => $l)
                <div class="flex justify-between border-t border-gray-100 py-1 first:border-0"><span>{{ $l }}</span><b class="tabular-nums">{{ $tindakLanjut[$k] ?? 0 }}</b></div>
            @endforeach
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
            <p class="mb-2 font-semibold">Keluhan terbanyak <span class="text-xs font-normal text-gray-500">(kata pertama keluhan)</span></p>
            @forelse ($keluhan as $kata => $n)
                <div class="flex justify-between border-t border-gray-100 py-1 first:border-0"><span>{{ $kata }}</span><b class="tabular-nums">{{ $n }}</b></div>
            @empty
                <p class="text-gray-400">Belum ada data.</p>
            @endforelse
        </div>
    </div>
@endsection
