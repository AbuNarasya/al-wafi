<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kasir') — Wafi Mart</title>

    <x-kepala-pwa />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
  Layout KASIR — layar penuh tanpa menu samping ERP. Kasir bekerja berjam-jam di satu
  layar dengan pemindai barcode; menu ERP hanya mengambil tempat dan mengundang salah klik.
  Kepala layar selalu menyebut toko, shift, dan kasirnya: struk & uang laci melekat ke sana.
--}}
<body class="h-full bg-slate-100 text-gray-800 antialiased">
<div class="flex min-h-full flex-col">
    <header class="flex flex-wrap items-center justify-between gap-2 bg-brand-dark px-4 py-2 text-white">
        <div class="flex items-center gap-3">
            <x-logo class="h-8 w-8 rounded text-base" />
            <div class="leading-tight">
                <div class="font-semibold">@yield('kepala', 'Kasir Wafi Mart')</div>
                <div class="text-xs text-white/70">@yield('subkepala')</div>
            </div>
        </div>
        <div class="flex items-center gap-3 text-sm">
            @yield('aksi')
            <span class="hidden text-white/70 sm:inline">{{ auth()->user()->nama }}</span>
            <a href="{{ route('dashboard') }}" class="rounded border border-white/30 px-2 py-1 text-xs hover:bg-white/10">Keluar ke ERP</a>
        </div>
    </header>

    @if (session('status'))
        <div class="bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <main class="flex-1 p-3 sm:p-4">
        @yield('content')
    </main>
</div>
</body>
</html>
