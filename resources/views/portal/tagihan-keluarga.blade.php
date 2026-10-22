@extends('layouts.portal')

@section('title', __('Tagihan'))

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#b8956a] text-white shadow-sm"><x-portal.ikon nama="tagihan" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Tagihan') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ __(':jumlah anak', ['jumlah' => $anak->count()]) }}</p>
        </div>
    </div>

    {{-- Total SELURUH anak lebih dulu — supaya tak ada yang mengira tagihan satu anak adalah seluruhnya. --}}
    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">{{ __('Total sisa tagihan keluarga') }}</p>
        <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">{{ $rp($total) }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('Ketuk nama anak untuk melihat rinciannya.') }}</p>
    </div>

    @include('portal._va-menunggu', ['daftar' => $va])

    <div class="mt-4 space-y-2">
        @foreach ($anak as $a)
            <a href="{{ route('portal.tagihan', ['anak' => $a['id']]) }}" class="flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="anak" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-semibold text-slate-900">{{ $a['nama'] }}</span>
                    <span class="block text-sm text-slate-500">{{ $a['jenjang'] }}{{ $a['tingkat'] ? ' · '.__('kelas :tingkat', ['tingkat' => $a['tingkat']]) : '' }} · {{ $a['status'] }}</span>
                </span>
                <span class="shrink-0 text-end">
                    @if ($a['sisa'] > 0)
                        <span class="block font-bold tabular-nums text-slate-900">{{ $rp($a['sisa']) }}</span>
                        <span class="block text-xs font-medium text-red-700">{{ __(':jumlah belum lunas', ['jumlah' => $a['lembar']]) }}</span>
                    @else
                        <span class="block text-sm font-semibold text-emerald-700">{{ __('Lunas') }}</span>
                    @endif
                </span>
                <x-portal.ikon nama="kanan" kelas="h-4 w-4 shrink-0 text-slate-400" />
            </a>
        @endforeach
    </div>
@endsection
