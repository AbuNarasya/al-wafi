@extends('layouts.portal')

@section('title', __('Verifikasi Data Keluarga'))

@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
@endphp

@section('content')
    <div class="mb-5 mt-2 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="data" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ __('Kehilangan link verifikasi?') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('Isi data salah satu anak Anda untuk membuka kembali link verifikasi keluarga.') }}</p>
    </div>

    <form method="POST" action="{{ route('portal.verifikasi.cari') }}" data-no-confirm class="space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <div>
            <label for="nis" class="{{ $kelasLabel }}">{{ __('NIS anak') }}</label>
            <input id="nis" name="nis" inputmode="numeric" data-bukan-rupiah required value="{{ old('nis') }}" class="{{ $kelasInput }}">
            @error('nis')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="tanggal_lahir" class="{{ $kelasLabel }}">{{ __('Tanggal lahir anak') }}</label>
            <input id="tanggal_lahir" name="tanggal_lahir" type="date" required value="{{ old('tanggal_lahir') }}" class="{{ $kelasInput }}">
            @error('tanggal_lahir')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="hp" class="{{ $kelasLabel }}">{{ __('4 angka terakhir nomor HP orang tua yang tercatat di pesantren') }}</label>
            <input id="hp" name="hp" inputmode="numeric" data-bukan-rupiah maxlength="4" required value="{{ old('hp') }}" placeholder="{{ __('mis. 7890') }}" class="{{ $kelasInput }}">
            @error('hp')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Buka link verifikasi') }}</button>
    </form>
    <p class="mt-4 px-2 text-center text-xs text-slate-500">{{ __('Hanya bisa dipakai bila keluarga Anda sudah dikirimi link oleh pesantren dan masa berlakunya belum habis.') }}</p>
@endsection
