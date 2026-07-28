@extends('layouts.app')

@section('title', 'Neraca')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex items-end gap-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">Per Tanggal</label>
                <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
            </div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'neraca'])
    </div>

    <div class="mb-3">
        @if ($data['balanced'])
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">✓ Neraca seimbang</span>
        @else
            <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">⚠ Tidak seimbang — periksa jurnal</span>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="space-y-4">
            @include('reports._section', ['section' => $data['aset']])
        </div>
        <div class="space-y-4">
            @include('reports._section', ['section' => $data['liabilitas']])
            @include('reports._section', [
                'section' => $data['ekuitas'],
                'extra' => ['label' => 'Laba/Rugi Tahun Berjalan', 'nilai' => $data['ekuitas']['laba_berjalan']],
            ])
            <div class="rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-center justify-between">
                <span class="text-sm font-semibold text-emerald-800">Total Liabilitas + Ekuitas</span>
                <span class="text-sm font-bold text-emerald-900 tabular-nums">@rp((float) $data['total_liabilitas'] + (float) $data['total_ekuitas'])</span>
            </div>
        </div>
    </div>
@endsection
