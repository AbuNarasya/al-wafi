@extends('layouts.portal')

@section('title', $ganti ? 'Password Baru' : 'Buat Password')

@section('content')
    <div class="mb-5 mt-6 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="kartu" /></span>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ $ganti ? 'Buat password baru' : 'Buat password' }}</h1>
        <p class="mt-1 text-sm text-slate-500">Mulai sekarang Anda masuk Portal Wali dengan email dan password ini — tanpa menunggu kode email lagi.</p>
    </div>

    <form method="POST" action="{{ route('portal.sandi.buat') }}" data-no-confirm class="space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        @include('portal._sandi', ['nama' => 'sandi', 'label' => 'Password baru (minimal '.\App\Services\PortalWali\KataSandiWali::MIN.' karakter)',
            'autocomplete' => 'new-password', 'min' => \App\Services\PortalWali\KataSandiWali::MIN])
        @include('portal._sandi', ['nama' => 'sandi_ulang', 'label' => 'Ulangi password baru', 'autocomplete' => 'new-password'])
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Simpan password</button>
    </form>
    <p class="mt-4 px-2 text-center text-xs text-slate-500">Jangan pakai password yang sama dengan akun lain. Pembayaran tetap memakai PIN transaksi tersendiri.</p>

    <form method="POST" action="{{ route('portal.keluar') }}" data-no-confirm class="mt-4 text-center">
        @csrf
        <button class="text-sm font-semibold text-slate-500">Batal &amp; keluar</button>
    </form>
@endsection
