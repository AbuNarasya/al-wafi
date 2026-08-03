{{-- Tombol Unduh daftar santri (CSV/Excel/PDF) — membawa penyaring yang aktif. --}}
@props(['lingkup'])
@php
    $base = request()->query();
    // `page` dibuang: unduhan selalu berisi SELURUH baris yang cocok, bukan
    // halaman yang kebetulan sedang dibuka.
    unset($base['format'], $base['page'], $base['kolom']);
@endphp
<x-unduh :url="route('santri.unduh', $lingkup).'?'.http_build_query($base)" />
