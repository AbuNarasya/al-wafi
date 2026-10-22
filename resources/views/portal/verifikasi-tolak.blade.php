@extends('layouts.portal')

@section('title', __('Verifikasi Data'))

@section('content')
    <div class="mt-6 rounded-3xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-700"><x-portal.ikon nama="lonceng" /></span>
        <p class="mt-4 text-sm leading-relaxed text-slate-700">{{ $pesan }}</p>
        <a href="{{ route('portal.masuk') }}" class="mt-5 inline-block rounded-2xl bg-brand px-5 py-2.5 text-sm font-semibold text-white">{{ __('Ke halaman masuk Portal Wali') }}</a>
    </div>
@endsection
