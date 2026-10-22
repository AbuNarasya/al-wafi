@extends('layouts.portal')

@section('title', __('Data Wali & Santri'))

@use('App\Services\PortalWali\UbahDataWali')
@use('App\Models\Wali')

@php
    $kosong = '<span class="text-slate-400">'.e(__('Belum diisi')).'</span>';
    $tgl = fn ($d) => $d ? $d->locale(app()->getLocale())->translatedFormat('j F Y') : null;
@endphp

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand text-white shadow-sm"><x-portal.ikon nama="data" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Data Wali & Santri') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Periksa datanya. Bila ada yang keliru, betulkan di sini.') }}</p>
        </div>
    </div>

    @if (\App\Services\PortalWali\SesiWali::modeVerifikasi(request()))
        <a href="{{ route('portal.verifikasi.ringkasan') }}" class="mb-4 flex items-center justify-between gap-2 rounded-2xl bg-blue-50 px-4 py-3 text-sm font-semibold text-brand ring-1 ring-blue-100">
            <span>{{ __('Sudah diperiksa? Kembali ke langkah verifikasi') }}</span>
            <x-portal.ikon nama="kanan" kelas="h-4 w-4" />
        </a>
    @endif

    <div class="mb-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
        <a href="{{ route('portal.data', ['tab' => 'santri']) }}" @class(['rounded-xl py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === 'santri', 'text-slate-600' => $tab !== 'santri'])>{{ __('Data Santri') }}</a>
        <a href="{{ route('portal.data', ['tab' => 'wali']) }}" @class(['rounded-xl py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === 'wali', 'text-slate-600' => $tab !== 'wali'])>{{ __('Data Wali') }}</a>
    </div>

    @if ($tab === 'santri')
        @forelse ($anak as $s)
            @php
                $bolehUbah = UbahDataWali::bolehUbahSantri($s);
                $kelompok = [
                    'Identitas' => [
                        'Nama lengkap' => $s->nama,
                        'Jenis kelamin' => isset(UbahDataWali::JENIS_KELAMIN[$s->jenis_kelamin]) ? __(UbahDataWali::JENIS_KELAMIN[$s->jenis_kelamin]) : $s->jenis_kelamin,
                        'Tempat lahir' => $s->tempat_lahir,
                        'Tanggal lahir' => $tgl($s->tanggal_lahir),
                        'NISN' => $s->nisn,
                    ],
                    'Sekolah asal' => [
                        'Nama sekolah' => $s->asal_sekolah,
                        'Alamat sekolah' => $s->alamat_sekolah_asal,
                        'Kepala sekolah' => $s->kepala_sekolah_asal,
                        'Kontak kepala sekolah' => $s->cp_kepala_sekolah_asal,
                        'Wali kelas' => $s->wali_kelas_asal,
                        'Kontak wali kelas' => $s->cp_wali_kelas_asal,
                    ],
                ];
                // Diatur sekolah — ditampilkan supaya wali bisa ikut memeriksa, tapi tak diubah di sini.
                $administrasi = [
                    'No. pendaftaran' => $s->no_pendaftaran,
                    'NIS' => $s->nis,
                    'Jenjang' => trim(($s->jenjang?->nama ?? $s->kode_jenjang).($s->tingkat ? ' · '.__('tingkat :tingkat', ['tingkat' => $s->tingkat]) : '')),
                    'Jalur' => $s->jalurPendaftaran?->nama ?? $s->jalur,
                    'Angkatan (T.A masuk)' => $s->tahun_ajaran,
                    'Status' => \App\Support\BahasaPortal::statusSantri((string) $s->status),
                ];
            @endphp
            <section class="mb-5 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center gap-3 border-b border-slate-100 p-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-brand"><x-portal.ikon nama="anak" kelas="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-bold text-slate-900">{{ $s->nama }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $administrasi['Jenjang'] }} &middot; {{ $administrasi['Status'] }}</p>
                    </div>
                </div>

                @foreach ($kelompok as $judul => $baris)
                    <div class="px-4 pt-3">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __($judul) }}</p>
                        <dl class="divide-y divide-slate-100">
                            @foreach ($baris as $label => $nilai)
                                <div class="flex gap-3 py-2 text-sm">
                                    <dt class="w-36 shrink-0 text-slate-500">{{ __($label) }}</dt>
                                    <dd class="min-w-0 flex-1 break-words font-medium text-slate-900">{!! filled($nilai) ? e($nilai) : $kosong !!}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach

                <div class="mx-4 mt-3 rounded-2xl bg-slate-50 px-3 pb-1 pt-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Diatur sekolah') }}</p>
                    <dl class="divide-y divide-slate-200/70">
                        @foreach ($administrasi as $label => $nilai)
                            <div class="flex gap-3 py-2 text-sm">
                                <dt class="w-36 shrink-0 text-slate-500">{{ __($label) }}</dt>
                                <dd class="min-w-0 flex-1 break-words text-slate-700">{!! filled($nilai) ? e($nilai) : '<span class="text-slate-400">—</span>' !!}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="p-4">
                    @if ($bolehUbah)
                        <a href="{{ route('portal.data.santri', $s->id) }}" class="flex w-full items-center justify-center gap-2 rounded-2xl bg-brand py-3 text-sm font-bold text-white">
                            <x-portal.ikon nama="isi" kelas="h-5 w-5" /> {{ __('Perbaharui Data :nama', ['nama' => \Illuminate\Support\Str::of($s->nama)->before(' ')->title()]) }}
                        </a>
                        <p class="mt-2 text-center text-xs text-slate-500">{{ __('Bagian “Diatur sekolah” hanya bisa dibetulkan oleh bagian kesantrian.') }}</p>
                    @else
                        <p class="rounded-2xl bg-slate-50 px-3 py-2 text-center text-xs text-slate-500">{{ __('Data santri berstatus :status tidak bisa diubah lewat portal. Hubungi bagian kesantrian bila ada yang keliru.', ['status' => $administrasi['Status']]) }}</p>
                    @endif
                </div>
            </section>
        @empty
            <div class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Belum ada anak yang terdaftar di keluarga ini.') }}</div>
        @endforelse
    @else
        @php
            $keluarga = [
                'Kontak utama' => isset(Wali::PERAN[$wali->kontak_utama]) ? __(Wali::PERAN[$wali->kontak_utama]) : $wali->kontak_utama,
                'NIK (kepala keluarga)' => $wali->nik,
                'Alamat' => $wali->alamat,
            ];
        @endphp
        <section class="mb-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Keluarga') }}</p>
            <dl class="divide-y divide-slate-100">
                @foreach ($keluarga as $label => $nilai)
                    <div class="flex gap-3 py-2 text-sm">
                        <dt class="w-36 shrink-0 text-slate-500">{{ __($label) }}</dt>
                        <dd class="min-w-0 flex-1 whitespace-pre-line break-words font-medium text-slate-900">{!! filled($nilai) ? e($nilai) : $kosong !!}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @foreach (Wali::PERAN as $p => $labelPeran)
            @php $ada = filled($wali->{'nama_'.$p}) || filled($wali->{'email_'.$p}) || filled($wali->{'telepon_'.$p}); @endphp
            {{-- Data wali (bukan orang tua) yang kosong sama sekali tak perlu ditampilkan. --}}
            @if ($p !== 'wali' || $ada)
                <section class="mb-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-400">
                        {{ __($labelPeran) }}
                        @if ($p === $peranSaya)<span class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold normal-case tracking-normal text-brand">{{ __('Anda') }}</span>@endif
                    </p>
                    <dl class="divide-y divide-slate-100">
                        @foreach ([
                            'Nama' => $wali->{'nama_'.$p},
                            'No. HP / WhatsApp' => $wali->{'telepon_'.$p},
                            'Pekerjaan' => $wali->{'pekerjaan_'.$p},
                            'Pendapatan / bulan' => isset(Wali::PENDAPATAN[$wali->{'pendapatan_'.$p}]) ? __(Wali::PENDAPATAN[$wali->{'pendapatan_'.$p}]) : $wali->{'pendapatan_'.$p},
                        ] as $label => $nilai)
                            <div class="flex gap-3 py-2 text-sm">
                                <dt class="w-36 shrink-0 text-slate-500">{{ __($label) }}</dt>
                                <dd class="min-w-0 flex-1 break-words font-medium text-slate-900">{!! filled($nilai) ? e($nilai) : $kosong !!}</dd>
                            </div>
                        @endforeach
                        <div class="flex items-start gap-3 py-2 text-sm">
                            <dt class="w-36 shrink-0 text-slate-500">{{ __('Email') }} <span class="block text-[11px]">{{ __('(untuk masuk portal)') }}</span></dt>
                            <dd class="min-w-0 flex-1 break-all font-medium text-slate-900">{!! filled($wali->{'email_'.$p}) ? e($wali->{'email_'.$p}) : $kosong !!}</dd>
                            <a href="{{ route('portal.data.email', $p) }}" class="shrink-0 text-sm font-semibold text-brand">{{ filled($wali->{'email_'.$p}) ? __('Ganti') : __('Isi') }}</a>
                        </div>
                    </dl>
                </section>
            @endif
        @endforeach

        <a href="{{ route('portal.data.keluarga') }}" class="flex w-full items-center justify-center gap-2 rounded-2xl bg-brand py-3 text-sm font-bold text-white">
            <x-portal.ikon nama="isi" kelas="h-5 w-5" /> {{ __('Perbaharui Data Wali') }}
        </a>
        <p class="mt-2 px-2 text-center text-xs text-slate-500">{{ __('Mengganti email dilakukan lewat tombol “Ganti” di atas, dengan kode yang dikirim ke email baru. Kontak utama keluarga diatur bagian kesantrian.') }}</p>
    @endif
@endsection
