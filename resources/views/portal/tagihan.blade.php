@extends('layouts.portal')

@section('title', 'Tagihan')
@section('kembali', $anak->count() > 1 ? route('portal.tagihan') : route('portal.beranda'))

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $periode = function (?string $p) {
        if (! $p || ! preg_match('/^\d{4}-\d{2}$/', $p)) {
            return null;
        }

        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $p.'-01')->locale('id')->translatedFormat('F Y');
    };
    $warna = ['belum' => 'bg-red-50 text-red-700', 'sebagian' => 'bg-blue-50 text-brand', 'lunas' => 'bg-emerald-50 text-emerald-700'];
    $label = ['belum' => 'Belum', 'sebagian' => 'Sebagian', 'lunas' => 'Lunas'];
@endphp

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#b8956a] text-white shadow-sm"><x-portal.ikon nama="tagihan" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">Tagihan</h1>
            <p class="truncate text-sm text-slate-500">{{ $dipilih?->nama ?? 'Belum ada anak' }}</p>
        </div>
    </div>

    @if ($anak->isEmpty())
        <div class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">Belum ada anak yang terdaftar.</div>
    @else
        @if ($anak->count() > 1)
            {{-- Rincian SATU anak; ringkasan seluruh keluarga ada satu langkah sebelumnya. --}}
            <a href="{{ route('portal.tagihan') }}" class="mb-4 flex items-center gap-1 text-sm font-semibold text-brand">
                <x-portal.ikon nama="kembali" kelas="h-4 w-4" /> Semua anak
            </a>
        @endif

        <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Sisa tagihan {{ $dipilih->nama }}</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">{{ $rp($ringkasan['sisa']) }}</p>
            <p class="mt-1 text-sm text-slate-500">
                dari {{ $rp($ringkasan['nominal']) }}
                @if ((float) $ringkasan['menunggu'] > 0)
                    &middot; <span class="font-medium text-amber-700">{{ $rp($ringkasan['menunggu']) }} menunggu verifikasi</span>
                @endif
            </p>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
            <a href="{{ route('portal.tagihan', ['anak' => $dipilih->id]) }}" @class(['rounded-xl py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === 'tagihan', 'text-slate-600' => $tab !== 'tagihan'])>Tagihan</a>
            <a href="{{ route('portal.tagihan', ['anak' => $dipilih->id, 'tab' => 'riwayat']) }}" @class(['rounded-xl py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === 'riwayat', 'text-slate-600' => $tab !== 'riwayat'])>Riwayat bayar</a>
        </div>

        @if ($tab === 'tagihan')
            {{-- Hanya yang belum lunas (keputusan user 13 Okt 2026): yang lunas sudah tercatat di
                 Riwayat bayar — menampilkannya di sini juga membuat kedua tab berisi hal yang sama. --}}
            @php
                $belumLunas = collect($tagihan)->reject(fn ($t) => $t['status'] === 'lunas');
                // Centang untuk bayar sekaligus — hanya bila ada ≥ 2 tagihan yang bisa dibayar.
                $bisaDipilih = $belumLunas->where('bisa_bayar', true)->count() > 1;
            @endphp
            <div class="mt-4 space-y-2" x-data="{ pilih: [] }">
                @if ($bisaDipilih)
                    <p class="px-1 text-xs text-slate-500">Centang beberapa tagihan untuk dibayar sekaligus.</p>
                @endif
                @forelse ($belumLunas as $t)
                    @php $p = $periode($t['periode']); @endphp
                    <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" :class="pilih.includes({{ $t['id'] }}) && 'ring-2 ring-brand'">
                        <div class="flex items-start justify-between gap-2">
                            @if ($bisaDipilih && $t['bisa_bayar'])
                                <input type="checkbox" value="{{ $t['id'] }}" x-model.number="pilih" aria-label="Pilih {{ $t['nama'] }}"
                                       class="mt-1 h-5 w-5 shrink-0 rounded border-slate-300 text-brand focus:ring-brand">
                            @endif
                            <p class="min-w-0 flex-1 font-semibold text-slate-900">
                                {{ $t['nama'] }}
                                @if ($p && ! str_contains(mb_strtolower($t['nama']), mb_strtolower($p)))
                                    <span class="font-normal text-slate-500">&middot; {{ $p }}</span>
                                @endif
                            </p>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$t['status']] }}">{{ $label[$t['status']] }}</span>
                        </div>
                        <div class="mt-1 flex items-end justify-between gap-2">
                            <div class="text-sm text-slate-500">
                                @if ($t['jatuh_tempo'] && $t['status'] !== 'lunas')
                                    <span class="block">Jatuh tempo {{ $t['jatuh_tempo']->format('d/m/Y') }}</span>
                                @endif
                                @if ($t['status'] === 'sebagian')
                                    <span class="block">Terbayar {{ $rp($t['terbayar']) }} dari {{ $rp($t['nominal']) }}</span>
                                @elseif ($t['status'] === 'lunas')
                                    <span class="block">{{ $rp($t['nominal']) }}</span>
                                @endif
                                @if ((float) $t['menunggu'] > 0)
                                    <span class="block font-medium text-amber-700">{{ $rp($t['menunggu']) }} menunggu verifikasi</span>
                                @endif
                            </div>
                            @if ($t['status'] !== 'lunas')
                                <p class="shrink-0 text-lg font-bold tabular-nums text-slate-900">{{ $rp($t['sisa']) }}</p>
                            @endif
                        </div>
                        @if ($t['bisa_bayar'])
                            <a href="{{ route('portal.bayar', $t['id']) }}" class="mt-3 block rounded-2xl bg-brand py-2.5 text-center text-sm font-semibold text-white">
                                {{ match ($t['alur']) { 'dompet' => 'Bayar dari Dompet', 'keduanya' => 'Bayar (dompet / transfer)', default => 'Bayar lewat transfer' } }}
                            </a>
                        @endif
                    </div>
                @empty
                    <div class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
                        @if (collect($tagihan)->isEmpty())
                            Belum ada tagihan.
                        @else
                            <span class="block font-semibold text-emerald-700">Alhamdulillah, semua tagihan sudah lunas.</span>
                            <a href="{{ route('portal.tagihan', ['anak' => $dipilih->id, 'tab' => 'riwayat']) }}" class="mt-2 inline-block font-semibold text-brand">Lihat riwayat bayar &rarr;</a>
                        @endif
                    </div>
                @endforelse

                {{-- Bilah bayar sekaligus — melayang di atas menu bawah selama ada yang dicentang. --}}
                @if ($bisaDipilih)
                    <div x-show="pilih.length > 0" x-cloak class="fixed inset-x-0 bottom-16 z-40 px-4" style="margin-bottom: env(safe-area-inset-bottom)">
                        <a :href="@js(route('portal.bayar_banyak.form')) + '?' + pilih.map(id => 't[]=' + id).join('&')"
                           class="mx-auto flex max-w-lg items-center justify-between gap-3 rounded-2xl bg-brand px-4 py-3 text-white shadow-xl">
                            <span class="text-sm"><b x-text="pilih.length"></b> tagihan dipilih</span>
                            <span class="text-sm font-bold">Bayar sekaligus &rarr;</span>
                        </a>
                    </div>
                @endif
            </div>
        @else
            <div class="mt-4 divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @forelse ($riwayat as $r)
                    <div class="flex items-start justify-between gap-2 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $r['tagihan'] }}</p>
                            <p class="text-xs text-slate-500">{{ $r['tanggal']->format('d/m/Y') }} &middot; {{ $r['sumber'] }}</p>
                            @if ($r['status'] === 'menunggu_verifikasi')
                                <p class="text-xs font-medium text-amber-700">Menunggu verifikasi</p>
                            @endif
                        </div>
                        <p class="shrink-0 text-sm font-bold tabular-nums text-slate-900">{{ $rp($r['nominal']) }}</p>
                    </div>
                @empty
                    <p class="p-6 text-center text-sm text-slate-500">Belum ada pembayaran.</p>
                @endforelse
            </div>
        @endif
    @endif
@endsection
