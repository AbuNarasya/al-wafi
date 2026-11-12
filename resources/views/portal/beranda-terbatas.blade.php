@extends('layouts.portal')

@section('title', __('Beranda'))

{{-- Beranda MODE TERBATAS masa uji coba (12 Nov 2026) — lihat App\Services\PortalWali\PortalTerbatas. --}}
@section('content')
    <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">{{ __("Assalamu'alaikum") }}</p>
        <h1 class="truncate text-2xl font-bold text-slate-900">{{ $nama }}</h1>
        <p class="text-sm text-slate-500">{{ now()->locale(app()->getLocale())->translatedFormat('l, j F Y') }}</p>
    </div>

    <a href="{{ route('portal.data') }}" class="mt-4 flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand/10 text-brand"><x-portal.ikon nama="data" kelas="h-5 w-5" /></span>
        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-slate-900">{{ __('Data Wali & Santri') }}</span>
            <span class="block text-xs text-slate-500">{{ __('Periksa dan perbarui data keluarga serta data santri.') }}</span>
        </span>
        <span class="text-slate-400">&rsaquo;</span>
    </a>

    <h2 class="mt-6 mb-2 text-sm font-semibold text-slate-700">{{ __('Anak') }}</h2>
    <div class="space-y-3">
        @forelse ($anak as $a)
            <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" data-anak="{{ $a['id'] }}">
                <p class="font-semibold text-slate-900">{{ $a['nama'] }}</p>
                <p class="text-xs text-slate-500">{{ $a['jenjang'] }} · {{ $a['label'] }}</p>
                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    <a href="{{ route('portal.data.santri', $a['id']) }}" class="rounded-2xl bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200">{{ __('Data') }}</a>
                    <a href="{{ route('portal.berkas', $a['id']) }}" class="rounded-2xl bg-brand px-3 py-2 font-semibold text-white">{{ __('Berkas') }}</a>
                    @if ($a['ppsb'])
                        <a href="{{ route('portal.anak', $a['id']) }}" class="rounded-2xl bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200">{{ __('Pendaftaran') }}</a>
                    @endif
                </div>
            </div>
        @empty
            <p class="rounded-3xl bg-white p-4 text-sm text-slate-500 ring-1 ring-slate-200">{{ __('Belum ada santri terdaftar di keluarga ini.') }}</p>
        @endforelse
    </div>
@endsection
