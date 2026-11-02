@extends('layouts.portal')

@section('title', __('Verifikasi Data'))

@section('content')
    @include('portal._langkah-verifikasi', ['aktif' => 3, 'lewat' => $lewat])

    <section class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Data orang tua / wali') }}</p>
            <a href="{{ route('portal.verifikasi.wali') }}" class="text-xs font-semibold text-brand">{{ __('Ubah') }}</a>
        </div>
        <ul class="mb-3 mt-1 divide-y divide-slate-100">
            @foreach (\App\Models\Wali::PERAN as $p => $l)
                @if (filled($wali->{'nama_'.$p}))
                    <li class="py-2 text-sm">
                        <span class="block font-semibold text-slate-900">{{ $wali->{'nama_'.$p} }} <span class="font-normal text-slate-500">· {{ __($l) }}</span></span>
                        <span class="block break-all text-slate-500">{{ $wali->{'telepon_'.$p} ?: '—' }} · {{ $wali->{'email_'.$p} ?: __('email belum diisi') }}</span>
                    </li>
                @endif
            @endforeach
        </ul>

        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Santri') }}</p>
            <a href="{{ route('portal.verifikasi.santri') }}" class="text-xs font-semibold text-brand">{{ __('Ubah') }}</a>
        </div>
        <ul class="mt-1 divide-y divide-slate-100">
            @forelse ($anak as $s)
                <li class="py-2 text-sm">
                    <span class="block font-semibold text-slate-900">{{ $s->nama }}</span>
                    <span class="text-slate-500">{{ $s->jenjang?->nama ?? $s->kode_jenjang }}{{ $s->nis ? ' · '.__('NIS :nis', ['nis' => $s->nis]) : '' }}</span>
                </li>
            @empty
                <li class="py-2 text-sm text-slate-500">{{ __('Tidak ada santri aktif yang tercatat. Bila ini keliru, hubungi bagian kesantrian.') }}</li>
            @endforelse
        </ul>
    </section>

    <form method="POST" action="{{ route('portal.verifikasi.selesai') }}" class="mt-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" data-no-confirm>
        @csrf
        <label class="flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="setuju" value="1" required class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand focus:ring-brand">
            <span>{!! __('Saya sudah memeriksa data keluarga dan santri di atas, dan datanya <b>sudah benar &amp; sesuai Kartu Keluarga</b>.') !!}</span>
        </label>
        @error('setuju')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

        <div class="mt-3 flex gap-2">
            <a href="{{ route('portal.verifikasi.santri') }}" class="flex-1 rounded-2xl bg-white py-3 text-center text-sm font-bold text-slate-700 ring-1 ring-slate-300"><span class="inline-block rtl:-scale-x-100">&larr;</span> {{ __('Kembali') }}</a>
            <button class="flex-[2] rounded-2xl bg-emerald-600 py-3 text-sm font-bold text-white">{{ __('Selesai — data sudah benar') }}</button>
        </div>
    </form>
@endsection
