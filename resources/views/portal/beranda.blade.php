@extends('layouts.portal')

@section('title', __('Beranda'))

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">{{ __("Assalamu'alaikum") }}</p>
        <h1 class="truncate text-2xl font-bold text-slate-900">{{ $nama }}</h1>
        <p class="text-sm text-slate-500">{{ now()->locale(app()->getLocale())->translatedFormat('l, j F Y') }}</p>
    </div>

    {{-- Kartu sorotan: status tagihan & saldo dompet BERDAMPINGAN. Bila hanya satu
         yang ada (keluarga calon belum berdompet), kartunya selebar layar. --}}
    @php $berdua = $adaTagihan && $saldoDompet !== null; @endphp
    @if ($adaTagihan || $saldoDompet !== null)
        <div @class(['mt-4 grid gap-3', 'grid-cols-2' => $berdua])>
            @if ($adaTagihan)
                @if ($sisaTagihan > 0)
                    <a href="{{ route('portal.tagihan') }}" class="relative flex flex-col overflow-hidden rounded-3xl bg-gradient-to-br from-rose-600 to-red-800 p-4 text-white shadow-lg">
                        <div class="absolute -end-8 -top-8 h-24 w-24 rounded-full bg-white/10"></div>
                        <p class="relative text-[11px] font-semibold uppercase tracking-widest text-white/80">{{ __('Sisa tagihan') }}</p>
                        <p @class(['relative mt-1 whitespace-nowrap font-bold tabular-nums', 'text-base sm:text-xl' => $berdua, 'text-3xl' => ! $berdua])>{{ $rp($sisaTagihan) }}</p>
                        <p class="relative text-xs text-white/85">{{ __(':jumlah belum lunas', ['jumlah' => $lembarTagihan]) }}</p>
                        {{-- rose-*, bukan white/red: mode gelap menimpa bg-white & text-red-* di seluruh portal. --}}
                        <span class="flex-1"></span>
                        <span class="relative mt-3 self-start whitespace-nowrap rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700">{{ __('Lihat') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></span>
                    </a>
                @else
                    <div class="relative flex flex-col items-center justify-center overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 to-emerald-900 p-4 text-center text-white shadow-lg">
                        <div class="absolute -end-8 -top-8 h-24 w-24 rounded-full bg-white/10"></div>
                        <span class="relative flex h-10 w-10 items-center justify-center rounded-2xl bg-white/15"><x-portal.ikon nama="absensi" kelas="h-6 w-6" /></span>
                        <p class="relative mt-2 font-bold">{{ __('Alhamdulillah') }}</p>
                        <p class="relative text-xs text-white/85">{{ __('Semua tagihan sudah lunas.') }}</p>
                    </div>
                @endif
            @endif

            @if ($saldoDompet !== null)
                <a href="{{ route('portal.dompet') }}" class="relative flex flex-col overflow-hidden rounded-3xl bg-gradient-to-br from-brand to-slate-900 p-4 text-white shadow-lg">
                    <div class="absolute -end-8 -top-8 h-24 w-24 rounded-full bg-white/5"></div>
                    <p class="relative text-[11px] font-semibold uppercase tracking-widest text-blue-100/80">{{ __('Saldo dompet') }}</p>
                    <p @class(['relative mt-1 whitespace-nowrap font-bold tabular-nums', 'text-base sm:text-xl' => $berdua, 'text-3xl' => ! $berdua])>{{ $rp($saldoDompet) }}</p>
                    <p class="relative text-xs text-white/75">{{ __('Dompet Wali') }}</p>
                    <span class="flex-1"></span>
                    {{-- "Lihat", bukan "Isi saldo": kartunya membuka halaman Dompet; isi saldo punya ikonnya sendiri. --}}
                    <span class="relative mt-3 self-start whitespace-nowrap rounded-full bg-accent px-3 py-1 text-xs font-bold text-slate-900">{{ __('Lihat') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></span>
                </a>
            @endif
        </div>
    @endif

    @include('portal._pasang')

    {{-- Menu utama: ikon kotak berwarna --}}
    <section class="mt-5">
        <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Menu utama') }}</h2>
        <div class="grid grid-cols-4 gap-x-2 gap-y-4">
            @foreach ($menuUtama as $kunci)
                @php [$rute, $label, $ikon, $warna] = \App\Services\PortalWali\MenuPortal::KATALOG[$kunci]; @endphp
                <a href="{{ route($rute) }}" class="flex flex-col items-center gap-1.5 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-sm" style="background: {{ $warna }}"><x-portal.ikon :nama="$ikon" /></span>
                    <span class="text-[11px] leading-tight text-slate-700">{{ __($label) }}</span>
                </a>
            @endforeach
            {{-- Selalu ada: seluruh menu dikelompokkan + atur menu utama. --}}
            <a href="{{ route('portal.menu') }}" class="flex flex-col items-center gap-1.5 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-brand shadow-sm ring-1 ring-slate-200"><x-portal.ikon nama="menu" /></span>
                <span class="text-[11px] leading-tight text-slate-700">{{ __('Menu Lengkap') }}</span>
            </a>
        </div>
    </section>

    {{-- Anak --}}
    <section class="mt-5">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Anak saya') }}</h2>
        <div class="space-y-2">
            @forelse ($anak as $a)
                <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    {{-- Kepala kartu = tautan ke detail anak. Bukan seluruh kartu: kartu
                         memuat tautan "Bayar", dan tautan tak boleh bersarang. --}}
                    <a href="{{ route('portal.anak', $a['id']) }}" class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand text-white"><x-portal.ikon nama="anak" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate font-semibold text-slate-900">{{ $a['nama'] }}</p>
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    'bg-emerald-50 text-emerald-700' => $a['status'] === 'aktif',
                                    'bg-blue-50 text-brand' => $a['psb'],
                                    'bg-slate-100 text-slate-600' => $a['final'],
                                ])>{{ $a['label'] }}</span>
                            </div>
                            <p class="text-sm text-slate-500">{{ $a['jenjang'] }}{{ $a['kelas'] ? ' · '.__('kelas :kelas', ['kelas' => $a['kelas']]) : ($a['tingkat'] ? ' · '.__('kelas :kelas', ['kelas' => $a['tingkat']]) : '') }} · {{ __('T.A :tahun', ['tahun' => $a['tahun_ajaran']]) }}</p>
                        </div>
                        <x-portal.ikon nama="kanan" kelas="mt-3 h-4 w-4 shrink-0 text-slate-400" />
                    </a>

                    @if ($a['psb'])
                        <div class="mt-3 flex gap-1" aria-label="{{ __('Tahap :ke dari :jumlah', ['ke' => $a['tahap'] + 1, 'jumlah' => $a['jumlah_tahap']]) }}">
                            @for ($i = 0; $i < $a['jumlah_tahap']; $i++)
                                <div @class(['h-1.5 flex-1 rounded-full', 'bg-brand' => $i <= $a['tahap'], 'bg-slate-200' => $i > $a['tahap']])></div>
                            @endfor
                        </div>
                        @include('portal._status-diterima', ['status' => $a['status']])
                        @if ($a['langkah'])
                            <p class="mt-2 text-sm text-slate-600">{{ $a['langkah'] }}</p>
                        @endif
                        @if ($a['registrasi'])
                            <a href="{{ route('portal.tagihan') }}" class="mt-3 flex items-center justify-between rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <span>{{ __('Biaya registrasi') }} <b class="rp">{{ $rp($a['registrasi']) }}</b></span>
                                <span class="font-semibold">{{ __('Bayar') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></span>
                            </a>
                        @endif
                        @if ($a['berkas_kurang'] > 0)
                            <a href="{{ route('portal.berkas', $a['id']) }}" class="mt-2 flex items-center justify-between rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <span>{!! __(':jumlah berkas registrasi belum lengkap', ['jumlah' => '<b>'.e($a['berkas_kurang']).'</b>']) !!}</span>
                                <span class="font-semibold">{{ __('Unggah') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></span>
                            </a>
                        @endif
                    @endif
                </div>
            @empty
                <div class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Belum ada anak yang terdaftar.') }}</div>
            @endforelse

            <a href="{{ route('portal.daftar') }}" class="flex items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 p-4 text-sm font-semibold text-brand">
                <x-portal.ikon nama="isi" kelas="h-5 w-5" /> {{ __('Daftarkan anak lain') }}
            </a>
        </div>
    </section>
@endsection
