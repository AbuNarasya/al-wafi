@extends('layouts.portal')

@section('title', __('Berkas Pendaftaran'))
@section('kembali', route('portal.anak', $santri->id))

@php
    $perKelompok = collect($daftar)->groupBy('kelompok');
    $maksByte = \App\Support\Unggahan::MAKS_KB * 1024;
    $warna = [
        'belum' => 'bg-slate-100 text-slate-600', 'menunggu' => 'bg-amber-100 text-amber-800',
        'diterima' => 'bg-emerald-100 text-emerald-800', 'perlu_diganti' => 'bg-red-100 text-red-700',
    ];
    $registrasi = collect($daftar)->where('kelompok', 'registrasi');
    $kurang = $registrasi->whereIn('status', ['belum', 'perlu_diganti'])->count();
@endphp

@section('content')
    <div class="mb-4">
        <h1 class="text-xl font-bold text-slate-900">{{ $santri->nama }}</h1>
        <p class="text-sm text-slate-500">{{ __('Unggah foto atau PDF berkas pendaftaran (maks :ukuran per berkas — foto dikecilkan otomatis).', ['ukuran' => \App\Support\Unggahan::maksLabel()]) }}
            {{ __('Berkas juga boleh diserahkan langsung ke panitia.') }}</p>
    </div>

    {{-- Pengingat, bukan penghalang: proses PSB tetap berjalan walau berkas belum lengkap. --}}
    @if ($kurang > 0)
        <div class="mb-4 rounded-3xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
            {!! __('<b>:kurang dari :total berkas registrasi</b> belum diterima pesantren. Mohon dilengkapi.', ['kurang' => e($kurang), 'total' => e($registrasi->count())]) !!}
        </div>
    @elseif ($registrasi->isNotEmpty())
        <div class="mb-4 rounded-3xl bg-emerald-50 p-4 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ __('Berkas registrasi sudah lengkap diterima. Jazakumullah khairan.') }}</div>
    @endif

    @foreach (['registrasi', 'pindahan', 'pasca_lulus'] as $kelompok)
        @php $isi = $perKelompok[$kelompok] ?? collect(); @endphp
        @continue ($isi->isEmpty())
        <section class="mb-5">
            <h2 class="mb-1 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __(\App\Services\Ppsb\DokumenPolicy::LABEL_KELOMPOK[$kelompok]) }}</h2>
            @if ($kelompok === 'pindahan')
                <p class="mb-2 text-xs text-slate-500">{{ __('Hanya untuk santri pindahan dari sekolah lain.') }}</p>
            @endif
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @foreach ($isi as $b)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-slate-900">{{ __($b['label']) }}</p>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$b['status']] }}">{{ __(\App\Services\Modules\DokumenSantriService::STATUS[$b['status']]) }}</span>
                        </div>
                        @if ($b['status'] === 'perlu_diganti' && $b['catatan'])
                            <p class="mt-1 text-sm text-red-700">{{ __('Catatan panitia: :catatan', ['catatan' => $b['catatan']]) }}</p>
                        @elseif ($b['status'] === 'diterima' && $b['berkas']->isEmpty())
                            <p class="mt-1 text-xs text-slate-500">{{ __('Diterima pesantren (diserahkan langsung).') }}</p>
                        @endif

                        @foreach ($b['berkas'] as $d)
                            <div class="mt-2 flex items-center gap-2 text-sm">
                                <a href="{{ route('portal.berkas.lihat', $d->id) }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-brand underline">{{ $d->nama_asli }}</a>
                                <span class="shrink-0 text-xs text-slate-400">{{ $d->created_at?->format('d/m/Y') }}</span>
                                @if ($bolehUnggah && $b['status'] !== 'diterima' && $d->diunggah_wali)
                                    <form method="POST" action="{{ route('portal.berkas.hapus', $d->id) }}" data-confirm="{{ __('Hapus berkas :nama?', ['nama' => $d->nama_asli]) }}">
                                        @csrf @method('DELETE')
                                        <button class="shrink-0 text-xs font-semibold text-red-700">{{ __('Hapus') }}</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach

                        {{-- Unggah: pilih berkas → dikecilkan → langsung terkirim. --}}
                        @if ($bolehUnggah && $b['status'] !== 'diterima')
                            <form method="POST" action="{{ route('portal.berkas', $santri->id) }}" enctype="multipart/form-data" data-no-confirm
                                  x-data="unggahBukti({{ $maksByte }}, true)" class="mt-3">
                                @csrf
                                <input type="hidden" name="jenis" value="{{ $b['jenis'] }}">
                                @if ($b['jenis'] === 'lainnya')
                                    <input name="keterangan" required maxlength="255" placeholder="{{ __('Berkas apa ini?') }}" class="mb-2 w-full rounded-2xl border border-slate-300 px-4 py-2.5 text-sm">
                                @endif
                                <label class="flex cursor-pointer flex-wrap items-center justify-center gap-x-2 rounded-2xl border-2 border-dashed border-slate-300 py-2.5 text-sm font-semibold text-brand"
                                       :class="{ 'opacity-60': sibuk, 'border-brand bg-blue-50': seret }"
                                       @dragover.prevent="seret = true" @dragleave.prevent="seret = false" @drop.prevent="jatuhkan($event)">
                                    <x-portal.ikon nama="unggah" kelas="h-5 w-5" />
                                    <span x-text="sibuk ? @js(__('Mengirim…')) : (seret ? @js(__('Lepaskan berkas di sini')) : @js($b['berkas']->isEmpty() ? __('Unggah berkas') : __('Unggah berkas pengganti')))"></span>
                                    <span x-show="! sibuk && ! seret" class="hidden text-xs font-normal text-slate-500 sm:inline">{{ __('atau seret berkas ke sini') }}</span>
                                    <input type="file" name="berkas" accept="image/*,application/pdf" class="sr-only" @change="pilih($event)">
                                </label>
                                <p x-show="galat" x-text="galat" x-cloak class="mt-1 text-xs text-red-700"></p>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    @unless ($bolehUnggah)
        <p class="px-2 text-center text-xs text-slate-500">{{ __('Proses pendaftaran ini sudah berakhir — berkas tidak bisa ditambah lagi.') }}</p>
    @endunless
@endsection
