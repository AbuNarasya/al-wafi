@extends('layouts.portal')

@section('title', __('Riwayat klinik'))
@section('kembali', route('portal.beranda'))

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-red-600 text-white shadow-sm"><x-portal.ikon nama="klinik" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Riwayat klinik') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ $pilih?->nama ?? __('Semua anak') }}</p>
        </div>
    </div>

    @if ($anak->count() > 1)
        <div class="mb-4 flex gap-2 overflow-x-auto text-sm">
            <a href="{{ route('portal.klinik') }}" @class(['shrink-0 rounded-full px-3 py-1.5', 'bg-brand text-white' => ! $pilih, 'bg-white text-slate-600 ring-1 ring-slate-200' => $pilih])>{{ __('Semua') }}</a>
            @foreach ($anak as $a)
                <a href="{{ route('portal.klinik', ['anak' => $a->id]) }}" @class(['shrink-0 rounded-full px-3 py-1.5', 'bg-brand text-white' => $pilih?->id === $a->id, 'bg-white text-slate-600 ring-1 ring-slate-200' => $pilih?->id !== $a->id])>{{ \Illuminate\Support\Str::of($a->nama)->before(' ')->title() }}</a>
            @endforeach
        </div>
    @endif

    <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
        @forelse ($kunjungan as $k)
            @php $serius = in_array($k->tindak_lanjut, ['dirawat', 'dirujuk', 'dijemput'], true); @endphp
            <div class="px-4 py-3 text-sm">
                <div class="flex items-start justify-between gap-2">
                    <p class="font-medium text-slate-900">{{ $k->keluhan }}</p>
                    <span class="shrink-0 text-xs text-slate-500">{{ $k->datang_pada->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('j M Y, H:i') }}</span>
                </div>
                @if ($anak->count() > 1 && ! $pilih)<p class="text-xs text-slate-500">{{ $k->santri?->nama }}</p>@endif
                @if ($k->suhu || $k->tensi)<p class="text-xs text-slate-600">{{ collect([$k->suhu ? __('Suhu :suhu°C', ['suhu' => $k->suhu]) : null, $k->tensi ? __('Tensi :tensi', ['tensi' => $k->tensi]) : null])->filter()->join(' · ') }}</p>@endif
                @if ($k->pemeriksaan)<p class="text-xs text-slate-600">{{ $k->pemeriksaan }}</p>@endif
                @if ($k->obat->isNotEmpty())<p class="text-xs text-slate-600">{{ __('Obat:') }} {{ $k->obat->map(fn ($o) => trim($o->nama.' '.$o->dosis))->join(', ') }}</p>@endif
                <p @class(['text-xs font-semibold', 'text-amber-700' => $serius, 'text-slate-500' => ! $serius])>
                    {{ __($k->labelTindakLanjut()) }}{{ $k->rujukan_ke ? ' — '.$k->rujukan_ke : '' }}{{ $k->status === 'dirawat' ? ' ('.__('masih dirawat').')' : '' }}</p>
            </div>
        @empty
            <p class="p-6 text-center text-sm text-slate-500">{{ __('Belum ada kunjungan ke klinik.') }}</p>
        @endforelse
    </div>
    <div class="mt-3">{{ $kunjungan->links() }}</div>
@endsection
