@extends('layouts.portal')

@section('title', __('Tarik tabungan'))
@section('kembali', route('portal.dompet'))

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $warna = ['menunggu' => 'bg-amber-50 text-amber-700', 'disetujui' => 'bg-emerald-50 text-emerald-700', 'ditolak' => 'bg-red-50 text-red-700', 'batal' => 'bg-slate-100 text-slate-600'];
    $label = ['menunggu' => __('Menunggu'), 'disetujui' => __('Disetujui'), 'ditolak' => __('Ditolak'), 'batal' => __('Dibatalkan')];
@endphp

@section('content')
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#4f7a5c] text-white shadow-sm"><x-portal.ikon nama="riwayat" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Tarik tabungan') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Disetujui keuangan, lalu masuk ke Dompet Wali.') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('portal.dompet.tabungan') }}" data-no-confirm class="space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
          x-data="pinPad(@js(\App\Services\PortalWali\SesiWali::punyaPin()), @js(route('portal.pin')))" @submit.prevent="buka()">
        @csrf
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('Anak') }}</label>
            <select name="id_santri" required class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base">
                @foreach ($anak as $a)
                    <option value="{{ $a->id }}" @selected(old('id_santri') == $a->id)>{{ $a->nama }} — {{ __('tabungan :nominal', ['nominal' => $rp($a->tabungan?->saldo ?? 0)]) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="nominal" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Nominal') }}</label>
            <input id="nominal" name="nominal" type="number" min="1" step="1" required value="{{ old('nominal') }}"
                   class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
        </div>
        <div>
            <label for="alasan" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Keperluan') }}</label>
            <textarea id="alasan" name="alasan" rows="2" required maxlength="500" placeholder="{{ __('mis. biaya kursus, keperluan keluarga') }}"
                      class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">{{ old('alasan') }}</textarea>
        </div>
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Ajukan penarikan') }}</button>

        @include('portal._pin', ['judul' => __('Konfirmasi pengajuan tarik tabungan')])
    </form>

    @if ($pengajuan->isNotEmpty())
        <section class="mt-5">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Riwayat pengajuan') }}</h2>
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @foreach ($pengajuan as $p)
                    <div class="px-4 py-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $p->santri?->nama }} &middot; {{ $rp($p->nominal) }}</p>
                                <p class="text-xs text-slate-500">{{ $p->nomor }} &middot; {{ $p->created_at?->format('d/m/Y') }}</p>
                                @if ($p->status === 'ditolak' && $p->alasan_tolak)
                                    <p class="text-xs text-red-700">{{ $p->alasan_tolak }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$p->status] }}">{{ $label[$p->status] }}</span>
                        </div>
                        @if ($p->status === 'menunggu')
                            <form method="POST" action="{{ route('portal.dompet.tabungan.batal', $p->id) }}" data-confirm="{{ __('Batalkan pengajuan ini?') }}" class="mt-1">
                                @csrf<button class="text-xs font-semibold text-red-700">{{ __('Batalkan') }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
