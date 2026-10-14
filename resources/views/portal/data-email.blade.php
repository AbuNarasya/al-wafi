@extends('layouts.portal')

@section('title', 'Ganti Email '.$label)
@section('kembali', route('portal.data', ['tab' => 'wali']))

@section('content')
    <div class="mb-5 mt-4 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="lonceng" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">Email {{ strtolower($label) }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $nama ?: $label }} &middot; sekarang: <b class="break-all text-slate-700">{{ $emailLama ?: 'belum diisi' }}</b></p>
    </div>

    @if (! filled($nama))
        <div class="rounded-3xl bg-amber-50 p-5 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
            Isi dulu nama {{ strtolower($label) }} di <a href="{{ route('portal.data.keluarga') }}" class="font-semibold underline">Perbaharui Data Wali</a>, lalu kembali ke sini untuk mengisi emailnya.
        </div>
    @else
        <form method="POST" action="{{ route('portal.data.email', $peran) }}" data-no-confirm class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email baru</label>
            <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}" autocomplete="email"
                   class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand">
            @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            <button class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Kirim kode ke email baru</button>
        </form>
        <ul class="mt-4 space-y-1.5 px-2 text-xs leading-relaxed text-slate-500">
            <li>&bull; Kode 6 angka dikirim ke email <b>baru</b> — sebagai bukti email itu benar dan bisa dibuka.</li>
            <li>&bull; Email ini dipakai {{ strtolower($label) }} untuk <b>masuk Portal Wali</b>. Email lama tak bisa dipakai lagi dan pemiliknya diberi tahu.</li>
            <li>&bull; Sesudah diganti, perangkat lain keluarga ini harus masuk ulang dengan kode.</li>
        </ul>
    @endif
@endsection
