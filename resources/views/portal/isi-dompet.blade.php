@extends('layouts.portal')

@section('title', __('Isi Dompet Wali'))
@section('kembali', route('portal.dompet'))

@php
    // Kembali dari galat isian transfer → tetap di pilihan transfer.
    $caraAwal = $online && ! old('kode_rekening') ? 'online' : 'transfer';
@endphp

@section('content')
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#4f7a5c] text-white shadow-sm"><x-portal.ikon nama="isi" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Isi saldo') }}</h1>
            <p class="text-sm text-slate-500">{{ $online ? __('Bayar online lewat Virtual Account, atau transfer lalu kirim bukti.') : __('Transfer ke rekening pesantren, lalu kirim buktinya.') }}</p>
        </div>
    </div>

    @if ($pesanan->isNotEmpty())
        <div class="mb-4 space-y-2">
            @foreach ($pesanan as $p)
                <a href="{{ route('portal.online.lihat', $p->order_id) }}" class="flex items-center justify-between gap-2 rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                    <span>{{ __('Menunggu pembayaran :nominal', ['nominal' => 'Rp '.number_format((float) $p->total, 0, ',', '.')]) }}</span>
                    <span class="font-semibold">{{ __('Lanjutkan') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></span>
                </a>
            @endforeach
        </div>
    @endif

    <div x-data="{ cara: @js($caraAwal) }">
        @if ($online)
            <div class="mb-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
                <button type="button" @click="cara = 'online'" :class="cara === 'online' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ __('Bayar online (VA)') }}</button>
                <button type="button" @click="cara = 'transfer'" :class="cara === 'transfer' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ __('Transfer + bukti') }}</button>
            </div>
            <div x-show="cara === 'online'" @if ($caraAwal !== 'online') x-cloak @endif>
                @include('portal._online', ['aksi' => route('portal.online.dompet'), 'nominalAwal' => 100000,
                    'min' => \App\Services\Midtrans\PembayaranMidtrans::MIN_ISI_DOMPET, 'maks' => null, 'biaya' => $biaya])
            </div>
        @endif

        <form method="POST" action="{{ route('portal.dompet.isi') }}" enctype="multipart/form-data"
              x-show="cara === 'transfer'" @if ($caraAwal !== 'transfer') x-cloak @endif
              data-confirm="{{ __('Kirim bukti transfer ini? Saldo bertambah setelah keuangan memverifikasinya.') }}">
            @csrf
            @include('portal._transfer')
            @if ($rekening !== [])
                <p class="mt-4 rounded-2xl bg-blue-50 px-4 py-3 text-xs text-slate-600">
                    {{ __('Transfer bank langsung diperiksa keuangan lebih dulu — saldo bertambah setelah diverifikasi, biasanya pada hari kerja yang sama.') }}
                </p>
                <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim bukti transfer') }}</button>
            @endif
        </form>
    </div>
@endsection
