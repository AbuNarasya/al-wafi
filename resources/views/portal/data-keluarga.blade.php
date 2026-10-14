@extends('layouts.portal')

@section('title', 'Perbaharui Data Wali')
@section('kembali', route('portal.data', ['tab' => 'wali']))

@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
@endphp

@section('content')
    <p class="mb-3 text-sm text-slate-500">Perubahan langsung tersimpan dan bagian kesantrian diberi tahu. Isian bertanda <span class="text-red-500">*</span> wajib diisi.</p>
    <x-peringatan-kk portal class="mb-4" />

    <form method="POST" action="{{ route('portal.data.keluarga') }}" class="space-y-5" data-confirm="Simpan perubahan data keluarga?">
        @csrf
        @method('PUT')

        <section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>Keluarga</h2>
            <div>
                <label for="nik" class="{{ $kelasLabel }}">NIK kepala keluarga</label>
                <input id="nik" name="nik" inputmode="numeric" data-bukan-rupiah maxlength="16" value="{{ old('nik', $wali->nik) }}" class="{{ $kelasInput }}">
                <p class="mt-1 text-xs text-slate-500">16 angka, sesuai Kartu Keluarga / KTP.</p>
                @error('nik')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="alamat" class="{{ $kelasLabel }}">Alamat rumah</label>
                <textarea id="alamat" name="alamat" rows="3" maxlength="1000" class="{{ $kelasInput }}">{{ old('alamat', $wali->alamat) }}</textarea>
                @error('alamat')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </section>

        @foreach (\App\Models\Wali::PERAN as $p => $label)
            @php
                $utama = $p === $wali->kontak_utama;
                $kerjaTersimpan = (string) $wali->{'pekerjaan_'.$p};
                $diDaftar = in_array($kerjaTersimpan, \App\Models\Wali::PEKERJAAN, true);
                $pilihanKerja = old('pekerjaan_'.$p, $kerjaTersimpan === '' ? '' : ($diDaftar ? $kerjaTersimpan : \App\Models\Wali::PEKERJAAN_LAIN));
                $kerjaLain = old('pekerjaan_lain_'.$p, $diDaftar ? '' : $kerjaTersimpan);
                $bukaAwal = $p !== 'wali' || filled($wali->nama_wali) || $peranSaya === 'wali' || filled(old('nama_wali'));
            @endphp
            <section class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" x-data="{ buka: @js($bukaAwal), kerja: @js($pilihanKerja) }">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800">
                        <span class="h-4 w-1 rounded-full bg-brand"></span>Data {{ $label }}
                        @if ($p === $peranSaya)<span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-brand">Anda</span>@endif
                        @if ($utama)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">kontak utama</span>@endif
                    </h2>
                    @if (! $bukaAwal)
                        <button type="button" @click="buka = ! buka" class="text-xs font-semibold text-brand" x-text="buka ? 'Tutup' : 'Isi bila bukan orang tua'"></button>
                    @endif
                </div>

                <div x-show="buka" @if (! $bukaAwal) x-cloak @endif class="mt-3 space-y-3">
                    <div>
                        <label for="nama_{{ $p }}" class="{{ $kelasLabel }}">Nama lengkap @if ($utama)<span class="text-red-500">*</span>@endif</label>
                        <input id="nama_{{ $p }}" name="nama_{{ $p }}" value="{{ old('nama_'.$p, $wali->{'nama_'.$p}) }}" maxlength="255" @required($utama) class="{{ $kelasInput }}">
                        @error('nama_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="telepon_{{ $p }}" class="{{ $kelasLabel }}">Nomor HP / WhatsApp @if ($utama)<span class="text-red-500">*</span>@endif</label>
                        <input id="telepon_{{ $p }}" name="telepon_{{ $p }}" inputmode="tel" placeholder="0812 3456 7890"
                               value="{{ old('telepon_'.$p, $wali->{'telepon_'.$p}) }}" @required($utama) class="{{ $kelasInput }}">
                        @error('telepon_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="pekerjaan_{{ $p }}" class="{{ $kelasLabel }}">Pekerjaan</label>
                        <select id="pekerjaan_{{ $p }}" name="pekerjaan_{{ $p }}" x-model="kerja" class="{{ $kelasInput }}">
                            <option value="">— pilih —</option>
                            @foreach (\App\Models\Wali::PEKERJAAN as $kerja)
                                <option value="{{ $kerja }}">{{ $kerja }}</option>
                            @endforeach
                            <option value="{{ \App\Models\Wali::PEKERJAAN_LAIN }}">Lainnya…</option>
                        </select>
                        <input name="pekerjaan_lain_{{ $p }}" value="{{ $kerjaLain }}" placeholder="Sebutkan pekerjaannya" maxlength="100"
                               x-show="kerja === @js(\App\Models\Wali::PEKERJAAN_LAIN)" x-cloak
                               :required="kerja === @js(\App\Models\Wali::PEKERJAAN_LAIN)" class="mt-2 {{ $kelasInput }}">
                        @error('pekerjaan_lain_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="pendapatan_{{ $p }}" class="{{ $kelasLabel }}">Rentang pendapatan per bulan</label>
                        <select id="pendapatan_{{ $p }}" name="pendapatan_{{ $p }}" class="{{ $kelasInput }}">
                            <option value="">— pilih —</option>
                            @foreach (\App\Models\Wali::PENDAPATAN as $kode => $teks)
                                <option value="{{ $kode }}" @selected(old('pendapatan_'.$p, $wali->{'pendapatan_'.$p}) === $kode)>{{ $teks }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="text-xs text-slate-500">Email {{ strtolower($label) }}: <b class="break-all text-slate-700">{{ $wali->{'email_'.$p} ?: 'belum diisi' }}</b> —
                        <a href="{{ route('portal.data.email', $p) }}" class="font-semibold text-brand">{{ filled($wali->{'email_'.$p}) ? 'ganti' : 'isi' }} email</a></p>
                </div>
            </section>
        @endforeach

        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">Simpan perubahan</button>
        <a href="{{ route('portal.data', ['tab' => 'wali']) }}" class="block text-center text-sm font-semibold text-slate-500">Batal</a>
    </form>
@endsection
