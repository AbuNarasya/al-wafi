@extends('layouts.portal')

@section('title', __('Kabar'))

@section('content')
    <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
        @forelse ($kabar as $k)
            <div class="flex gap-3 px-4 py-3">
                <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-brand' => in_array($k->id, $baru, true), 'bg-transparent' => ! in_array($k->id, $baru, true)])></span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-slate-900">{{ $k->judul }}</p>
                    <p class="text-sm text-slate-600">{{ $k->pesan }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $k->created_at?->locale(app()->getLocale())->diffForHumans() }}</p>
                </div>
            </div>
        @empty
            <div class="p-8 text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><x-portal.ikon nama="lonceng" /></span>
                <p class="mt-2 text-sm text-slate-500">{{ __('Belum ada kabar.') }}</p>
            </div>
        @endforelse
    </div>
@endsection
