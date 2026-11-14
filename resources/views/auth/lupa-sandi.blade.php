<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Kata Sandi — {{ \App\Support\Instans::nama() }}</title>

    <x-kepala-pwa />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-brand-dark p-4">
@include('partials.pita-uji')
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <x-logo class="mx-auto mb-3 h-20 w-20 rounded-2xl text-3xl" />
            <h1 class="text-xl font-semibold text-white">Lupa Kata Sandi</h1>
            <p class="text-sm text-slate-300">{{ \App\Support\Instans::nama() }}</p>
        </div>

        <div class="space-y-4 rounded-xl bg-white p-6 shadow-xl">
            @if (session('terkirim'))
                <div class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    Tautan reset sudah dikirim ke <strong>{{ session('terkirim') }}</strong>. Buka email itu dan ketuk
                    tautannya dalam {{ \App\Services\Modules\ResetSandiStafService::MENIT }} menit. Tidak ada di Kotak Masuk? Periksa folder Spam.
                </div>
            @endif

            <form method="POST" action="{{ route('sandi_staf.kirim') }}" data-no-confirm class="space-y-4">
                @csrf
                <p class="text-sm text-gray-600">Isi username atau email akun Anda. Kami kirim tautan untuk membuat kata sandi baru ke email yang tercatat di akun.</p>

                <div>
                    <label for="akun" class="mb-1 block text-sm font-medium text-gray-700">Username atau email</label>
                    <input id="akun" name="akun" type="text" value="{{ old('akun') }}" autofocus required autocomplete="username"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                    @error('akun')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                    Kirim tautan reset
                </button>
            </form>

            <a href="{{ route('login') }}" class="block text-center text-sm text-brand hover:underline">&larr; Kembali ke halaman masuk</a>
        </div>
    </div>
</body>
</html>
