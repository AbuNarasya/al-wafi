@extends('layouts.app')

@section('title', 'Profil Saya')

@section('content')
    <div class="mx-auto max-w-2xl space-y-4">
        {{-- Identitas akun: baca saja. Level, bagian, dan status hanya boleh
             diubah lewat modul Pengguna oleh yang berwenang. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold text-gray-900">Akun Saya</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-gray-500">Username</dt>
                    <dd class="font-medium text-gray-900">{{ $user->username }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Nama Lengkap</dt>
                    <dd class="font-medium text-gray-900">{{ $user->nama }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Jabatan</dt>
                    <dd class="text-gray-900">{{ $user->jabatan ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Bagian</dt>
                    <dd class="text-gray-900">{{ $user->bagian?->nama_bagian ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Level Otorisasi Keuangan</dt>
                    <dd class="text-gray-900">{{ $user->level?->nama_level ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Peringkat Pengajuan</dt>
                    <dd class="text-gray-900">{{ $user->levelPengajuan?->nama ?: '— Tidak ikut rantai pengajuan —' }}</dd>
                </div>
            </dl>
            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-400">
                Perubahan data di atas hanya bisa dilakukan lewat modul Pengguna. Hubungi administrator bila keliru.
            </p>
        </div>

        <form method="POST" action="{{ route('profil.kata_sandi') }}"
              data-confirm="Ganti kata sandi akun Anda sekarang? Kata sandi lama tidak akan berlaku lagi."
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <h2 class="text-sm font-semibold text-gray-900">Ganti Kata Sandi</h2>

            <x-field name="password_lama" label="Kata Sandi Lama" type="password" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="password_baru" label="Kata Sandi Baru" type="password" required
                         hint="Minimal 6 karakter, dan harus berbeda dari kata sandi lama." />
                <x-field name="password_baru_confirmation" label="Ulangi Kata Sandi Baru" type="password" required />
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('dashboard') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    Simpan Kata Sandi
                </button>
            </div>
        </form>
    </div>
@endsection
