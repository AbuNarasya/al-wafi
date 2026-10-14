@extends('layouts.portal')

@section('title', 'Masuk')

@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
@endphp

@section('content')
    <div class="mb-6 mt-6 text-center">
        <x-logo class="mx-auto mb-3 h-16 w-16 rounded-2xl text-2xl" />
        <h1 class="text-2xl font-bold text-slate-900">Portal Wali</h1>
        <p class="mt-1 text-sm text-slate-500">Masuk dengan email dan password Anda.</p>
    </div>

    {{-- Masuk sehari-hari: email + password (keputusan user 14 Okt 2026). --}}
    <form method="POST" action="{{ route('portal.masuk.sandi') }}" data-no-confirm class="space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required autofocus
                   value="{{ old('email') }}" placeholder="nama@email.com" class="{{ $kelasInput }}">
            @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        @include('portal._sandi', ['nama' => 'sandi', 'label' => 'Password'])
        <label class="flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="ingat" value="1" class="mt-0.5 rounded border-slate-300 text-brand focus:ring-brand">
            <span>Ingat perangkat ini
                <span class="block text-xs text-slate-500">Tetap masuk di perangkat ini. Jangan dicentang di HP orang lain.</span>
            </span>
        </label>
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Masuk</button>
        <a href="{{ route('portal.lupa_sandi') }}" class="block text-center text-sm font-semibold text-brand">Lupa password?</a>
    </form>

    {{-- Pertama kali / mendaftar: kode email SEKALI, lalu membuat password. --}}
    <details class="mt-4 rounded-3xl bg-white p-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200" @if ($errors->has('email_kode') || old('lewat_kode')) open @endif>
        <summary class="cursor-pointer font-semibold text-slate-900">Pertama kali masuk, atau mendaftarkan anak baru?</summary>
        <p class="mt-2">Masukkan email Anda — kami kirim kode 6 angka. Sesudah kodenya benar, Anda membuat password untuk masuk berikutnya.
            Wali yang sudah terdaftar: pakai email yang tercatat di data keluarga.</p>
        <form method="POST" action="{{ route('portal.masuk') }}" data-no-confirm class="mt-3 space-y-3">
            @csrf
            <input type="hidden" name="lewat_kode" value="1">
            <input name="email" type="email" inputmode="email" autocomplete="email" required placeholder="nama@email.com" class="{{ $kelasInput }}">
            <button class="w-full rounded-2xl bg-white py-3 text-sm font-bold text-brand ring-1 ring-slate-300">Kirim kode ke email</button>
        </form>
    </details>
@endsection
