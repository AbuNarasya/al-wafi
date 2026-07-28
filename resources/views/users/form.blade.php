@extends('layouts.app')

@php $baru = ! $user->exists; @endphp

@section('title', $baru ? 'Tambah Pengguna' : 'Ubah Pengguna ' . $user->username)

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('users.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        <form method="POST" action="{{ $baru ? route('users.store') : route('users.update', $user) }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @unless ($baru) @method('PUT') @endunless

            @if (! $baru && $user->is_admin)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Pengguna ini administrator — melewati matriks hak akses. Status admin tidak dapat diubah lewat form ini.
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="username" label="Username" :value="$user->username" required />
                <x-field name="nama" label="Nama Lengkap" :value="$user->nama" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="jabatan" label="Jabatan" :value="$user->jabatan" />
                <x-field name="password" label="{{ $baru ? 'Kata Sandi' : 'Kata Sandi Baru' }}" type="password"
                         :required="$baru" hint="{{ $baru ? 'Minimal 6 karakter.' : 'Kosongkan bila tidak diubah.' }}" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_level" label="Level Otorisasi Keuangan" :value="$user->kode_level"
                         :options="$levels" required />
                <x-field name="kode_bagian" label="Bagian" :value="$user->kode_bagian" :options="$bagianList" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="peringkat_pengajuan" label="Peringkat Pengajuan" :value="$user->peringkat_pengajuan"
                         :options="$peringkatList" hint="Staff & Mudir Bagian wajib punya bagian." />
                <x-field name="status" label="Status" :value="$user->status ?? 'aktif'"
                         :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" />
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="tim_keuangan" value="0">
                <input type="checkbox" name="tim_keuangan" value="1"
                       @checked(old('tim_keuangan', $user->tim_keuangan))
                       class="rounded border-gray-300 text-brand focus:ring-brand">
                Anggota Tim Keuangan (boleh memverifikasi dokumen keuangan)
            </label>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('users.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    {{ $baru ? 'Simpan' : 'Perbarui' }}
                </button>
            </div>
        </form>
    </div>
@endsection
