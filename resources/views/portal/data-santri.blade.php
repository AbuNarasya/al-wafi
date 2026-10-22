@extends('layouts.portal')

@section('title', __('Perbaharui Data Santri'))
@section('kembali', route('portal.data', ['tab' => 'santri']))

@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
    $isian = [
        'Sekolah asal' => [
            'asal_sekolah' => ['Nama sekolah asal', 'text'],
            'alamat_sekolah_asal' => ['Alamat sekolah asal', 'textarea'],
            'kepala_sekolah_asal' => ['Nama kepala sekolah', 'text'],
            'cp_kepala_sekolah_asal' => ['Kontak kepala sekolah', 'tel'],
            'wali_kelas_asal' => ['Nama wali kelas', 'text'],
            'cp_wali_kelas_asal' => ['Kontak wali kelas', 'tel'],
        ],
    ];
@endphp

@section('content')
    <p class="mb-3 text-sm text-slate-500">{!! __('Perubahan langsung tersimpan dan bagian kesantrian diberi tahu. Isian bertanda :bintang wajib diisi.', ['bintang' => '<span class="text-red-500">*</span>']) !!}</p>
    <x-peringatan-kk portal class="mb-4" />

    <form method="POST" action="{{ route('portal.data.santri', $santri->id) }}" class="space-y-5"
          data-confirm="{{ __('Simpan perubahan data :nama?', ['nama' => $santri->nama]) }}">
        @csrf
        @method('PUT')

        <section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Identitas') }}</h2>
            {{-- Nama dikunci: hanya admin pesantren yang boleh mengubahnya (keputusan user 14 Okt 2026). --}}
            <div>
                <p class="{{ $kelasLabel }}">{{ __('Nama lengkap') }}</p>
                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-base font-semibold text-slate-900">{{ $santri->nama }}</div>
                <p class="mt-1 text-xs text-slate-500">{{ __('Nama hanya bisa diubah oleh admin pesantren. Bila keliru, hubungi bagian kesantrian dengan membawa Kartu Keluarga.') }}</p>
            </div>
            <div>
                <label class="{{ $kelasLabel }}">{{ __('Jenis kelamin') }} <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Services\PortalWali\UbahDataWali::JENIS_KELAMIN as $kode => $teks)
                        <label class="flex cursor-pointer items-center justify-center rounded-2xl border border-slate-300 bg-white py-2.5 text-sm has-[:checked]:border-brand has-[:checked]:bg-blue-50 has-[:checked]:text-brand">
                            <input type="radio" name="jenis_kelamin" value="{{ $kode }}" required @checked(old('jenis_kelamin', $santri->jenis_kelamin) === $kode) class="sr-only">{{ __($teks) }}
                        </label>
                    @endforeach
                </div>
                @error('jenis_kelamin')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="tempat_lahir" class="{{ $kelasLabel }}">{{ __('Tempat lahir') }}</label>
                    <input id="tempat_lahir" name="tempat_lahir" value="{{ old('tempat_lahir', $santri->tempat_lahir) }}" maxlength="255" class="{{ $kelasInput }}">
                    @error('tempat_lahir')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tanggal_lahir" class="{{ $kelasLabel }}">{{ __('Tanggal lahir') }}</label>
                    <input id="tanggal_lahir" type="date" name="tanggal_lahir" max="{{ now()->subDay()->format('Y-m-d') }}"
                           value="{{ old('tanggal_lahir', $santri->tanggal_lahir?->format('Y-m-d')) }}" class="{{ $kelasInput }}">
                    @error('tanggal_lahir')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <label for="nisn" class="{{ $kelasLabel }}">{{ __('NISN') }}</label>
                <input id="nisn" name="nisn" inputmode="numeric" data-bukan-rupiah value="{{ old('nisn', $santri->nisn) }}" maxlength="20" class="{{ $kelasInput }}">
                <p class="mt-1 text-xs text-slate-500">{{ __('Nomor Induk Siswa Nasional, 10 angka — tertera di rapor / kartu NISN.') }}</p>
                @error('nisn')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </section>

        @foreach ($isian as $judul => $kolom)
            <section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __($judul) }}</h2>
                @foreach ($kolom as $nama => [$label, $jenis])
                    <div>
                        <label for="{{ $nama }}" class="{{ $kelasLabel }}">{{ __($label) }}</label>
                        @if ($jenis === 'textarea')
                            <textarea id="{{ $nama }}" name="{{ $nama }}" rows="2" maxlength="1000" class="{{ $kelasInput }}">{{ old($nama, $santri->{$nama}) }}</textarea>
                        @else
                            <input id="{{ $nama }}" name="{{ $nama }}" value="{{ old($nama, $santri->{$nama}) }}" maxlength="255"
                                   @if ($jenis === 'tel') inputmode="tel" @endif class="{{ $kelasInput }}">
                        @endif
                        @error($nama)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </section>
        @endforeach

        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Simpan perubahan') }}</button>
        <a href="{{ route('portal.data', ['tab' => 'santri']) }}" class="block text-center text-sm font-semibold text-slate-500">{{ __('Batal') }}</a>
    </form>
@endsection
