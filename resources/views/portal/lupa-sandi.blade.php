@extends('layouts.portal')

@section('title', __('Lupa Password'))
@section('kembali', route('portal.masuk'))

@section('content')
    <div class="mb-5 mt-6 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="lonceng" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ __('Lupa password') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('Masukkan email Anda. Kami kirim kode 6 angka, lalu Anda membuat password baru.') }}</p>
    </div>

    <form method="POST" action="{{ route('portal.masuk') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <input type="hidden" name="lupa" value="1">
        <label for="email" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Email') }}</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required autofocus value="{{ old('email') }}"
               placeholder="{{ __('nama@email.com') }}" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
        @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim kode') }}</button>
    </form>
    <p class="mt-4 px-2 text-center text-xs text-slate-500">{{ __('Sesudah password baru dibuat, perangkat lain yang masih masuk dengan akun Anda akan dikeluarkan.') }}</p>
@endsection
