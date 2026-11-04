@extends('layouts.portal')

@section('title', __('Ajukan :tipe', ['tipe' => __($j->tipe->nama)]))
@section('kembali', route('portal.perizinan_rutin'))

@section('content')
    @php
        $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
        $tgl = fn ($t) => $t?->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('D, j M Y H:i');
        $batas = $j->batasPengajuan();
    @endphp
    <h1 class="mb-1 mt-2 text-xl font-bold text-slate-900">{{ __('Ajukan :tipe', ['tipe' => __($j->tipe->nama)]) }}</h1>
    <p class="text-sm text-slate-600">{{ $tgl($j->mulai) }} → {{ $tgl($j->selesai) }}</p>
    @if ($batas)<p class="mb-4 text-xs text-amber-700">{{ __('Pengajuan ditutup :waktu', ['waktu' => $tgl($batas)]) }}</p>@else<div class="mb-4"></div>@endif

    <form method="POST" action="{{ route('portal.perizinan_rutin.kirim', $j->id) }}" enctype="multipart/form-data" data-no-confirm class="space-y-4">
        @csrf
        <section class="space-y-2 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-sm font-bold text-slate-800">{{ __('Anak') }} <span class="text-red-500">*</span></h2>
            @foreach ($calon as $s)
                <label @class(['flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm', 'cursor-pointer border-slate-300 has-[:checked]:border-brand has-[:checked]:bg-blue-50' => ! $s->hambatan_tolak, 'border-red-200 bg-red-50' => $s->hambatan_tolak])>
                    <input type="checkbox" name="anak[]" value="{{ $s->id }}" @checked(! $s->hambatan_tolak && in_array($s->id, old('anak', $calon->reject(fn ($c) => $c->hambatan_tolak)->pluck('id')->all())))
                           @disabled($s->hambatan_tolak) class="mt-0.5 rounded text-brand focus:ring-brand">
                    <span class="min-w-0"><span class="block font-medium text-slate-900">{{ \Illuminate\Support\Str::of($s->nama)->title() }}</span>
                        @if ($s->hambatan_tolak)
                            <span class="block text-xs text-red-700">{{ __('Tidak dapat diajukan: masih ada :tunggakan.', ['tunggakan' => \App\Services\Modules\TunggakanIzin::teks($s->hambatan_tolak)]) }}</span>
                        @elseif ($s->hambatan_kembali)
                            <span class="block text-xs text-amber-700">{{ __('Perhatian: masih ada :tunggakan. Santri belum dapat kembali ke pondok sebelum tunggakan diselesaikan.', ['tunggakan' => \App\Services\Modules\TunggakanIzin::teks($s->hambatan_kembali)]) }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
            @error('anak')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        </section>

        @include('portal._penjemput-isian')

        <section class="space-y-2 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <label for="keperluan" class="block text-sm font-medium text-slate-700">{{ __('Catatan') }} <span class="text-xs font-normal text-slate-400">({{ __('tidak wajib') }})</span></label>
            <textarea id="keperluan" name="keperluan" rows="2" maxlength="1000" class="{{ $kelasInput }}">{{ old('keperluan') }}</textarea>
        </section>

        @if ($j->tipe->persetujuan === 'otomatis')<p class="text-xs text-emerald-700">{{ __('Izin ini langsung disetujui setelah dikirim.') }}</p>@endif
        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim pengajuan') }}</button>
    </form>
@endsection
