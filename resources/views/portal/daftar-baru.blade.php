@extends('layouts.portal')

@section('title', __('Daftarkan Calon Santri'))

@section('content')
    <a href="{{ route('portal.depan') }}" class="mt-2 inline-block text-sm font-semibold text-brand"><span class="inline-block rtl:-scale-x-100">&larr;</span> {{ __('Kembali') }}</a>

    <div class="mb-5 mt-4 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-accent text-slate-900"><x-portal.ikon nama="psb" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ __('Buat akun & daftarkan calon santri') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('Masukkan email aktif Anda. Kami kirim kode 6 angka; sesudah kodenya benar, formulir pendaftaran santri baru terbuka.') }}</p>
    </div>

    <form method="POST" action="{{ route('portal.masuk') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <label for="email" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Email aktif') }}</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required autofocus value="{{ old('email') }}"
               placeholder="{{ __('nama@email.com') }}" class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
        @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim kode ke email') }}</button>
    </form>

    <div class="mt-4 rounded-3xl bg-white p-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">
        <p class="font-semibold text-slate-900">{{ __('Sudah punya anak yang terdaftar di pesantren?') }}</p>
        <p class="mt-1">{!! __('Masuk dulu lewat :tautan, lalu daftarkan adiknya dari menu Akun — supaya tetap satu keluarga.', ['tautan' => '<a href="'.e(route('portal.masuk')).'" class="font-semibold text-brand">'.e(__('Masuk Aplikasi')).'</a>']) !!}</p>
    </div>
@endsection
