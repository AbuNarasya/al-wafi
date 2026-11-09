<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kasir') — {{ $mitra->nama }}</title>

    <x-kepala-pwa />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
  Layout MITRA KANTIN — dibuka dari HP mitra. Tanpa menu ERP sama sekali: akun mitra hanya punya
  tiga layar (kasir, riwayat, laporan). Navigasi di bawah supaya terjangkau ibu jari.
--}}
<body class="h-full bg-slate-100 text-gray-800 antialiased">
<div class="mx-auto flex min-h-full max-w-xl flex-col pb-20">
    <header class="flex items-center justify-between gap-2 bg-brand-dark px-4 py-3 text-white">
        <div class="min-w-0 leading-tight">
            <div class="truncate font-semibold">{{ $mitra->nama }}</div>
            <div class="text-xs text-white/70">Mitra Wafi Mart · {{ auth()->user()->username }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}" data-no-confirm>
            @csrf
            <button class="rounded border border-white/30 px-2 py-1 text-xs hover:bg-white/10">Keluar</button>
        </form>
    </header>

    @if (session('status'))
        <div class="bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <main class="flex-1 p-3">
        @yield('content')
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)]">
        <div class="mx-auto grid max-w-xl grid-cols-4 text-center text-xs font-semibold">
            @foreach ([['mitra.kasir', 'Kasir'], ['mitra.riwayat', 'Riwayat'], ['mitra.laporan', 'Laporan'], ['mitra.saldo', 'Saldo']] as [$rute, $label])
                <a href="{{ route($rute) }}" class="py-3 {{ request()->routeIs($rute) ? 'text-brand' : 'text-gray-500' }}">{{ $label }}</a>
            @endforeach
        </div>
    </nav>
</div>
</body>
</html>
