@extends('layouts.portal')

@section('title', 'Dompet')

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand to-slate-900 p-5 text-white shadow-lg">
        <div class="absolute -right-10 -top-10 h-36 w-36 rounded-full bg-white/5"></div>
        <p class="relative text-xs font-semibold uppercase tracking-widest text-blue-100/80">Saldo Dompet Wali</p>
        <p class="relative mt-1 text-3xl font-bold tabular-nums">{{ $rp($saldoWali) }}</p>
        <p class="relative mt-1 text-sm text-white/75">Untuk membayar tagihan santri aktif</p>
        <a href="{{ route('portal.dompet.isi') }}"
           class="relative mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-accent py-3 text-sm font-bold text-slate-900 shadow-sm active:opacity-90">
            <span class="text-lg leading-none">+</span> Isi saldo
        </a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3">
        <a href="{{ route('portal.dompet.pindah') }}" class="flex items-center gap-2 rounded-3xl bg-white p-4 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-slate-200">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-brand"><x-portal.ikon nama="dompet" kelas="h-5 w-5" /></span> Pindah saldo
        </a>
        <a href="{{ route('portal.dompet.tabungan') }}" class="flex items-center gap-2 rounded-3xl bg-white p-4 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-slate-200">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><x-portal.ikon nama="riwayat" kelas="h-5 w-5" /></span> Tarik tabungan
        </a>
    </div>

    {{-- Auto-debet: izin keluarga, bukan per orang. MENGAKTIFKAN = izin memotong
         untuk seterusnya → lewat PIN. Mematikan cukup konfirmasi biasa. --}}
    <form method="POST" action="{{ route('portal.dompet.auto_debet') }}" class="mt-4 flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
          @if ($autoDebet)
              data-confirm="Matikan auto-debet? Tagihan harus dibayar manual dari halaman Tagihan."
          @else
              data-no-confirm x-data="pinPad(@js(\App\Services\PortalWali\SesiWali::punyaPin()), @js(route('portal.pin')))" @submit.prevent="buka()"
          @endif>
        @csrf
        <input type="hidden" name="aktif" value="{{ $autoDebet ? '0' : '1' }}">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="otomatis" kelas="h-5 w-5" /></span>
        <div class="min-w-0 flex-1 text-sm">
            <p class="font-semibold text-slate-900">Auto-debet tagihan</p>
            <p class="text-xs text-slate-500">
                @if ($autoDebet)
                    Aktif &mdash; tagihan terlama dibayar otomatis saat saldo cukup.
                @else
                    Mati &mdash; tagihan dibayar manual.
                @endif
            </p>
        </div>
        {{-- Saklar 56×32 px. Kenop & jalur memakai kelas khusus (app.css) supaya tak ikut penimpaan
             warna mode gelap. `tanpa-sentuh`: aturan layar sentuh (min-height 44px untuk SEMUA tombol)
             memanjangkan jalurnya & kenopnya jadi menempel di atas; sasaran sentuhnya diganti
             lapisan tak terlihat `before:-inset-2` (72×48 px). --}}
        <button type="submit" @class(['tanpa-sentuh relative h-8 w-14 shrink-0 rounded-full transition-colors before:absolute before:-inset-2', 'bg-emerald-500' => $autoDebet, 'bg-slate-300 saklar-mati' => ! $autoDebet])
                role="switch" aria-checked="{{ $autoDebet ? 'true' : 'false' }}" aria-label="Auto-debet tagihan">
            <span @class(['saklar-kenop absolute top-1 h-6 w-6 rounded-full shadow-md transition-all', 'left-7' => $autoDebet, 'left-1' => ! $autoDebet])></span>
        </button>
        @unless ($autoDebet)
            @include('portal._pin', ['judul' => 'Aktifkan auto-debet tagihan'])
        @endunless
    </form>
    @if ($autoDebet)
        <p class="mt-2 px-2 text-xs text-slate-500">SPP & tagihan lain-lain hanya dipotong bila saldo cukup untuk melunasinya sekaligus.</p>
    @endif

    @if ($anak->isNotEmpty())
        <section class="mt-5">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Per anak</h2>
            <div class="space-y-2">
                @foreach ($anak as $a)
                    <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <p class="mb-1 font-semibold text-slate-900">{{ $a->nama }}</p>
                        <div class="flex justify-between text-sm text-slate-600"><span>Dompet santri (uang saku)</span><span class="tabular-nums">{{ $rp($a->dompet?->saldo ?? 0) }}</span></div>
                        <div class="flex justify-between text-sm text-slate-600"><span>Tabungan</span><span class="tabular-nums">{{ $rp($a->tabungan?->saldo ?? 0) }}</span></div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-5">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Mutasi Dompet Wali</h2>
        <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($mutasi as $m)
                <div class="flex items-start justify-between gap-2 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-900">{{ $m['label'] }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $m['tanggal']->format('d/m/Y') }}{{ $m['keterangan'] ? ' · '.$m['keterangan'] : '' }}</p>
                        @if ($m['menunggu'])
                            <p class="text-xs font-medium text-amber-700">Menunggu verifikasi &mdash; saldo bertambah setelah dicek keuangan</p>
                        @endif
                    </div>
                    <p @class(['shrink-0 text-sm font-bold tabular-nums', 'text-red-700' => $m['keluar'], 'text-emerald-700' => ! $m['keluar'] && ! $m['menunggu'], 'text-slate-400' => $m['menunggu']])>
                        {{ $m['keluar'] ? '−' : '+' }}{{ number_format(abs((float) $m['nominal']), 0, ',', '.') }}
                    </p>
                </div>
            @empty
                <p class="p-6 text-center text-sm text-slate-500">Belum ada mutasi.</p>
            @endforelse
        </div>
    </section>
@endsection
