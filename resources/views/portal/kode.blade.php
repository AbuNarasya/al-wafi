@extends('layouts.portal')

@section('title', 'Kode masuk')

@section('content')
    <div class="mb-5 mt-6 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="lonceng" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">Masukkan kode</h1>
        <p class="mt-1 text-sm text-slate-500">
            Kode 6 angka dikirim ke <b class="text-slate-700">{{ $tujuan }}</b>.<br>Berlaku {{ $menit }} menit. Tidak masuk? Periksa folder Spam.
        </p>
    </div>

    @include('portal._kode-uji')

    <form method="POST" action="{{ route('portal.kode') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <input name="kode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus
               placeholder="••••••" aria-label="Kode 6 angka"
               class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-center text-2xl font-bold tracking-[0.5em] focus:border-brand focus:ring-brand">
        @error('kode')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror

        <label class="mt-4 flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="ingat" value="1" class="mt-0.5 rounded border-slate-300 text-brand focus:ring-brand">
            <span>Ingat perangkat ini
                <span class="block text-xs text-slate-500">Tak perlu kode lagi selama 90 hari. Jangan dicentang di HP orang lain.</span>
            </span>
        </label>

        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Masuk</button>
    </form>

    <a href="{{ route('portal.masuk') }}" class="mt-5 block text-center text-sm font-semibold text-brand">Ganti email atau minta kode baru</a>
@endsection
