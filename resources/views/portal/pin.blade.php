@extends('layouts.portal')

@section('title', 'PIN transaksi')
@section('kembali', route('portal.akun'))

@php $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-center text-xl font-bold tracking-[0.5em] focus:border-brand focus:ring-brand'; @endphp

@section('content')
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand text-white shadow-sm"><x-portal.ikon nama="kartu" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">PIN transaksi</h1>
            <p class="text-sm text-slate-500">Dipakai setiap membayar dari dompet atau memindah saldo.</p>
        </div>
    </div>

    @if ($terkunci)
        <div class="mb-4 rounded-3xl bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-200">
            PIN sedang terkunci {{ $terkunci }} menit lagi karena salah berulang kali. Anda bisa langsung menyetel PIN baru di bawah.
        </div>
    @endif

    {{-- Buat pertama / lupa PIN: dikonfirmasi kode email --}}
    <form method="POST" action="{{ route('portal.pin.kode') }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        <p class="font-semibold text-slate-900">{{ $punya ? 'Lupa PIN? Setel PIN baru' : 'Buat PIN' }}</p>
        <p class="mb-4 text-sm text-slate-500">6 angka. Kami kirim kode ke email Anda untuk memastikan ini memang Anda.</p>
        <label class="mb-1 block text-sm font-medium text-slate-700">PIN baru</label>
        <input type="password" inputmode="numeric" name="pin_baru" maxlength="6" pattern="\d{6}" required autocomplete="new-password" class="{{ $kelasInput }}">
        <label class="mb-1 mt-3 block text-sm font-medium text-slate-700">Ulangi PIN</label>
        <input type="password" inputmode="numeric" name="pin_ulang" maxlength="6" pattern="\d{6}" required autocomplete="new-password" class="{{ $kelasInput }}">
        <p class="mt-2 text-xs text-slate-500">Hindari PIN mudah ditebak seperti 123456 atau tanggal lahir.</p>
        <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Kirim kode konfirmasi</button>
    </form>

    @if ($punya)
        <form method="POST" action="{{ route('portal.pin.ganti') }}" data-no-confirm class="mt-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <p class="font-semibold text-slate-900">Ganti PIN</p>
            <p class="mb-4 text-sm text-slate-500">Masih ingat PIN lama? Ganti tanpa kode email.</p>
            <label class="mb-1 block text-sm font-medium text-slate-700">PIN lama</label>
            <input type="password" inputmode="numeric" name="pin_lama" maxlength="6" pattern="\d{6}" required autocomplete="current-password" class="{{ $kelasInput }}">
            <label class="mb-1 mt-3 block text-sm font-medium text-slate-700">PIN baru</label>
            <input type="password" inputmode="numeric" name="pin_baru" maxlength="6" pattern="\d{6}" required autocomplete="new-password" class="{{ $kelasInput }}">
            <label class="mb-1 mt-3 block text-sm font-medium text-slate-700">Ulangi PIN baru</label>
            <input type="password" inputmode="numeric" name="pin_ulang" maxlength="6" pattern="\d{6}" required autocomplete="new-password" class="{{ $kelasInput }}">
            <button class="mt-4 w-full rounded-2xl bg-white py-3 text-sm font-bold text-brand ring-1 ring-slate-300">Ganti PIN</button>
        </form>
    @endif
@endsection
