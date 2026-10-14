@extends('layouts.portal')

@section('title', 'Beranda')

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Assalamu'alaikum</p>
        <h1 class="truncate text-2xl font-bold text-slate-900">{{ $nama }}</h1>
        <p class="text-sm text-slate-500">{{ now()->locale('id')->translatedFormat('l, j F Y') }}</p>
    </div>

    {{-- Kartu sorotan: status tagihan & saldo dompet BERDAMPINGAN. Bila hanya satu
         yang ada (keluarga calon belum berdompet), kartunya selebar layar. --}}
    @php $berdua = $adaTagihan && $saldoDompet !== null; @endphp
    @if ($adaTagihan || $saldoDompet !== null)
        <div @class(['mt-4 grid gap-3', 'grid-cols-2' => $berdua])>
            @if ($adaTagihan)
                @if ($sisaTagihan > 0)
                    <a href="{{ route('portal.tagihan') }}" class="relative flex flex-col overflow-hidden rounded-3xl bg-gradient-to-br from-rose-600 to-red-800 p-4 text-white shadow-lg">
                        <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-white/10"></div>
                        <p class="relative text-[11px] font-semibold uppercase tracking-widest text-white/80">Sisa tagihan</p>
                        <p @class(['relative mt-1 whitespace-nowrap font-bold tabular-nums', 'text-base sm:text-xl' => $berdua, 'text-3xl' => ! $berdua])>{{ $rp($sisaTagihan) }}</p>
                        <p class="relative text-xs text-white/85">{{ $lembarTagihan }} belum lunas</p>
                        {{-- rose-*, bukan white/red: mode gelap menimpa bg-white & text-red-* di seluruh portal. --}}
                        <span class="flex-1"></span>
                        <span class="relative mt-3 self-start whitespace-nowrap rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700">Lihat &rarr;</span>
                    </a>
                @else
                    <div class="relative flex flex-col items-center justify-center overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 to-emerald-900 p-4 text-center text-white shadow-lg">
                        <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-white/10"></div>
                        <span class="relative flex h-10 w-10 items-center justify-center rounded-2xl bg-white/15"><x-portal.ikon nama="absensi" kelas="h-6 w-6" /></span>
                        <p class="relative mt-2 font-bold">Alhamdulillah</p>
                        <p class="relative text-xs text-white/85">Semua tagihan sudah lunas.</p>
                    </div>
                @endif
            @endif

            @if ($saldoDompet !== null)
                <a href="{{ route('portal.dompet') }}" class="relative flex flex-col overflow-hidden rounded-3xl bg-gradient-to-br from-brand to-slate-900 p-4 text-white shadow-lg">
                    <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-white/5"></div>
                    <p class="relative text-[11px] font-semibold uppercase tracking-widest text-blue-100/80">Saldo dompet</p>
                    <p @class(['relative mt-1 whitespace-nowrap font-bold tabular-nums', 'text-base sm:text-xl' => $berdua, 'text-3xl' => ! $berdua])>{{ $rp($saldoDompet) }}</p>
                    <p class="relative text-xs text-white/75">Dompet Wali</p>
                    <span class="flex-1"></span>
                    {{-- "Lihat", bukan "Isi saldo": kartunya membuka halaman Dompet; isi saldo punya ikonnya sendiri. --}}
                    <span class="relative mt-3 self-start whitespace-nowrap rounded-full bg-accent px-3 py-1 text-xs font-bold text-slate-900">Lihat &rarr;</span>
                </a>
            @endif
        </div>
    @endif

    @include('portal._pasang')

    {{-- Menu utama: ikon kotak berwarna --}}
    <section class="mt-5">
        <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Menu utama</h2>
        @php
            $menuUtama = array_values(array_filter([
                // Bukan tiruan kartu di atas: kartu = sisa & saldo SAAT INI, ikon = HISTORINYA.
                ['portal.tagihan.riwayat', 'Riwayat tagihan', 'riwayat', '#b8956a'],
                $saldoDompet !== null ? ['portal.dompet.mutasi_anak', 'Mutasi dompet anak', 'dompet', '#164a9e'] : null,
                $saldoDompet !== null ? ['portal.dompet.isi', 'Isi saldo', 'isi', '#4f7a5c'] : null,
                ['portal.daftar', 'Pendaftaran', 'psb', '#9b7bb0'],
                ['portal.kabar', 'Kabar', 'lonceng', '#c98a5e'],
                ['portal.akun', 'Akun', 'profil', '#64748b'],
            ]));
        @endphp
        <div class="grid grid-cols-4 gap-x-2 gap-y-4">
            @foreach ($menuUtama as [$rute, $label, $ikon, $warna])
                <a href="{{ route($rute) }}" class="flex flex-col items-center gap-1.5 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-sm" style="background: {{ $warna }}"><x-portal.ikon :nama="$ikon" /></span>
                    <span class="text-[11px] leading-tight text-slate-700">{{ $label }}</span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Anak --}}
    <section class="mt-5">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Anak saya</h2>
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
                            <p class="text-sm text-slate-500">{{ $a['jenjang'] }}{{ $a['tingkat'] ? ' · kelas '.$a['tingkat'] : '' }} · T.A {{ $a['tahun_ajaran'] }}</p>
                        </div>
                        <x-portal.ikon nama="kanan" kelas="mt-3 h-4 w-4 shrink-0 text-slate-400" />
                    </a>

                    @if ($a['psb'])
                        <div class="mt-3 flex gap-1" aria-label="Tahap {{ $a['tahap'] + 1 }} dari {{ $a['jumlah_tahap'] }}">
                            @for ($i = 0; $i < $a['jumlah_tahap']; $i++)
                                <div @class(['h-1.5 flex-1 rounded-full', 'bg-brand' => $i <= $a['tahap'], 'bg-slate-200' => $i > $a['tahap']])></div>
                            @endfor
                        </div>
                        @if ($a['langkah'])
                            <p class="mt-2 text-sm text-slate-600">{{ $a['langkah'] }}</p>
                        @endif
                        @if ($a['registrasi'])
                            <a href="{{ route('portal.tagihan') }}" class="mt-3 flex items-center justify-between rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <span>Biaya registrasi <b>{{ $rp($a['registrasi']) }}</b></span>
                                <span class="font-semibold">Bayar &rarr;</span>
                            </a>
                        @endif
                        @if ($a['berkas_kurang'] > 0)
                            <a href="{{ route('portal.berkas', $a['id']) }}" class="mt-2 flex items-center justify-between rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <span><b>{{ $a['berkas_kurang'] }}</b> berkas registrasi belum lengkap</span>
                                <span class="font-semibold">Unggah &rarr;</span>
                            </a>
                        @endif
                    @endif
                </div>
            @empty
                <div class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">Belum ada anak yang terdaftar.</div>
            @endforelse

            <a href="{{ route('portal.daftar') }}" class="flex items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 p-4 text-sm font-semibold text-brand">
                <x-portal.ikon nama="isi" kelas="h-5 w-5" /> Daftarkan anak lain
            </a>
        </div>
    </section>
@endsection
