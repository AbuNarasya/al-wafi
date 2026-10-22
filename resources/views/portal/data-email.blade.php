@extends('layouts.portal')

@php
    // Label peran (Ayah/Ibu/Wali) dalam bahasa wali; huruf kecil untuk di tengah kalimat.
    $labelBahasa = __($label);
    $labelKecil = mb_strtolower($labelBahasa);
@endphp

@section('title', __('Ganti Email :peran', ['peran' => $labelBahasa]))
@section('kembali', route('portal.data', ['tab' => 'wali']))

@section('content')
    <div class="mb-5 mt-4 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="lonceng" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ __('Email :peran', ['peran' => $labelKecil]) }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $nama ?: $labelBahasa }} &middot; {{ __('sekarang:') }} <b class="break-all text-slate-700">{{ $emailLama ?: __('belum diisi') }}</b></p>
    </div>

    @if (! filled($nama))
        <div class="rounded-3xl bg-amber-50 p-5 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
            {!! __('Isi dulu nama :peran di :tautan, lalu kembali ke sini untuk mengisi emailnya.', [
                'peran' => e($labelKecil),
                'tautan' => '<a href="'.e(route('portal.data.keluarga')).'" class="font-semibold underline">'.e(__('Perbaharui Data Wali')).'</a>',
            ]) !!}
        </div>
    @else
        <form method="POST" action="{{ route('portal.data.email', $peran) }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Email baru') }}</label>
            <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}" autocomplete="email"
                   class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
            @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim kode ke email baru') }}</button>
        </form>
        <ul class="mt-4 space-y-1.5 px-2 text-xs leading-relaxed text-slate-500">
            <li>&bull; {!! __('Kode 6 angka dikirim ke email <b>baru</b> — sebagai bukti email itu benar dan bisa dibuka.') !!}</li>
            <li>&bull; {!! __('Email ini dipakai :peran untuk <b>masuk Portal Wali</b>. Email lama tak bisa dipakai lagi dan pemiliknya diberi tahu.', ['peran' => e($labelKecil)]) !!}</li>
            <li>&bull; {{ __('Sesudah diganti, perangkat lain keluarga ini harus masuk ulang dengan kode.') }}</li>
        </ul>
    @endif
@endsection
