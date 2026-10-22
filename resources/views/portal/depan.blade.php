@extends('layouts.portal')

@section('title', __('Portal Wali'))

@section('content')
    <div class="mb-6 mt-6 text-center">
        <x-logo class="mx-auto mb-3 h-16 w-16 rounded-2xl text-2xl" />
        <h1 class="text-2xl font-bold text-slate-900">{{ __('Portal Wali') }}</h1>
        <p class="mt-1 text-sm text-slate-500">AL Wafi Islamic Boarding School</p>
    </div>

    {{-- Dua pintu (permintaan user 15 Okt 2026): wali yang belum punya akun langsung tahu harus ke mana. --}}
    <div class="space-y-4">
        <a href="{{ route('portal.masuk') }}" class="flex items-center gap-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200 hover:ring-brand">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="profil" /></span>
            <span class="min-w-0 flex-1">
                <span class="block text-lg font-bold text-slate-900">{{ __('Masuk Aplikasi') }}</span>
                <span class="block text-sm text-slate-500">{{ __('Sudah punya akun, atau wali santri yang sudah terdaftar di pesantren.') }}</span>
            </span>
            <x-portal.ikon nama="kanan" kelas="h-5 w-5 text-slate-400" />
        </a>

        <a href="{{ route('portal.daftar_baru') }}" class="flex items-center gap-4 rounded-3xl bg-amber-50 p-5 shadow-sm ring-2 ring-amber-300 hover:ring-amber-400">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-accent text-slate-900"><x-portal.ikon nama="psb" /></span>
            <span class="min-w-0 flex-1">
                <span class="block text-lg font-bold text-slate-900">{{ __('Buat Akun & Daftarkan Calon Santri') }}</span>
                <span class="block text-sm text-slate-600">{{ __('Pendaftaran santri baru — cukup dengan email aktif Anda.') }}</span>
            </span>
            <x-portal.ikon nama="kanan" kelas="h-5 w-5 text-slate-500" />
        </a>
    </div>
@endsection
