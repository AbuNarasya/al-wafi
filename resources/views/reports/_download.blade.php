{{-- Tombol Unduh laporan (CSV/Excel/PDF) yang mempertahankan filter aktif. --}}
@props(['type'])
@php
    $base = request()->query();
    unset($base['format'], $base['kolom']);
@endphp
<x-unduh :url="route('reports.export', $type).'?'.http_build_query($base)" />
