@extends('layouts.portal')

@section('title', __('Pendaftaran Santri Baru'))

@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $kelasLabel = 'mb-1 block text-sm font-medium text-slate-700';
    $buka = $gelombang !== [] && $jalur !== [] && $jenjang !== [] && $surat;
    $gelombangAwal = old('tahun_ajaran') ? old('tahun_ajaran').'|'.old('gelombang') : (count($gelombang) === 1 ? $gelombang[0]['tahun_ajaran'].'|'.$gelombang[0]['kode'] : '');
@endphp

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#9b7bb0] text-white shadow-sm"><x-portal.ikon nama="psb" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Pendaftaran santri baru') }}</h1>
            <p class="truncate text-sm text-slate-500">AL Wafi Islamic Boarding School</p>
        </div>
    </div>

    @if (! $buka)
        <div class="mt-4 rounded-3xl bg-amber-50 p-5 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
            {!! __('Pendaftaran lewat portal <b>sedang ditutup</b>. Silakan pantau pengumuman pesantren atau hubungi panitia PSB.') !!}
        </div>
        @if (! $pendaftarBaru)
            <a href="{{ route('portal.beranda') }}" class="mt-5 block text-center text-sm text-brand hover:underline">{{ __('Kembali ke beranda') }}</a>
        @endif
    @else
        <p class="mb-3 text-sm text-slate-500">{!! __('Isian bertanda :bintang wajib diisi.', ['bintang' => '<span class="text-red-500">*</span>']) !!}</p>
        <x-peringatan-kk portal class="mb-5" />

        <form method="POST" action="{{ route('portal.daftar') }}" class="space-y-6"
              data-confirm="{{ __('Kirim pendaftaran ini? Pastikan data anak sudah benar.') }}"
              x-data="{
                  jenjang: @js($jenjang),
                  pilihanGelombang: @js($gelombangAwal),
                  kodeJenjang: @js(old('kode_jenjang', '')),
                  sumber: @js(old('sumber_informasi', '')),
                  sumberLain: @js($sumber->where('butuh_keterangan', true)->pluck('kode')->values()),
                  get ta() { return this.pilihanGelombang.split('|')[0] || '' },
                  get gel() { return this.pilihanGelombang.split('|')[1] || '' },
                  get j() { return this.jenjang.find(x => x.kode === this.kodeJenjang) },
                  jalurBoleh(kode) { return ! (this.j && (this.j.jalur_nonaktif[this.ta] || []).includes(kode)) },
              }">
            @csrf
            <input type="hidden" name="tahun_ajaran" :value="ta">
            <input type="hidden" name="gelombang" :value="gel">
            <input type="hidden" name="versi_surat" value="{{ $surat->versi }}">

            {{-- Gelombang --}}
            <section>
                <label class="{{ $kelasLabel }}">{{ __('Gelombang pendaftaran') }} <span class="text-red-500">*</span></label>
                @if (count($gelombang) === 1)
                    <div class="rounded-2xl bg-blue-50 px-4 py-3 text-sm font-semibold text-brand">
                        {{ __('T.A :tahun', ['tahun' => $gelombang[0]['tahun_ajaran']]) }} &middot; {{ $gelombang[0]['nama'] }}
                        @if ($gelombang[0]['sampai'])<span class="block text-xs font-normal text-slate-600">{{ __('Dibuka sampai :tanggal', ['tanggal' => $gelombang[0]['sampai']]) }}</span>@endif
                    </div>
                @else
                    <select x-model="pilihanGelombang" required class="{{ $kelasInput }}">
                        <option value="">{{ __('— pilih —') }}</option>
                        @foreach ($gelombang as $g)
                            <option value="{{ $g['tahun_ajaran'] }}|{{ $g['kode'] }}">{{ __('T.A :tahun', ['tahun' => $g['tahun_ajaran']]) }} · {{ $g['nama'] }}{{ $g['sampai'] ? ' ('.__('s.d. :tanggal', ['tanggal' => $g['sampai']]).')' : '' }}</option>
                        @endforeach
                    </select>
                @endif
                @error('gelombang')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>

            @if ($pendaftarBaru)
                {{-- Data keluarga selengkap modul Wali/Keluarga (13 Okt 2026). Wajib: nama ayah & ibu,
                     plus nama & HP peran pendaftar — sama dengan aturan di PortalWaliController. --}}
                <section class="space-y-4" x-data="{ peran: @js(old('peran', '')) }">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Data keluarga') }}</h2>
                    <div>
                        <label class="{{ $kelasLabel }}">{{ __('Anda sebagai') }} <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (\App\Models\Wali::PERAN as $kode => $label)
                                <label class="flex cursor-pointer items-center justify-center gap-1.5 rounded-2xl border border-slate-300 bg-white py-2.5 text-sm has-[:checked]:border-brand has-[:checked]:bg-blue-50 has-[:checked]:text-brand">
                                    <input type="radio" name="peran" value="{{ $kode }}" x-model="peran" required @checked(old('peran') === $kode) class="sr-only">{{ __($label) }}
                                </label>
                            @endforeach
                        </div>
                        @error('peran')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    @foreach (\App\Models\Wali::PERAN as $p => $label)
                        @php
                            $pilihanKerja = old('pekerjaan_'.$p, '');
                            $bukaAwal = $p !== 'wali' || filled(old('nama_wali')) || old('peran') === 'wali';
                        @endphp
                        <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
                             x-data="{ buka: @js($bukaAwal), kerja: @js($pilihanKerja) }">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-bold text-slate-900">
                                    {{ __('Data '.$label) }}
                                    <span x-show="peran === @js($p)" x-cloak class="ms-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-brand">{{ __('Anda') }}</span>
                                </h3>
                                @if ($p === 'wali')
                                    <button type="button" x-show="peran !== 'wali'" @click="buka = ! buka" class="text-xs font-semibold text-brand"
                                            x-text="buka ? @js(__('Tutup')) : @js(__('Isi bila bukan orang tua'))"></button>
                                @endif
                            </div>

                            <div x-show="buka || peran === @js($p)" @if (! $bukaAwal) x-cloak @endif class="mt-3 space-y-3">
                                <div>
                                    <label for="nama_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Nama lengkap') }}
                                        <span @if ($p === 'wali') x-show="peran === 'wali'" x-cloak @endif class="text-red-500">*</span></label>
                                    <input id="nama_{{ $p }}" name="nama_{{ $p }}" value="{{ old('nama_'.$p) }}" class="{{ $kelasInput }}"
                                           @if ($p === 'wali') :required="peran === 'wali'" @else required @endif>
                                    @error('nama_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="telepon_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Nomor HP / WhatsApp') }}
                                        <span x-show="peran === @js($p)" x-cloak class="text-red-500">*</span></label>
                                    <input id="telepon_{{ $p }}" name="telepon_{{ $p }}" inputmode="tel" value="{{ old('telepon_'.$p) }}"
                                           placeholder="0812 3456 7890" :required="peran === @js($p)" class="{{ $kelasInput }}">
                                    @error('telepon_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="email_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Email') }}</label>
                                    <div x-show="peran === @js($p)" x-cloak class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                        {{ $emailPendaftar }}
                                        <span class="block text-xs text-slate-500">{{ __('Email masuk Anda — tak bisa diubah di sini.') }}</span>
                                    </div>
                                    <div x-show="peran !== @js($p)">
                                        <input id="email_{{ $p }}" name="email_{{ $p }}" type="email" value="{{ old('email_'.$p) }}" class="{{ $kelasInput }}">
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Bila diisi, '.strtolower($label).' bisa masuk portal dengan email ini.') }}</p>
                                    </div>
                                    @error('email_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="pekerjaan_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Pekerjaan') }}</label>
                                    <select id="pekerjaan_{{ $p }}" name="pekerjaan_{{ $p }}" x-model="kerja" class="{{ $kelasInput }}">
                                        <option value="">{{ __('— pilih —') }}</option>
                                        @foreach (\App\Models\Wali::PEKERJAAN as $kerja)
                                            <option value="{{ $kerja }}">{{ __($kerja) }}</option>
                                        @endforeach
                                        <option value="{{ \App\Models\Wali::PEKERJAAN_LAIN }}">{{ __('Lainnya…') }}</option>
                                    </select>
                                    <input name="pekerjaan_lain_{{ $p }}" value="{{ old('pekerjaan_lain_'.$p) }}" placeholder="{{ __('Sebutkan pekerjaannya') }}"
                                           x-show="kerja === @js(\App\Models\Wali::PEKERJAAN_LAIN)" x-cloak
                                           :required="kerja === @js(\App\Models\Wali::PEKERJAAN_LAIN)" class="mt-2 {{ $kelasInput }}">
                                    @error('pekerjaan_lain_'.$p)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="pendapatan_{{ $p }}" class="{{ $kelasLabel }}">{{ __('Rentang pendapatan per bulan') }}</label>
                                    <select id="pendapatan_{{ $p }}" name="pendapatan_{{ $p }}" class="{{ $kelasInput }}">
                                        <option value="">{{ __('— pilih —') }}</option>
                                        @foreach (\App\Models\Wali::PENDAPATAN as $kode => $teks)
                                            <option value="{{ $kode }}" @selected(old('pendapatan_'.$p) === $kode)>{{ __($teks) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div>
                        <label for="nik" class="{{ $kelasLabel }}">{{ __('NIK') }}</label>
                        <input id="nik" name="nik" inputmode="numeric" data-bukan-rupiah value="{{ old('nik') }}" class="{{ $kelasInput }}">
                    </div>
                    <div>
                        <label for="alamat" class="{{ $kelasLabel }}">{{ __('Alamat keluarga') }}</label>
                        <textarea id="alamat" name="alamat" rows="2" class="{{ $kelasInput }}">{{ old('alamat') }}</textarea>
                    </div>
                </section>
            @endif

            <section class="space-y-4">
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Data anak') }}</h2>
                <div>
                    <label for="nama" class="{{ $kelasLabel }}">{{ __('Nama lengkap anak') }} <span class="text-red-500">*</span></label>
                    <input id="nama" name="nama" required value="{{ old('nama') }}" autocapitalize="characters" class="{{ $kelasInput }} uppercase">
                    @error('nama')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $kelasLabel }}">{{ __('Jenis kelamin') }} <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['L' => __('Laki-laki'), 'P' => __('Perempuan')] as $kode => $label)
                            <label class="flex cursor-pointer items-center justify-center rounded-2xl border border-slate-300 bg-white py-2.5 text-sm has-[:checked]:border-brand has-[:checked]:bg-blue-50 has-[:checked]:text-brand">
                                <input type="radio" name="jenis_kelamin" value="{{ $kode }}" required @checked(old('jenis_kelamin') === $kode) class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('jenis_kelamin')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="tempat_lahir" class="{{ $kelasLabel }}">{{ __('Tempat lahir') }}</label>
                        <input id="tempat_lahir" name="tempat_lahir" value="{{ old('tempat_lahir') }}" class="{{ $kelasInput }}">
                    </div>
                    <div>
                        <label for="tanggal_lahir" class="{{ $kelasLabel }}">{{ __('Tanggal lahir') }}</label>
                        <input id="tanggal_lahir" name="tanggal_lahir" type="date" value="{{ old('tanggal_lahir') }}" class="{{ $kelasInput }}">
                        @error('tanggal_lahir')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label for="asal_sekolah" class="{{ $kelasLabel }}">{{ __('Asal sekolah') }}</label>
                    <input id="asal_sekolah" name="asal_sekolah" value="{{ old('asal_sekolah') }}" class="{{ $kelasInput }}">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="kode_jenjang" class="{{ $kelasLabel }}">{{ __('Jenjang') }} <span class="text-red-500">*</span></label>
                        <select id="kode_jenjang" name="kode_jenjang" x-model="kodeJenjang" required class="{{ $kelasInput }}">
                            <option value="">{{ __('— pilih —') }}</option>
                            @foreach ($jenjang as $j)
                                <option value="{{ $j['kode'] }}">{{ $j['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="tingkat" class="{{ $kelasLabel }}">{{ __('Masuk kelas') }} <span class="text-red-500">*</span></label>
                        <select id="tingkat" name="tingkat" required class="{{ $kelasInput }}" :disabled="! j">
                            <option value="">{{ __('— pilih —') }}</option>
                            <template x-for="t in (j ? j.tingkat : [])" :key="t">
                                <option :value="t" x-text="t" :selected="t == @js(old('tingkat'))"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="jalur" class="{{ $kelasLabel }}">{{ __('Jalur pendaftaran') }} <span class="text-red-500">*</span></label>
                    <select id="jalur" name="jalur" required class="{{ $kelasInput }}">
                        <option value="">{{ __('— pilih —') }}</option>
                        @foreach ($jalur as $kode => $nama)
                            <option value="{{ $kode }}" x-show="jalurBoleh(@js($kode))" @selected(old('jalur') === $kode)>{{ $nama }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($sumber->isNotEmpty())
                    <div>
                        <label for="sumber_informasi" class="{{ $kelasLabel }}">{{ __('Dari mana Anda mengetahui Al Wafi?') }}</label>
                        <select id="sumber_informasi" name="sumber_informasi" x-model="sumber" class="{{ $kelasInput }}">
                            <option value="">{{ __('— pilih —') }}</option>
                            @foreach ($sumber as $s)
                                <option value="{{ $s->kode }}">{{ $s->nama }}</option>
                            @endforeach
                        </select>
                        <input name="sumber_informasi_lain" x-show="sumberLain.includes(sumber)" x-cloak value="{{ old('sumber_informasi_lain') }}"
                               placeholder="{{ __('Sebutkan') }}" class="mt-2 {{ $kelasInput }}">
                    </div>
                @endif
            </section>

            <section x-data="{ baca: false }">
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span>{{ __('Surat pernyataan') }}</h2>
                <div class="mt-3 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                    <button type="button" @click="baca = ! baca" class="flex w-full items-center justify-between px-4 py-3 text-start text-sm">
                        <span class="font-semibold text-slate-900">{{ $surat->judul }}</span>
                        <span class="text-xs font-semibold text-brand" x-text="baca ? @js(__('Tutup')) : @js(__('Baca'))"></span>
                    </button>
                    <div x-show="baca" x-cloak class="max-h-80 overflow-y-auto whitespace-pre-line border-t border-slate-100 px-4 py-3 text-sm leading-relaxed text-slate-700">{{ $surat->isi }}</div>
                </div>
                <label class="mt-3 flex items-start gap-3 text-sm text-slate-700">
                    <input type="checkbox" name="setuju" value="1" required @checked(old('setuju')) class="mt-0.5 rounded border-slate-300 text-brand focus:ring-brand">
                    <span>{{ __('Saya telah membaca dan menyetujui surat pernyataan di atas.') }}</span>
                </label>
                @error('setuju')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>

            <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Kirim pendaftaran') }}</button>
        </form>
    @endif
@endsection
