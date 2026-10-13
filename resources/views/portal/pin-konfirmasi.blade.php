@extends('layouts.portal')

@section('title', 'Konfirmasi PIN')
@section('kembali', route('portal.pin'))

@section('content')
    <div class="mb-5 mt-4 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="lonceng" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">Masukkan kode</h1>
        <p class="mt-1 text-sm text-slate-500">Kode 6 angka dikirim ke <b class="text-slate-700">{{ $tujuan }}</b>. Berlaku {{ $menit }} menit.</p>
    </div>

    <form method="POST" action="{{ route('portal.pin.konfirmasi') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <input name="kode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus placeholder="••••••" aria-label="Kode 6 angka"
               class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-center text-2xl font-bold tracking-[0.5em] focus:border-brand focus:ring-brand">
        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Simpan PIN</button>
    </form>
    <a href="{{ route('portal.pin') }}" class="mt-5 block text-center text-sm font-semibold text-brand">Ulangi dari awal</a>
@endsection
