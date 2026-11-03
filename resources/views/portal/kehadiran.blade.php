@extends('layouts.portal')

@section('title', __('Kehadiran asrama'))
@section('kembali', route('portal.beranda'))

@section('content')
    @php
        $label = \App\Services\Modules\AbsensiAsramaService::LABEL_KATEGORI;
        $warna = ['hadir' => 'bg-emerald-100 text-emerald-800', 'terlambat' => 'bg-amber-100 text-amber-800', 'tidak_hadir' => 'bg-red-100 text-red-700',
            'izin' => 'bg-sky-100 text-sky-800', 'uzur' => 'bg-violet-100 text-violet-800', 'lainnya' => 'bg-slate-100 text-slate-600'];
        $q = fn (array $x = []) => route('portal.kehadiran', array_filter(['anak' => $pilih?->id] + $x));
    @endphp
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-green-700 text-white shadow-sm"><x-portal.ikon nama="absensi" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Kehadiran asrama') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ $pilih?->nama ?? __('Belum ada anak berstatus santri aktif.') }}</p>
        </div>
    </div>

    @if ($anak->count() > 1)
        <div class="mb-4 flex gap-2 overflow-x-auto text-sm">
            @foreach ($anak as $a)
                <a href="{{ route('portal.kehadiran', ['anak' => $a->id]) }}" @class(['shrink-0 rounded-full px-3 py-1.5', 'bg-brand text-white' => $pilih?->id === $a->id, 'bg-white text-slate-600 ring-1 ring-slate-200' => $pilih?->id !== $a->id])>{{ \Illuminate\Support\Str::of($a->nama)->before(' ')->title() }}</a>
            @endforeach
        </div>
    @endif

    @if ($pilih)
        {{-- Ringkasan per sesi: 7 / 30 hari terakhir. --}}
        <section class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" x-data="{ rentang: 7 }">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-800">{{ __('Ringkasan') }}</h2>
                <div class="flex gap-1 text-xs">
                    @foreach ([7, 30] as $h)
                        <button type="button" @click="rentang = {{ $h }}" :class="rentang === {{ $h }} ? 'bg-brand text-white' : 'bg-slate-100 text-slate-600'" class="rounded-full px-3 py-1">{{ __(':hari hari', ['hari' => $h]) }}</button>
                    @endforeach
                </div>
            </div>
            @foreach (['7' => $ringkas7, '30' => $ringkas30] as $h => $ringkas)
                <div x-show="rentang === {{ $h }}" @if ($h === '30') x-cloak @endif class="mt-3 space-y-2">
                    @forelse ($ringkas as $sesi => $r)
                        <div class="rounded-2xl bg-slate-50 px-3 py-2 text-sm">
                            <p class="font-medium text-slate-800">{{ $sesi }}</p>
                            <div class="mt-1 flex flex-wrap gap-1.5 text-[11px]">
                                @foreach (['hadir', 'terlambat', 'tidak_hadir', 'izin', 'uzur'] as $k)
                                    @continue(! $r[$k])
                                    <span class="rounded-full px-2 py-0.5 {{ $warna[$k] }}">{{ __($label[$k]) }} {{ $r[$k] }}</span>
                                @endforeach
                                <span class="text-slate-400">/ {{ $r['total'] }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('Belum ada catatan kehadiran.') }}</p>
                    @endforelse
                </div>
            @endforeach
        </section>

        {{-- Riwayat per hari --}}
        <div class="mb-2 mt-6 flex items-center justify-between gap-2">
            <a href="{{ $q(['tanggal' => $tanggal->copy()->subDay()->format('Y-m-d')]) }}" class="rounded-full bg-white px-3 py-1.5 text-sm text-slate-600 ring-1 ring-slate-200" aria-label="{{ __('Hari sebelumnya') }}"><span class="inline-block rtl:-scale-x-100">&larr;</span></a>
            <form method="GET" action="{{ route('portal.kehadiran') }}" class="flex-1 text-center">
                <input type="hidden" name="anak" value="{{ $pilih->id }}">
                <input type="date" name="tanggal" value="{{ $tanggal->format('Y-m-d') }}" max="{{ now('Asia/Jakarta')->format('Y-m-d') }}" onchange="this.form.submit()"
                       class="rounded-2xl border border-slate-300 bg-white px-3 py-1.5 text-sm" aria-label="{{ __('Tanggal') }}">
            </form>
            @if ($tanggal->lt(now('Asia/Jakarta')->startOfDay()))
                <a href="{{ $q(['tanggal' => $tanggal->copy()->addDay()->format('Y-m-d')]) }}" class="rounded-full bg-white px-3 py-1.5 text-sm text-slate-600 ring-1 ring-slate-200" aria-label="{{ __('Hari berikutnya') }}"><span class="inline-block rtl:-scale-x-100">&rarr;</span></a>
            @else
                <span class="w-10"></span>
            @endif
        </div>
        <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($harian as $a)
                @php $k = \App\Services\Modules\AbsensiAsramaService::kategoriEfektif($a, $izin); @endphp
                <div class="flex items-center justify-between gap-2 px-4 py-3 text-sm">
                    <span class="min-w-0"><span class="block font-medium text-slate-900">{{ $a->sesi }}</span>
                        <span class="block text-xs text-slate-500">{{ $a->waktu->timezone('Asia/Jakarta')->format('H:i') }}{{ $a->asrama ? ' · '.$a->asrama : '' }}</span></span>
                    <span class="shrink-0 text-end">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$k] }}">{{ __($label[$k]) }}</span>
                        @if ($k !== 'izin')<span class="mt-0.5 block text-[11px] text-slate-500">{{ \App\Services\Modules\AbsensiAsramaService::statusUntukWali($a) }}</span>@endif
                    </span>
                </div>
            @empty
                <p class="p-4 text-center text-sm text-slate-500">{{ __('Belum ada catatan kehadiran pada tanggal ini.') }}</p>
            @endforelse
        </div>
    @endif
@endsection
