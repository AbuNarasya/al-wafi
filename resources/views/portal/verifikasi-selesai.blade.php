@extends('layouts.portal')

@section('title', 'Pembaharuan Data Selesai')

@section('content')
    <div class="mb-5 mt-4 text-center">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-600 text-white">
            <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </span>
        <h1 class="mt-4 text-2xl font-bold text-slate-900">Syukron, pembaharuan data telah selesai</h1>
        <p class="mt-1 text-sm text-slate-500">Jazakumullah khairan atas waktunya.</p>
    </div>

    <section class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Santri</p>
        <ul class="mb-3 divide-y divide-slate-100">
            @foreach ($r['anak'] as $a)
                <li class="py-2 text-sm">
                    <span class="block font-semibold text-slate-900">{{ $a['nama'] }}</span>
                    <span class="text-slate-500">{{ $a['jenjang'] }}{{ $a['nis'] ? ' · NIS '.$a['nis'] : '' }}</span>
                </li>
            @endforeach
        </ul>

        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Penanggung jawab</p>
        <dl class="divide-y divide-slate-100">
            @foreach (['Nama ('.$r['peran'].')' => $r['nama'], 'Email' => $r['email'], 'No. HP' => $r['telepon']] as $label => $nilai)
                <div class="flex gap-3 py-2 text-sm">
                    <dt class="w-28 shrink-0 text-slate-500">{{ $label }}</dt>
                    <dd class="min-w-0 flex-1 break-all font-medium text-slate-900">{{ $nilai ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <p class="mt-3 px-2 text-center text-xs text-slate-500">Email di atas kini dipakai untuk masuk Portal Wali.</p>

    {{-- Tab yang dibuka dari WhatsApp sering tak boleh ditutup lewat skrip — bila
         window.close() ditolak, pesan pengganti yang muncul. --}}
    <div x-data="{ ditolak: false }" class="mt-5">
        <button type="button" @click="window.close(); setTimeout(() => ditolak = true, 400)"
                class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Tutup</button>
        <p x-show="ditolak" x-cloak class="mt-3 text-center text-sm text-slate-600">Silakan tutup halaman ini secara manual.</p>
    </div>
@endsection
