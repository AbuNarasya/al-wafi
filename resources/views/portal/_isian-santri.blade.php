{{--
    Isian data satu santri — dipakai menu Data Wali & Santri (data-santri, $banyak = false)
    dan langkah ② verifikasi data (verifikasi-santri, $banyak = true: semua anak dalam satu
    formulir, nama isian "santri[id][kolom]").
--}}
@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
    $banyak ??= false;
    $nm = fn (string $k) => $banyak ? "santri[{$santri->id}][{$k}]" : $k;
    $kunci = fn (string $k) => $banyak ? "santri.{$santri->id}.{$k}" : $k;
    $id = fn (string $k) => $banyak ? "s{$santri->id}_{$k}" : $k;
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
                    <input type="radio" name="{{ $nm('jenis_kelamin') }}" value="{{ $kode }}" required @checked(old($kunci('jenis_kelamin'), $santri->jenis_kelamin) === $kode) class="sr-only">{{ __($teks) }}
                </label>
            @endforeach
        </div>
        @error($kunci('jenis_kelamin'))<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label for="{{ $id('tempat_lahir') }}" class="{{ $kelasLabel }}">{{ __('Tempat lahir') }}</label>
            <input id="{{ $id('tempat_lahir') }}" name="{{ $nm('tempat_lahir') }}" value="{{ old($kunci('tempat_lahir'), $santri->tempat_lahir) }}" maxlength="255" class="{{ $kelasInput }}">
            @error($kunci('tempat_lahir'))<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="{{ $id('tanggal_lahir') }}" class="{{ $kelasLabel }}">{{ __('Tanggal lahir') }}</label>
            <input id="{{ $id('tanggal_lahir') }}" type="date" name="{{ $nm('tanggal_lahir') }}" max="{{ now()->subDay()->format('Y-m-d') }}"
                   value="{{ old($kunci('tanggal_lahir'), $santri->tanggal_lahir?->format('Y-m-d')) }}" class="{{ $kelasInput }}">
            @error($kunci('tanggal_lahir'))<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
    <div>
        <label for="{{ $id('nisn') }}" class="{{ $kelasLabel }}">{{ __('NISN') }}</label>
        <input id="{{ $id('nisn') }}" name="{{ $nm('nisn') }}" inputmode="numeric" data-bukan-rupiah value="{{ old($kunci('nisn'), $santri->nisn) }}" maxlength="20" class="{{ $kelasInput }}">
        <p class="mt-1 text-xs text-slate-500">{{ __('Nomor Induk Siswa Nasional, 10 angka — tertera di rapor / kartu NISN.') }}</p>
        @error($kunci('nisn'))<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</section>

@foreach ($isian as $judul => $kolom)
    <section class="space-y-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __($judul) }}</h2>
        @foreach ($kolom as $nama => [$label, $jenis])
            <div>
                <label for="{{ $id($nama) }}" class="{{ $kelasLabel }}">{{ __($label) }}</label>
                @if ($jenis === 'textarea')
                    <textarea id="{{ $id($nama) }}" name="{{ $nm($nama) }}" rows="2" maxlength="1000" class="{{ $kelasInput }}">{{ old($kunci($nama), $santri->{$nama}) }}</textarea>
                @else
                    <input id="{{ $id($nama) }}" name="{{ $nm($nama) }}" value="{{ old($kunci($nama), $santri->{$nama}) }}" maxlength="255"
                           @if ($jenis === 'tel') inputmode="tel" @endif class="{{ $kelasInput }}">
                @endif
                @error($kunci($nama))<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </section>
@endforeach
