{{-- Pilihan penjemput (dijemput wali / diwakilkan) — dipakai Perizinan Insidental & Rutin. Butuh $keluarga, $wakilWajib, $kelasInput. --}}
@php
    // Anggota keluarga yang datanya ada — pilihan "dijemput wali".
    $anggota = collect(\App\Models\Wali::PERAN)->filter(fn ($l, $k) => trim((string) $keluarga->{"nama_{$k}"}) !== '');
    $wj = fn ($k) => in_array($k, $wakilWajib, true);
    $bintang = fn ($k) => $wj($k) ? ' *' : ' ('.__('tidak wajib').')';
@endphp
<section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
         x-data="{ jemput: @js(old('penjemput_jenis', '')) }">
    <h2 class="text-sm font-bold text-slate-800">{{ __('Siapa yang menjemput?') }} <span class="text-red-500">*</span></h2>
    <div class="grid grid-cols-2 gap-2">
        @foreach (['wali' => __('Dijemput wali'), 'wakil' => __('Diwakilkan')] as $k => $l)
            <label class="flex cursor-pointer items-center gap-2 rounded-2xl border border-slate-300 px-4 py-3 text-sm has-[:checked]:border-brand has-[:checked]:bg-blue-50">
                <input type="radio" name="penjemput_jenis" value="{{ $k }}" x-model="jemput" required class="text-brand focus:ring-brand"> {{ $l }}</label>
        @endforeach
    </div>
    @error('penjemput_jenis')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

    <div x-show="jemput === 'wali'" x-cloak class="space-y-2">
        @foreach ($anggota as $k => $l)
            <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 px-4 py-2.5 text-sm has-[:checked]:border-brand has-[:checked]:bg-blue-50">
                <input type="radio" name="penjemput_peran" value="{{ $k }}" @checked(old('penjemput_peran', $anggota->keys()->first()) === $k) class="text-brand focus:ring-brand">
                <span><span class="font-semibold text-slate-900">{{ $keluarga->{"nama_{$k}"} }}</span>
                    <span class="block text-xs text-slate-500">{{ __($l) }}{{ $keluarga->{"telepon_{$k}"} ? ' · '.$keluarga->{"telepon_{$k}"} : '' }}</span></span>
            </label>
        @endforeach
    </div>

    <div x-show="jemput === 'wakil'" x-cloak class="space-y-3">
        <p class="text-xs text-amber-700">{{ __('Penjemput yang diwakilkan akan dicocokkan identitasnya oleh petugas pos sebelum santri dilepas.') }}</p>
        <input name="penjemput_nama" value="{{ old('penjemput_nama') }}" maxlength="255" placeholder="{{ __('Nama penjemput') }}{{ $bintang('nama') }}" :required="jemput === 'wakil' && @js($wj('nama'))" class="{{ $kelasInput }}">
        <div class="grid grid-cols-2 gap-3">
            <input name="penjemput_hubungan" value="{{ old('penjemput_hubungan') }}" maxlength="100" placeholder="{{ __('Hubungan, mis. paman') }}{{ $bintang('hubungan') }}" :required="jemput === 'wakil' && @js($wj('hubungan'))" class="{{ $kelasInput }}">
            <input name="penjemput_hp" value="{{ old('penjemput_hp') }}" inputmode="tel" placeholder="{{ __('No. HP') }}{{ $bintang('hp') }}" :required="jemput === 'wakil' && @js($wj('hp'))" class="{{ $kelasInput }}">
        </div>
        <input name="penjemput_identitas" value="{{ old('penjemput_identitas') }}" maxlength="50" inputmode="numeric" data-bukan-rupiah placeholder="{{ __('NIK / no. identitas') }}{{ $bintang('identitas') }}" :required="jemput === 'wakil' && @js($wj('identitas'))" class="{{ $kelasInput }}">
        <label class="block text-sm text-slate-700">{{ __('Foto KTP / identitas') }}{{ $bintang('foto_identitas') }} <span class="text-xs text-slate-400">({{ \App\Support\Unggahan::maksLabel() }})</span>
            <input type="file" name="penjemput_foto" accept=".pdf,.jpg,.jpeg,.png,.webp" :required="jemput === 'wakil' && @js($wj('foto_identitas'))" class="mt-1 block w-full text-sm"></label>
        <input name="penjemput_keterangan" value="{{ old('penjemput_keterangan') }}" maxlength="500" placeholder="{{ __('Keterangan') }}{{ $bintang('keterangan') }}" :required="jemput === 'wakil' && @js($wj('keterangan'))" class="{{ $kelasInput }}">
    </div>
    @error('penjemput_hp')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
</section>
