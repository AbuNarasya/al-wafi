{{-- Tombol Unduh tabel kontrol (CSV/Excel/PDF). --}}
@props(['type'])
<x-unduh :url="route('kontrol.download', $type)" />
