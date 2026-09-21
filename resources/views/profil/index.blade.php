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

        {{-- Notifikasi perangkat. Sengaja PER PERANGKAT, bukan per akun: izinnya
             memang melekat pada peramban di ponsel/laptop ini, bukan pada akun. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
             x-data="pushLangganan({ kunciPublik: @js($pushKunci), url: @js(route('profil.push.langganan')) })">
            <h2 class="mb-1 text-sm font-semibold text-gray-900">Notifikasi di Perangkat Ini</h2>
            <p class="mb-4 text-xs text-gray-500">
                Kalau dinyalakan, tugas yang menunggu Anda &mdash; persetujuan, verifikasi pembayaran,
                pengingat menyusun tagihan &mdash; akan muncul di perangkat ini walau aplikasinya sedang tertutup.
                Kabar biasa tetap hanya di lonceng.
            </p>

            <template x-if="halangan">
                <div class="mb-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800" x-text="halangan"></div>
            </template>

            <template x-if="pesan">
                <div class="mb-3 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700" x-text="pesan"></div>
            </template>

            <template x-if="didukung">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" x-show="! aktif" :disabled="sibuk" @click="nyalakan()"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">
                        Nyalakan Notifikasi
                    </button>
                    <button type="button" x-show="aktif" x-cloak :disabled="sibuk" @click="matikan()"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                        Matikan Notifikasi
                    </button>
                    <span x-show="aktif" x-cloak class="text-sm text-emerald-700">Aktif di perangkat ini.</span>
                </div>
            </template>

            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-400">
                Pengaturan ini hanya berlaku untuk perangkat yang sedang Anda pakai. Nyalakan sendiri di
                ponsel dan di komputer bila ingin keduanya berbunyi.
            </p>
        </div>
    </div>
@endsection
