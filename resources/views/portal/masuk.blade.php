@extends('layouts.portal')

@section('title', __('Masuk'))

@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
@endphp

@section('content')
    <a href="{{ route('portal.depan') }}" class="mt-2 inline-block text-sm font-semibold text-brand"><span class="inline-block rtl:-scale-x-100">&larr;</span> {{ __('Kembali') }}</a>

    <div class="mb-6 mt-4 text-center">
        <x-logo class="mx-auto mb-3 h-16 w-16 rounded-2xl text-2xl" />
        <h1 class="text-2xl font-bold text-slate-900">{{ __('Portal Wali') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('Masuk dengan email dan password Anda.') }}</p>
    </div>

    {{-- Masuk sehari-hari: email + password (keputusan user 14 Okt 2026). --}}
    <form method="POST" action="{{ route('portal.masuk.sandi') }}" data-no-confirm class="space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required autofocus
                   value="{{ old('email') }}" placeholder="{{ __('nama@email.com') }}" class="{{ $kelasInput }}">
            @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        @include('portal._sandi', ['nama' => 'sandi', 'label' => __('Password')])
        <label class="flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="ingat" value="1" class="mt-0.5 rounded border-slate-300 text-brand focus:ring-brand">
            <span>{{ __('Ingat perangkat ini') }}
                <span class="block text-xs text-slate-500">{{ __('Tetap masuk di perangkat ini. Jangan dicentang di HP orang lain.') }}</span>
            </span>
        </label>
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Masuk') }}</button>
        <a href="{{ route('portal.lupa_sandi') }}" class="block text-center text-sm font-semibold text-brand">{{ __('Lupa password?') }}</a>
    </form>

    {{-- Wali TERDAFTAR yang baru pertama kali masuk: kode email SEKALI, lalu membuat password.
         Pendaftaran santri baru punya pintunya sendiri di halaman depan (portal.daftar_baru). --}}
    <details class="mt-4 rounded-3xl bg-white p-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200" @if (old('lewat_kode')) open @endif>
        <summary class="cursor-pointer font-semibold text-slate-900">{{ __('Wali terdaftar, baru pertama kali masuk?') }}</summary>
        <p class="mt-2">{{ __('Masukkan email yang tercatat di data keluarga — kami kirim kode 6 angka. Sesudah kodenya benar, Anda membuat password untuk masuk berikutnya.') }}</p>
        <form method="POST" action="{{ route('portal.masuk') }}" data-no-confirm class="mt-3 space-y-3">
            @csrf
            <input type="hidden" name="lewat_kode" value="1">
            <input name="email" type="email" inputmode="email" autocomplete="email" required placeholder="{{ __('nama@email.com') }}" class="{{ $kelasInput }}">
            <button class="w-full rounded-2xl bg-white py-3 text-sm font-bold text-brand ring-1 ring-slate-300">{{ __('Kirim kode ke email') }}</button>
        </form>
    </details>

    <a href="{{ route('portal.daftar_baru') }}" class="mt-3 flex items-center justify-between gap-2 rounded-3xl bg-amber-50 p-4 text-sm ring-2 ring-amber-300">
        <span><b class="text-slate-900">{{ __('Belum punya akun?') }}</b> <span class="text-slate-600">{{ __('Buat akun & daftarkan calon santri') }}</span></span>
        <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-500" />
    </a>
@endsection
