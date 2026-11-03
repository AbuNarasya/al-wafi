@extends('layouts.portal')

@section('title', __('Perizinan'))
@section('kembali', route('portal.beranda'))

@section('content')
    @php $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('j M Y, H:i'); @endphp
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-teal-700 text-white shadow-sm"><x-portal.ikon nama="keluar" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Perizinan') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ $pilih?->nama ?? __('Belum ada anak berstatus santri aktif.') }}</p>
        </div>
    </div>

    @if ($anak->count() > 1)
        <div class="mb-4 flex gap-2 overflow-x-auto text-sm">
            @foreach ($anak as $a)
                <a href="{{ route('portal.perizinan', ['anak' => $a->id]) }}" @class(['shrink-0 rounded-full px-3 py-1.5', 'bg-brand text-white' => $pilih?->id === $a->id, 'bg-white text-slate-600 ring-1 ring-slate-200' => $pilih?->id !== $a->id])>{{ \Illuminate\Support\Str::of($a->nama)->before(' ')->title() }}</a>
            @endforeach
        </div>
    @endif

    @if ($pilih)
        <a href="{{ route('portal.perizinan.ajukan', ['anak' => $pilih->id]) }}" class="mb-5 block rounded-2xl bg-brand py-3 text-center text-sm font-bold text-white">{{ __('Ajukan izin') }}</a>

        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Riwayat izin') }}</h2>
        <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($daftar as $z)
                <a href="{{ route('portal.perizinan.lihat', $z->id) }}" class="flex items-start justify-between gap-2 px-4 py-3 text-sm hover:bg-slate-50">
                    <span class="min-w-0"><span class="block font-medium text-slate-900">{{ __($z->tipe?->nama) }}</span>
                        <span class="block text-xs text-slate-500">{{ $jam($z->rencana_keluar) }} – {{ $jam($z->rencana_kembali) }}</span></span>
                    @include('portal._status-izin', ['z' => $z])
                </a>
            @empty
                <p class="p-4 text-center text-sm text-slate-500">{{ __('Belum ada pengajuan izin.') }}</p>
            @endforelse
        </div>
    @endif
@endsection
