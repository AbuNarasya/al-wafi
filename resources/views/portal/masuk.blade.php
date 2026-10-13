@extends('layouts.portal')

@section('title', 'Masuk')

@section('content')
    <div class="mb-6 mt-6 text-center">
        <x-logo class="mx-auto mb-3 h-16 w-16 rounded-2xl text-2xl" />
        <h1 class="text-2xl font-bold text-slate-900">Portal Wali</h1>
        <p class="mt-1 text-sm text-slate-500">Masuk atau daftarkan anak Anda &mdash; tanpa kata sandi.</p>
    </div>

    <form method="POST" action="{{ route('portal.masuk') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required autofocus
               value="{{ old('email') }}" placeholder="nama@email.com"
               class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
        @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Kirim kode masuk</button>
    </form>

    <div class="mt-4 rounded-3xl bg-white p-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">
        <p class="font-semibold text-slate-900">Belum pernah mendaftar?</p>
        <p class="mt-1">Pakai email yang sama. Setelah kodenya benar, formulir pendaftaran santri baru akan terbuka.</p>
        <p class="mt-2 font-semibold text-slate-900">Wali santri yang sudah terdaftar</p>
        <p class="mt-1">Pakai email yang tercatat di data keluarga Anda.</p>
    </div>
@endsection
