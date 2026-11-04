@extends('layouts.portal')

@section('title', __('Ajukan izin'))
@section('kembali', route('portal.perizinan'))

@section('content')
    @php
        $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
        $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
        $wajib = $tipe->where('wajib_keperluan', true)->pluck('kode')->values();
        $harian = $tipe->where('pulang', false)->pluck('kode')->values();
        $batas = $tipe->mapWithKeys(fn ($t) => [$t->kode => $t->batas_hari]);
        $otomatis = $tipe->where('persetujuan', 'otomatis')->pluck('kode')->values();
    @endphp
    <h1 class="mb-1 mt-2 text-xl font-bold text-slate-900">{{ __('Ajukan izin') }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ __('Izin diputuskan pesantren. Anda dikabari lewat portal & HP setiap ada perubahan.') }}</p>

    <form method="POST" action="{{ route('portal.perizinan.kirim') }}" enctype="multipart/form-data" data-no-confirm class="space-y-4"
          x-data="{ tipe: @js(old('kode_tipe', '')), wajib: @js($wajib), harian: @js($harian), batas: @js($batas), otomatis: @js($otomatis) }">
        @csrf
        <section class="space-y-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div>
                <label for="id_santri" class="{{ $kelasLabel }}">{{ __('Anak') }} <span class="text-red-500">*</span></label>
                <select id="id_santri" name="id_santri" required class="{{ $kelasInput }}">
                    @foreach ($anak as $a)<option value="{{ $a->id }}" @selected((int) old('id_santri', $pilih) === $a->id)>{{ $a->nama }}</option>@endforeach
                </select>
            </div>
            <div>
                <p class="{{ $kelasLabel }}">{{ __('Jenis izin') }} <span class="text-red-500">*</span></p>
                <div class="space-y-2">
                    @foreach ($tipe as $t)
                        <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-300 px-4 py-3 text-sm has-[:checked]:border-brand has-[:checked]:bg-blue-50">
                            <input type="radio" name="kode_tipe" value="{{ $t->kode }}" x-model="tipe" required class="text-brand focus:ring-brand">
                            <span>{{ __($t->nama) }}</span>
                        </label>
                    @endforeach
                </div>
                <p x-show="tipe && batas[tipe] !== null && batas[tipe] !== undefined" x-cloak class="mt-1 text-xs text-amber-700">{{ __('Diajukan paling lambat') }} H-<span x-text="batas[tipe]"></span> {{ __('sebelum tanggal keluar.') }}</p>
                <p x-show="otomatis.includes(tipe)" x-cloak class="mt-1 text-xs text-emerald-700">{{ __('Izin ini langsung disetujui setelah dikirim.') }}</p>
            </div>
            <div>
                <label for="keperluan" class="{{ $kelasLabel }}">{{ __('Keperluan') }} <span x-show="wajib.includes(tipe)" class="text-red-500">*</span>
                    <span x-show="! wajib.includes(tipe)" class="text-xs font-normal text-slate-400">({{ __('tidak wajib') }})</span></label>
                <textarea id="keperluan" name="keperluan" rows="2" maxlength="1000" :required="wajib.includes(tipe)" class="{{ $kelasInput }}">{{ old('keperluan') }}</textarea>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="rencana_keluar" class="{{ $kelasLabel }}">{{ __('Waktu keluar') }} <span class="text-red-500">*</span></label>
                    <input id="rencana_keluar" type="datetime-local" name="rencana_keluar" required value="{{ old('rencana_keluar') }}" class="{{ $kelasInput }}"></div>
                <div><label for="rencana_kembali" class="{{ $kelasLabel }}">{{ __('Waktu kembali') }} <span class="text-red-500">*</span></label>
                    <input id="rencana_kembali" type="datetime-local" name="rencana_kembali" required value="{{ old('rencana_kembali') }}" class="{{ $kelasInput }}"></div>
            </div>
            <p x-show="harian.includes(tipe)" x-cloak class="text-xs text-amber-700">{{ __('Izin keluar lingkungan ma\'had: kembali di hari yang sama.') }}</p>
        </section>

        @include('portal._penjemput-isian')

        <section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <label class="block text-sm text-slate-700">{{ __('Lampiran (surat dokter, jadwal keberangkatan, dll.)') }} <span class="text-xs text-slate-400">({{ __('tidak wajib') }}, {{ \App\Support\Unggahan::maksLabel() }})</span>
                <input type="file" name="lampiran" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm"></label>
        </section>

        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim pengajuan') }}</button>
    </form>
@endsection
