<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Kata Sandi Baru — {{ \App\Support\Instans::nama() }}</title>

    <x-kepala-pwa />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-brand-dark p-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <x-logo class="mx-auto mb-3 h-20 w-20 rounded-2xl text-3xl" />
            <h1 class="text-xl font-semibold text-white">Buat Kata Sandi Baru</h1>
            <p class="text-sm text-slate-300">{{ \App\Support\Instans::nama() }}</p>
        </div>

        <div class="space-y-4 rounded-xl bg-white p-6 shadow-xl">
            @if (! $baris)
                <div class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    Tautan ini sudah tidak berlaku — kedaluwarsa, sudah dipakai, atau sudah diganti tautan yang lebih baru.
                </div>
                <a href="{{ route('sandi_staf.lupa') }}" class="block w-full rounded-lg bg-brand px-4 py-2 text-center text-sm font-semibold text-white hover:bg-brand-dark">Minta tautan baru</a>
            @else
                <form method="POST" action="{{ route('sandi_staf.simpan', $token) }}" data-no-confirm class="space-y-4">
                    @csrf
                    <p class="text-sm text-gray-600">Akun: <strong>{{ $baris->pengguna->username }}</strong> ({{ $baris->pengguna->nama }})</p>

                    <div>
                        <label for="password_baru" class="mb-1 block text-sm font-medium text-gray-700">Kata sandi baru</label>
                        <input id="password_baru" name="password_baru" type="password" required minlength="6" autocomplete="new-password"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                        @error('password_baru')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="password_baru_confirmation" class="mb-1 block text-sm font-medium text-gray-700">Ulangi kata sandi baru</label>
                        <input id="password_baru_confirmation" name="password_baru_confirmation" type="password" required autocomplete="new-password"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                    </div>
                    <p class="text-xs text-gray-500">Minimal 6 karakter. Setelah disimpan, semua perangkat yang sedang masuk dengan akun ini dikeluarkan.</p>

                    <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                        Simpan kata sandi baru
                    </button>
                </form>
            @endif
        </div>
    </div>
</body>
</html>
