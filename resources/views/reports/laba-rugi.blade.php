@extends('layouts.app')

@section('title', 'Laba Rugi')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'laba-rugi'])
    </div>

    <div class="mx-auto max-w-3xl space-y-4">
        @include('reports._section', ['section' => $data['pendapatan'], 'detail' => ['from' => $from, 'to' => $to]])
        @include('reports._section', ['section' => $data['beban'], 'detail' => ['from' => $from, 'to' => $to]])

        <div class="rounded-xl border-2 px-4 py-4 flex items-center justify-between {{ (float) $data['laba_rugi_bersih'] >= 0 ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' }}">
            <span class="text-sm font-semibold {{ (float) $data['laba_rugi_bersih'] >= 0 ? 'text-emerald-800' : 'text-red-800' }}">
                Laba/Rugi Bersih
            </span>
            <span class="text-lg font-bold tabular-nums {{ (float) $data['laba_rugi_bersih'] >= 0 ? 'text-emerald-900' : 'text-red-900' }}">
                @rp($data['laba_rugi_bersih'])
            </span>
        </div>
    </div>
@endsection
