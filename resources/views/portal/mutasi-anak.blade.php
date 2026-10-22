@extends('layouts.portal')

@section('title', __('Mutasi dompet anak'))

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand text-white shadow-sm"><x-portal.ikon nama="dompet" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Mutasi dompet anak') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ $dipilih ? __('Uang saku & tabungan :nama', ['nama' => $dipilih->nama]) : __('Uang saku & tabungan seluruh anak') }}</p>
        </div>
    </div>

    {{-- Pilihan tarik-turun, bukan deretan tombol yang digulir ke samping: nama santri
         panjang, dan deretan itu memunculkan bilah gulir di layar lebar. --}}
    @if ($anak->count() > 1)
        <form method="GET" action="{{ route('portal.dompet.mutasi_anak') }}" class="mb-4">
            <label for="pilih-anak" class="mb-1 block text-xs font-semibold uppercase tracking-widest text-slate-500">{{ __('Tampilkan') }}</label>
            <select id="pilih-anak" name="anak" onchange="this.form.submit()"
                    class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-900 focus:border-brand focus:ring-brand">
                <option value="">{{ __('Semua anak') }}</option>
                @foreach ($anak as $a)
                    <option value="{{ $a->id }}" @selected($dipilih?->id === $a->id)>{{ $a->nama }}</option>
                @endforeach
            </select>
            <noscript><button class="mt-2 rounded-2xl bg-brand px-4 py-2 text-sm font-semibold text-white">{{ __('Tampilkan') }}</button></noscript>
        </form>
    @endif

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs text-slate-500">{{ __('Uang saku') }}</p>
            <p class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($saldoSaku) }}</p>
        </div>
        <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs text-slate-500">{{ __('Tabungan') }}</p>
            <p class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($saldoTabungan) }}</p>
        </div>
    </div>

    {{-- Baris belanja kartu bisa diketuk → lembar rincian barang (_rincian-belanja). --}}
    <div class="mt-4 divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200" x-data>
        @forelse ($mutasi as $m)
            <div @class(['flex items-start justify-between gap-2 px-4 py-3', 'cursor-pointer active:bg-slate-50' => $m['rinci']])
                 @if ($m['rinci']) role="button" tabindex="0" @click="$dispatch('rincian-belanja', @js($m['rinci']))" @keydown.enter="$dispatch('rincian-belanja', @js($m['rinci']))" @endif>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900">{{ $m['dompet'] }} &middot; {{ $m['label'] }}</p>
                    <p class="truncate text-xs text-slate-500">
                        {{ $m['tanggal']->format('d/m/Y') }}{{ $dipilih ? '' : ' · '.$m['anak'] }}{{ $m['keterangan'] ? ' · '.$m['keterangan'] : '' }}
                    </p>
                    @if ($m['menunggu'])<p class="text-xs font-medium text-amber-700">{{ __('Menunggu verifikasi') }}</p>@endif
                    @if ($m['rinci'])<p class="text-xs font-medium text-brand">{{ __('Lihat rincian') }} <span class="inline-block rtl:-scale-x-100">›</span></p>@endif
                </div>
                <p @class(['rp shrink-0 text-sm font-bold tabular-nums', 'text-red-700' => $m['keluar'], 'text-emerald-700' => ! $m['keluar'] && ! $m['menunggu'], 'text-slate-400' => $m['menunggu']])>
                    {{ $m['keluar'] ? '−' : '+' }}{{ number_format($m['nominal'], 0, ',', '.') }}
                </p>
            </div>
        @empty
            <p class="p-6 text-center text-sm text-slate-500">{{ __('Belum ada mutasi.') }}</p>
        @endforelse
    </div>

    @include('portal._rincian-belanja')
@endsection
