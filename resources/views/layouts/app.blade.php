<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — AL Wafi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-100 text-gray-800 antialiased">
<div x-data="{ sidebar: true }" class="min-h-full">
    {{-- Sidebar --}}
    <aside x-show="sidebar" x-cloak
           class="fixed inset-y-0 left-0 z-30 w-64 overflow-y-auto bg-brand-dark text-slate-200 [scrollbar-gutter:stable]">
        <div class="flex h-14 items-center gap-2 border-b border-white/10 px-4">
            <div class="flex h-8 w-8 items-center justify-center rounded bg-accent font-bold text-brand-dark">A</div>
            <span class="font-semibold tracking-tight text-white">AL Wafi</span>
        </div>
        @php $aktifUrl = \App\Support\Navigation::activeUrl(); @endphp
        <nav class="p-2 text-sm" x-data="{ open: @js(\App\Support\Navigation::activeGroup()), openSub: @js(\App\Support\Navigation::activeSub()) }">
            @foreach (\App\Support\Navigation::tree() as $grup)
                @if ($grup['group'] === '')
                    {{-- Grup tanpa header (Dashboard): tampil langsung. --}}
                    @foreach ($grup['subs'] as $sub)
                        @foreach ($sub['items'] as $item)
                            <a href="{{ url($item['url']) }}"
                               class="mb-1 flex items-center justify-between gap-2 rounded px-2 py-2 text-[12px] font-medium {{ $item['url'] === $aktifUrl ? 'bg-accent text-brand-dark font-semibold' : 'text-slate-200 hover:bg-white/10 hover:text-white' }}">
                                <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                <x-badge-tugas :url="$item['url']" />
                            </a>
                        @endforeach
                    @endforeach
                @else
                    {{-- Jumlah pekerjaan di dalam grup: penanda tetap terlihat walau
                         grupnya sedang tertutup, jadi tak ada tugas yang tersembunyi. --}}
                    @php $tugasGrup = collect($grup['subs'])->flatMap(fn ($s) => $s['items'])
                        ->sum(fn ($i) => \App\Support\TugasSaya::untukUrl($i['url'])); @endphp
                    <div class="mt-1">
                        <button type="button" @click="open = (open === @js($grup['group']) ? null : @js($grup['group']))"
                                class="flex w-full items-center justify-between gap-2 rounded px-2 py-2 text-[14px] font-bold uppercase text-slate-100 hover:bg-white/10">
                            <span class="min-w-0 truncate">{{ $grup['group'] }}</span>
                            @if ($tugasGrup > 0)
                                <span class="ml-auto flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white"
                                      x-show="open !== @js($grup['group'])">{{ $tugasGrup > 99 ? '99+' : $tugasGrup }}</span>
                            @endif
                            <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform" :class="open === @js($grup['group']) ? 'rotate-90' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <div x-show="open === @js($grup['group'])" x-cloak class="mt-0.5 space-y-0.5 pb-1">
                            @foreach ($grup['subs'] as $sub)
                                @if ($sub['sub'])
                                    {{-- Sub-grup collapsible: tombol label (menjorok 1 tingkat) + item (2 tingkat). --}}
                                    <div>
                                        @php $tugasSub = collect($sub['items'])->sum(fn ($i) => \App\Support\TugasSaya::untukUrl($i['url'])); @endphp
                                        <button type="button" @click="openSub = (openSub === @js($sub['sub']) ? null : @js($sub['sub']))"
                                                class="mt-1 flex w-full items-center justify-between gap-2 rounded py-1.5 pl-4 pr-2 text-[12px] font-medium normal-case tracking-normal text-slate-500 hover:bg-white/10 hover:text-slate-300">
                                            <span class="min-w-0 truncate">{{ $sub['sub'] }}</span>
                                            @if ($tugasSub > 0)
                                                <span class="ml-auto flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white"
                                                      x-show="openSub !== @js($sub['sub'])">{{ $tugasSub > 99 ? '99+' : $tugasSub }}</span>
                                            @endif
                                            <svg class="h-3 w-3 shrink-0 transition-transform" :class="openSub === @js($sub['sub']) ? 'rotate-90' : ''"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        </button>
                                        <div x-show="openSub === @js($sub['sub'])" x-cloak class="space-y-0.5">
                                            @foreach ($sub['items'] as $item)
                                                <a href="{{ url($item['url']) }}"
                                                   class="ml-4 flex items-center justify-between gap-2 rounded border-l border-white/15 py-1.5 pl-4 pr-3 text-[12px] {{ $item['url'] === $aktifUrl ? 'border-accent bg-accent text-brand-dark font-semibold' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                                    <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                                    <x-badge-tugas :url="$item['url']" />
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    {{-- Item langsung di bawah grup: menjorok 1 tingkat. --}}
                                    @foreach ($sub['items'] as $item)
                                        <a href="{{ url($item['url']) }}"
                                           class="flex items-center justify-between gap-2 rounded py-1.5 pl-4 pr-3 text-[12px] {{ $item['url'] === $aktifUrl ? 'bg-accent text-brand-dark font-semibold' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                            <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                            <x-badge-tugas :url="$item['url']" />
                                        </a>
                                    @endforeach
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>
    </aside>

    {{-- Konten --}}
    <div :class="sidebar ? 'lg:pl-64' : ''" class="flex min-h-full flex-col">
        <header class="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-gray-200 bg-white px-4 shadow-sm">
            <div class="flex items-center gap-3">
                <button @click="sidebar = !sidebar" class="rounded p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Toggle sidebar">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-base font-semibold text-gray-900">@yield('title', 'Dashboard')</h1>
                {{-- Penanda modul: muncul otomatis di halaman yang punya pekerjaan
                     menunggu, tanpa perlu menyunting tiap view. --}}
                @php $tugasHalaman = \App\Support\TugasSaya::untukUrl(\App\Support\Navigation::activeUrl()); @endphp
                @if ($tugasHalaman > 0)
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                        {{ $tugasHalaman }} {{ \App\Support\TugasSaya::labelUrl(\App\Support\Navigation::activeUrl()) }}
                    </span>
                @endif
            </div>
            <div class="flex items-center gap-1">
            <x-lonceng />
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-100">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold">
                        {{ strtoupper(substr(auth()->user()->nama, 0, 1)) }}
                    </div>
                    <span class="hidden sm:inline">{{ auth()->user()->nama }}</span>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false"
                     class="absolute right-0 mt-2 w-48 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                    <div class="border-b border-gray-100 px-3 py-2 text-xs text-gray-500">
                        {{ auth()->user()->jabatan ?? '—' }}
                        @if (auth()->user()->is_admin)
                            <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">ADMIN</span>
                        @endif
                    </div>
                    <a href="{{ route('profil.index') }}" class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Profil &amp; Kata Sandi
                    </a>
                    <form method="POST" action="{{ route('logout') }}" data-no-confirm>
                        @csrf
                        <button type="submit" class="block w-full px-3 py-2 text-left text-sm text-red-600 hover:bg-gray-50">Keluar</button>
                    </form>
                </div>
            </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @if (session('status'))
                <div x-data="{ show: true }" x-show="show"
                     class="mb-4 flex items-center justify-between rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                    <span>{{ session('status') }}</span>
                    <button @click="show = false" class="text-emerald-600 hover:text-emerald-800">&times;</button>
                </div>
            @endif
            @if (session('error'))
                <div x-data="{ show: true }" x-show="show"
                     class="mb-4 flex items-center justify-between rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
                    <span>{{ session('error') }}</span>
                    <button @click="show = false" class="text-red-600 hover:text-red-800">&times;</button>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
