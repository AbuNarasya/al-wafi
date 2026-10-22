@extends('layouts.portal')

@section('title', __('Buktikan Email'))
@section('kembali', route('portal.verifikasi.ringkasan'))

@section('content')
    <div class="mb-5 mt-4 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="lonceng" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ __('Masukkan kode') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{!! __('Kode 6 angka dikirim ke :tujuan.', ['tujuan' => '<b class="break-all text-slate-700">'.e($tujuan).'</b>']) !!} {{ __('Berlaku :menit menit.', ['menit' => $menit]) }}</p>
    </div>

    @include('portal._kode-uji')

    <form method="POST" action="{{ route('portal.verifikasi.kode') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <input name="kode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus placeholder="••••••" aria-label="{{ __('Kode 6 angka') }}"
               class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-center text-2xl font-bold tracking-[0.5em] focus:border-brand focus:ring-brand">
        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Buktikan email') }}</button>
    </form>
    <a href="{{ route('portal.verifikasi.ringkasan') }}" class="mt-5 block text-center text-sm font-semibold text-brand">{{ __('Kembali ke verifikasi') }}</a>
@endsection
