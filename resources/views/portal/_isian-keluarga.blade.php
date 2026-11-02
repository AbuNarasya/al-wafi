{{--
    Isian data keluarga — dipakai menu Data Wali & Santri (data-keluarga) dan langkah ①
    verifikasi data (verifikasi-wali).

    $emailLangsung = true  → email diketik langsung di sini, tanpa kode (langkah verifikasi);
                             email orang yang sudah punya password hanya ditampilkan.
    $emailLangsung = false → email diganti lewat halaman tersendiri dengan kode (menu Data).
--}}
@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
    $emailLangsung ??= false;
    $punyaSandi ??= [];
@endphp

<section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Keluarga') }}</h2>
    <div>
        <label for="nik" class="{{ $kelasLabel }}">{{ __('NIK kepala keluarga') }}</label>
        <input id="nik" name="nik" inputmode="numeric" data-bukan-rupiah maxlength="16" value="{{ old('nik', $wali->nik) }}" class="{{ $kelasInput }}">
        <p class="mt-1 text-xs text-slate-500">{{ __('16 angka, sesuai Kartu Keluarga / KTP.') }}</p>
        @error('nik')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="alamat" class="{{ $kelasLabel }}">{{ __('Alamat rumah') }}</label>
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
        $saya = $p === $peranSaya;
    @endphp
    <section class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" x-data="{ buka: @js($bukaAwal), kerja: @js($pilihanKerja) }">
        <div class="flex items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800">
                <span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Data :peran', ['peran' => __($label)]) }}
                @if ($saya)<span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-brand">{{ __('Anda') }}</span>@endif
                @if ($utama)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ __('kontak utama') }}</span>@endif
            </h2>
            @if (! $bukaAwal)
                <button type="button" @click="buka = ! buka" class="text-xs font-semibold text-brand" x-text="buka ? @js(__('Tutup')) : @js(__('Isi bila bukan orang tua'))"></button>
            @endif
        </div>

        <div x-show="buka" @if (! $bukaAwal) x-cloak @endif class="mt-3 space-y-3">
            <div>
                <label for="nama_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Nama lengkap') }} @if ($utama)<span class="text-red-500">*</span>@endif</label>
                <input id="nama_{{ $p }}" name="nama_{{ $p }}" value="{{ old('nama_'.$p, $wali->{'nama_'.$p}) }}" maxlength="255" @required($utama) class="{{ $kelasInput }}">
                @error('nama_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="telepon_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Nomor HP / WhatsApp') }} @if ($utama)<span class="text-red-500">*</span>@endif</label>
                <input id="telepon_{{ $p }}" name="telepon_{{ $p }}" inputmode="tel" placeholder="0812 3456 7890"
                       value="{{ old('telepon_'.$p, $wali->{'telepon_'.$p}) }}" @required($utama) class="{{ $kelasInput }}">
                @error('telepon_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            @if ($emailLangsung)
                <div>
                    <label for="email_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Email') }} @if ($saya && empty($punyaSandi[$p]))<span class="text-red-500">*</span>@endif</label>
                    @if (! empty($punyaSandi[$p]))
                        {{-- Sudah pernah masuk portal: emailnya hanya bisa diganti orangnya sendiri. --}}
                        <div class="break-all rounded-2xl bg-slate-50 px-4 py-3 text-base font-semibold text-slate-900">{{ $wali->{'email_'.$p} }}</div>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Sudah dipakai masuk Portal Wali. Untuk mengganti, :peran masuk sendiri lalu buka menu Data Wali & Santri.', ['peran' => mb_strtolower(__($label))]) }}</p>
                    @else
                        <input id="email_{{ $p }}" name="email_{{ $p }}" type="email" inputmode="email" autocomplete="{{ $saya ? 'email' : 'off' }}" maxlength="255"
                               value="{{ old('email_'.$p, $wali->{'email_'.$p}) }}" placeholder="{{ __('nama@email.com') }}" @required($saya) class="{{ $kelasInput }}">
                        <p class="mt-1 text-xs text-slate-500">{{ $saya
                            ? __('Dipakai untuk masuk Portal Wali. Pastikan ejaannya benar — kode masuk pertama kali dikirim ke email ini.')
                            : __('Boleh dikosongkan. Pastikan ejaannya benar — :peran memakai email ini untuk masuk Portal Wali.', ['peran' => mb_strtolower(__($label))]) }}</p>
                    @endif
                    @error('email_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
            <div>
                <label for="pekerjaan_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Pekerjaan') }}</label>
                <select id="pekerjaan_{{ $p }}" name="pekerjaan_{{ $p }}" x-model="kerja" class="{{ $kelasInput }}">
                    <option value="">{{ __('— pilih —') }}</option>
                    @foreach (\App\Models\Wali::PEKERJAAN as $kerja)
                        <option value="{{ $kerja }}">{{ __($kerja) }}</option>
                    @endforeach
                    <option value="{{ \App\Models\Wali::PEKERJAAN_LAIN }}">{{ __('Lainnya…') }}</option>
                </select>
                <input name="pekerjaan_lain_{{ $p }}" value="{{ $kerjaLain }}" placeholder="{{ __('Sebutkan pekerjaannya') }}" maxlength="100"
                       x-show="kerja === @js(\App\Models\Wali::PEKERJAAN_LAIN)" x-cloak
                       :required="kerja === @js(\App\Models\Wali::PEKERJAAN_LAIN)" class="mt-2 {{ $kelasInput }}">
                @error('pekerjaan_lain_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="pendapatan_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Rentang pendapatan per bulan') }}</label>
                <select id="pendapatan_{{ $p }}" name="pendapatan_{{ $p }}" class="{{ $kelasInput }}">
                    <option value="">{{ __('— pilih —') }}</option>
                    @foreach (\App\Models\Wali::PENDAPATAN as $kode => $teks)
                        <option value="{{ $kode }}" @selected(old('pendapatan_'.$p, $wali->{'pendapatan_'.$p}) === $kode)>{{ __($teks) }}</option>
                    @endforeach
                </select>
            </div>
            @unless ($emailLangsung)
                <p class="text-xs text-slate-500">{{ __('Email :peran', ['peran' => mb_strtolower(__($label))]) }}: <b class="break-all text-slate-700">{{ $wali->{'email_'.$p} ?: __('belum diisi') }}</b> —
                    <a href="{{ route('portal.data.email', $p) }}" class="font-semibold text-brand">{{ filled($wali->{'email_'.$p}) ? __('ganti email') : __('isi email') }}</a></p>
            @endunless
        </div>
    </section>
@endforeach
