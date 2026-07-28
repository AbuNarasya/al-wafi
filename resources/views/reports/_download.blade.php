{{-- Tombol Unduh laporan (CSV/Excel/PDF) yang mempertahankan filter aktif. --}}
@props(['type'])
@php
    $base = request()->query();
    unset($base['format']);
@endphp
<div class="flex items-center gap-1">
    <span class="text-xs text-gray-400">Unduh:</span>
    @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $f => $lbl)
        <a href="{{ route('reports.export', $type) }}?{{ http_build_query(array_merge($base, ['format' => $f])) }}"
           class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $lbl }}</a>
    @endforeach
</div>
