<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — AL Wafi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-brand-dark p-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-accent text-xl font-bold text-brand-dark">A</div>
            <h1 class="text-lg font-semibold text-white">AL Wafi Islamic Boarding School</h1>
            <p class="text-sm text-slate-400">Sistem Akuntansi &amp; Kesantrian</p>
        </div>

        <form method="POST" action="{{ url('/login') }}" data-no-confirm class="space-y-4 rounded-xl bg-white p-6 shadow-xl">
            @csrf

            @if ($errors->any())
                <div class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-gray-700">Username</label>
                <input id="username" name="username" type="text" value="{{ old('username') }}" autofocus required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Kata Sandi</label>
                <input id="password" name="password" type="password" required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300 text-brand focus:ring-brand">
                Ingat saya
            </label>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                Masuk
            </button>
        </form>
    </div>
</body>
</html>
