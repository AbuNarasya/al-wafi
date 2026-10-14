@extends('layouts.portal')

@section('title', 'Ganti Password')
@section('kembali', route('portal.akun'))

@section('content')
    <form method="POST" action="{{ route('portal.sandi.ganti') }}" data-no-confirm class="mt-4 space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @csrf
        @include('portal._sandi', ['nama' => 'sandi_lama', 'label' => 'Password lama'])
        @include('portal._sandi', ['nama' => 'sandi', 'label' => 'Password baru (minimal '.\App\Services\PortalWali\KataSandiWali::MIN.' karakter)',
            'autocomplete' => 'new-password', 'min' => \App\Services\PortalWali\KataSandiWali::MIN])
        @include('portal._sandi', ['nama' => 'sandi_ulang', 'label' => 'Ulangi password baru', 'autocomplete' => 'new-password'])
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Ganti password</button>
    </form>
    <p class="mt-4 px-2 text-center text-xs text-slate-500">Sesudah diganti, perangkat lain yang masih masuk dengan akun Anda akan dikeluarkan.</p>
@endsection
